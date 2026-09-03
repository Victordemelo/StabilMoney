<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use App\Models\FixedBill;
use App\Services\FixedBillService;
use App\Support\Brl;
use App\Support\FundingSource;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pagamento de UMA competência (mês) de uma conta fixa.
 *
 * O valor vem preenchido com o previsto mas é editável: conta de luz varia. O
 * que se grava é o valor REAL; o previsto continua na `fixed_bills` servindo de
 * projeção para os meses seguintes.
 *
 * A posse da conta fixa é verificada pela FixedBillPolicy no controller.
 *
 * ⚠️ Auditoria 28/07/2026 (C-2): este era o campo de dinheiro mais frouxo do
 * app. `min:0.01` e nada mais deixava passar `1e12` (notação científica é
 * `numeric` para o PHP) e, como o pagamento de conta fixa entra no
 * FundingService como OBRIGAÇÃO, a trava de gasto era ignorada — o valor
 * gravava direto e a conta ia a −R$ 999.999.999.900,00. Hoje há três barreiras:
 *   1. `decimal:0,2`  → mata notação científica e mais de 2 casas;
 *   2. teto relativo  → no máximo TETO_PREVISTO× o valor previsto da conta fixa;
 *   3. piso de data   → `paid_on` não pode ser de antes do mês anterior à competência.
 *
 * ⚠️ 02/08/2026: a COMPETÊNCIA também é validada aqui (`validarJanelaDaCompetencia`).
 * `ends_on` só era respeitado na projeção do FixedBillService, então um POST direto
 * na rota pagava o mês de uma conta JÁ ENCERRADA — dinheiro saindo do caixa por uma
 * competência que nenhuma tela lista, e a linha nascendo com `fixed_bill_id` +
 * `competence` de um período inválido.
 */
class PayFixedBillRequest extends FormRequest
{
    use NormalizesMoneyInput;

    /**
     * Quantas vezes o previsto o valor pago pode chegar a ser.
     *
     * Por que 3: contas fixas de valor variável (luz no verão, água com
     * vazamento, condomínio com rateio extraordinário) realmente dobram, e não
     * queremos travar o usuário no mês em que ele mais precisa registrar. Já
     * 4×, 10× ou 1e12 não são "o mês veio caro": são erro de digitação (18.000
     * no lugar de 1.800) ou abuso. Quem mudou de patamar de vez edita a conta
     * fixa — agora há botão para isso na tela.
     */
    public const TETO_PREVISTO = 3;

    /**
     * Posse verificada AQUI, antes da validação.
     *
     * Sem isto, um estranho pagando a conta fixa de outra família recebia 302 com erro
     * de validação (o FK da conta de caixa o barrava) em vez de 403. O dinheiro ficava
     * protegido pela ordem dos middlewares, não por decisão explícita — e a mensagem
     * de erro contava mais do que devia. A Policy no controller segue como 2ª linha.
     */
    public function authorize(): bool
    {
        $conta = $this->route('conta');

        if (! $conta instanceof FixedBill) {
            return false;
        }

        return $conta->user_id === $this->user()?->ownerId();
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeMoneyField('amount');
    }

    public function rules(): array
    {
        $ownerId = $this->user()->ownerId();

        return [
            'account_id' => [
                'required',
                // Cartão de débito não paga nada: não tem saldo próprio.
                Rule::exists('accounts', 'id')->where(fn ($q) => $q
                    ->where('user_id', $ownerId)
                    // Métodos ESPELHO (débito e Pix) não têm saldo próprio: os
                    // selects já mandam o id da conta vinculada.
                    ->whereNotIn('type', ['debit_card', 'pix'])),
            ],
            // `decimal:0,2` é o que barra "1e12" — `numeric` o aceita. (Já "800.123"
            // é OITOCENTOS MIL: ponto + 3 dígitos é separador de milhar no pt-BR;
            // a terceira casa decimal se escreve "800,123".) O teto vem do trait:
            // o antigo (9999999999999.99) virava 1e13 no bind e dava erro 500.
            'amount' => $this->regrasDeDinheiro(),
            'paid_on' => [
                'nullable',
                'date',
                // Piso RELATIVO à competência (M-6): pagar a conta de julho/2026
                // com data de 2001 tirava a despesa do fluxo de caixa e do
                // "gasto do mês", enquanto o saldo caía hoje.
                'after_or_equal:'.$this->pisoDaDataDePagamento(),
                'before_or_equal:'.now()->toDateString(),
            ],
            'funding_source' => ['nullable', Rule::in(FundingSource::TODAS)],
            'funding_investment_id' => [
                'nullable',
                'required_if:funding_source,'.FundingSource::RESGATE_INVESTIMENTO,
                Rule::exists('investments', 'id')->where('user_id', $ownerId),
            ],
            // TETO do resgate aprovado no modal (F-3, auditoria de 02/09/2026):
            // o faltante é recalculado sob lock na gravação e, sem o teto, o
            // pagamento resgatava o que fosse preciso — não o que foi aprovado.
            'funding_max_amount' => $this->regrasDeDinheiro(obrigatorio: false),
        ];
    }

    /**
     * Checagens que dependem da conta fixa da rota. Só rodam quando ela é mesmo
     * da família — o `authorize()` acima já barra o estranho com 403, e este
     * guard é a 2ª linha: sem ele, uma mudança futura no authorize faria as
     * mensagens abaixo revelarem o nome e o valor previsto de outra família.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $bill = $this->contaFixa();

                if (! $bill) {
                    return;
                }

                $this->validarTetoDoValor($validator, $bill);
                $this->validarJanelaDaCompetencia($validator, $bill);
            },
        ];
    }

    /** Teto relativo ao previsto — só depois de as regras básicas passarem. */
    private function validarTetoDoValor(Validator $validator, FixedBill $bill): void
    {
        if ($validator->errors()->has('amount')) {
            return;
        }

        $previsto = round((float) $bill->amount, 2);
        $teto = round($previsto * self::TETO_PREVISTO, 2);
        $valor = round((float) $this->input('amount'), 2);

        if ($previsto > 0 && $valor > $teto) {
            $validator->errors()->add('amount',
                'O valor pago ('.Brl::format($valor).') é muito maior que o previsto para '
                .$bill->name.' ('.Brl::format($previsto).'). Confira os centavos — se a conta '
                .'mudou de valor de vez, edite a conta fixa antes de pagar.');
        }
    }

    /**
     * A competência precisa CABER na janela que o FixedBillService projeta —
     * só é pagável o que a tela realmente lista.
     *
     * Por que existe: as competências não são materializadas (não há linha em
     * `transactions` até o pagamento), então a URL é a única coisa que diz QUAL
     * mês está sendo pago. Sem guarda, um POST direto pagava qualquer mês: o
     * dinheiro saía do caixa e a linha nascia com uma `competence` que nenhuma
     * tela mostra — invisível para conferir e impossível de estornar pela UI.
     *
     * As duas bordas abaixo são cópia literal da regra da projeção, de propósito.
     * Se a projeção mudar, isto tem de mudar junto (uma tela que exibe o botão
     * "Pagar" e um POST que recusa é pior que o bug original):
     *
     *  - `ends_on` → `FixedBillService::occurrences` compara o MÊS
     *    (`ends_on->startOfMonth()`) e para nele, INCLUSIVE. A competência do mês
     *    em que a conta se encerra ainda é devida (o aluguel vence dia 10 e o
     *    contrato acaba em 30/06: junho se paga). Só o mês SEGUINTE deixa de
     *    existir. Repare que a comparação é por mês, não pelo vencimento — ao
     *    contrário do `starts_on`, que o controller compara pela data de
     *    vencimento (A-10). A assimetria é da projeção; aqui só a espelhamos.
     *  - piso de `MAX_MESES_ATRAS` → `currentAndOverdue` começa em
     *    `hoje − MAX_MESES_ATRAS` meses, então nada mais antigo aparece em tela.
     *    Quem precisa registrar um pagamento mais velho lança despesa avulsa,
     *    que é o caminho sem `fixed_bill_id`.
     *
     * (`active`, janela FUTURA e `starts_on` continuam no controller, junto do
     * resto da regra de "está na hora de pagar".)
     */
    private function validarJanelaDaCompetencia(Validator $validator, FixedBill $bill): void
    {
        $competencia = $this->competencia();

        if (! $competencia) {
            return;
        }

        if ($bill->ends_on) {
            $fim = CarbonImmutable::parse($bill->ends_on);

            if ($competencia->greaterThan($fim->startOfMonth())) {
                $validator->errors()->add('amount',
                    'A conta fixa '.$bill->name.' foi encerrada em '.$fim->translatedFormat('d/m/Y')
                    .' — não há competência de '.$competencia->translatedFormat('F/Y').' para pagar.');

                return;
            }
        }

        $piso = CarbonImmutable::today()
            ->subMonthsNoOverflow(FixedBillService::MAX_MESES_ATRAS)
            ->startOfMonth();

        if ($competencia->lessThan($piso)) {
            $validator->errors()->add('amount',
                'A competência de '.$competencia->translatedFormat('F/Y').' é antiga demais: as contas fixas '
                .'só aparecem até '.FixedBillService::MAX_MESES_ATRAS.' meses atrás (desde '
                .$piso->translatedFormat('F/Y').'). Se você ainda precisa registrar esse pagamento, '
                .'lance-o como despesa avulsa.');
        }
    }

    /**
     * Data mínima aceita em `paid_on`: o primeiro dia do mês ANTERIOR ao da
     * competência. Um mês de folga cobre quem paga o boleto adiantado (o
     * condomínio de agosto pago em 28/07) sem permitir jogar a despesa para um
     * ano qualquer no passado.
     */
    private function pisoDaDataDePagamento(): string
    {
        $competencia = $this->competencia();

        if (! $competencia) {
            return '2000-01-01';
        }

        return $competencia->subMonthNoOverflow()->startOfMonth()->toDateString();
    }

    /** A competência da URL ("2026-07") como 1º dia do mês, ou null se ilegível. */
    private function competencia(): ?CarbonImmutable
    {
        $bruta = $this->route('competencia');

        if (! is_string($bruta) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bruta)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $bruta.'-01')->startOfMonth();
    }

    /** A conta fixa da rota, só se for da família de quem está pedindo. */
    private function contaFixa(): ?FixedBill
    {
        $bill = $this->route('conta');

        if (! $bill instanceof FixedBill || $bill->user_id !== $this->user()->ownerId()) {
            return null;
        }

        return $bill;
    }

    public function attributes(): array
    {
        return [
            'account_id' => 'método de pagamento',
            'amount' => 'valor pago',
            'paid_on' => 'data do pagamento',
            'funding_max_amount' => 'teto do resgate',
        ];
    }

    public function messages(): array
    {
        return [
            'account_id.required' => 'Escolha de qual conta sai o pagamento.',
            'account_id.exists' => 'O método escolhido não existe ou não pertence a você.',
            'amount.required' => 'Informe o valor pago.',
            'amount.numeric' => 'O valor deve ser um número. Use vírgula para os centavos, ex.: 800,00.',
            'amount.decimal' => 'O valor deve ter no máximo duas casas decimais, ex.: 800,00.',
            'amount.min' => 'O valor mínimo é R$ 0,01.',
            'amount.max' => 'Esse valor é alto demais para uma conta fixa. Confira os centavos.',
            'paid_on.after_or_equal' => 'A data do pagamento é antiga demais para esta competência — use uma data a partir do mês anterior ao vencimento.',
            'paid_on.before_or_equal' => 'A data do pagamento não pode ser no futuro.',
            'funding_investment_id.required_if' => 'Escolha de qual investimento resgatar.',
        ];
    }
}
