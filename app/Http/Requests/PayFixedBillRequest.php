<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use App\Models\FixedBill;
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

    public function authorize(): bool
    {
        return true;
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
                    ->where('type', '!=', 'debit_card')),
            ],
            // `decimal:0,2` é o que barra "1e12" e "800.123" — `numeric` aceita ambos.
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999999.99'],
            'paid_on' => [
                'nullable',
                'date',
                // Piso RELATIVO à competência (M-6): pagar a conta de julho/2026
                // com data de 2001 tirava a despesa do fluxo de caixa e do
                // "gasto do mês", enquanto o saldo caía hoje.
                'after_or_equal:' . $this->pisoDaDataDePagamento(),
                'before_or_equal:' . now()->toDateString(),
            ],
            'funding_source' => ['nullable', Rule::in(FundingSource::TODAS)],
            'funding_investment_id' => [
                'nullable',
                'required_if:funding_source,' . FundingSource::RESGATE_INVESTIMENTO,
                Rule::exists('investments', 'id')->where('user_id', $ownerId),
            ],
        ];
    }

    /**
     * Teto relativo ao previsto — só depois das regras básicas, e só quando a
     * conta fixa é mesmo da família (senão a mensagem revelaria o valor
     * previsto de outra família; a posse é negada pela policy logo em seguida).
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $bill = $this->contaFixa();

                if (! $bill || $validator->errors()->has('amount')) {
                    return;
                }

                $previsto = round((float) $bill->amount, 2);
                $teto = round($previsto * self::TETO_PREVISTO, 2);
                $valor = round((float) $this->input('amount'), 2);

                if ($previsto > 0 && $valor > $teto) {
                    $validator->errors()->add('amount',
                        'O valor pago (' . Brl::format($valor) . ') é muito maior que o previsto para '
                        . $bill->name . ' (' . Brl::format($previsto) . '). Confira os centavos — se a conta '
                        . 'mudou de valor de vez, edite a conta fixa antes de pagar.');
                }
            },
        ];
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

        return CarbonImmutable::createFromFormat('!Y-m-d', $bruta . '-01')->startOfMonth();
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
