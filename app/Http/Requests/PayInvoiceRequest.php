<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\FundingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Pagamento da fatura de um cartão de crédito.
 *
 * Antes isto era um `$request->validate()` inline no controller, com um campo
 * só e a data forçada em `now()`. Agora tem Form Request (convenção do projeto)
 * e a DATA DO PAGAMENTO é informável: quitar uma fatura em atraso registra o
 * dia em que o dinheiro saiu, não o dia do clique.
 *
 * A posse do cartão é verificada aqui E pela AccountPolicy no controller (defesa em
 * profundidade — ver o `authorize()` abaixo).
 */
class PayInvoiceRequest extends FormRequest
{
    use NormalizesMoneyInput;

    /**
     * Posse do cartão ANTES da validação.
     *
     * Sem isto, quem tentasse pagar a fatura de outra família era barrado pelo FK da
     * conta de pagamento — 302 com erro de validação em vez de 403. Funcionava, mas por
     * efeito colateral da ordem dos middlewares, e a mensagem contava mais do que devia.
     */
    public function authorize(): bool
    {
        $cartao = $this->route('account');

        if (! $cartao instanceof Account) {
            return false;
        }

        return $cartao->user_id === $this->user()?->ownerId();
    }

    public function rules(): array
    {
        $ownerId = $this->user()->ownerId();

        return [
            'pay_account_id' => [
                'required',
                // Precisa ser uma conta de CAIXA (corrente/poupança) da mesma família.
                Rule::exists('accounts', 'id')->where(fn ($q) => $q
                    ->where('user_id', $ownerId)
                    ->whereIn('type', ['checking', 'savings'])),
            ],
            'paid_on' => [
                'nullable',
                'date_format:Y-m-d',
                // Piso RELATIVO à dívida: não se paga uma fatura antes de a compra
                // existir. Antes bastava ser >= 2000-01-01, então `paid_on=2001-03-04`
                // era aceito numa compra de 2026: o saldo descontava hoje, mas a despesa
                // sumia do fluxo de caixa e do "gasto do mês" (que olham a data).
                'after_or_equal:'.$this->pisoDaDataDoPagamento(),
                // Pagamento é fato consumado: não se paga no futuro.
                'before_or_equal:'.now()->toDateString(),
            ],
            // Qual fatura está sendo paga: a do ciclo aberto (padrão) ou a do ciclo já
            // fechado e vencida — que antes não tinha caminho de pagamento nenhum.
            'ciclo' => ['nullable', Rule::in(['aberto', 'fechado'])],
            // Se o caixa escolhido não cobrir, o FundingService pergunta a fonte.
            'funding_source' => ['nullable', Rule::in(FundingSource::TODAS)],
            'funding_investment_id' => [
                'nullable',
                'required_if:funding_source,'.FundingSource::RESGATE_INVESTIMENTO,
                Rule::exists('investments', 'id')->where('user_id', $ownerId),
            ],
            // TETO do resgate que o usuário aprovou no modal de fonte (F-3 da
            // auditoria de 02/09/2026). O front já mandava o campo neste caminho,
            // mas ele não era validado nem repassado ao FundingService — um
            // pagamento de fatura que dormiu na fila resgatava mais do que o
            // número que a pessoa viu. Mesma regra do StoreTransactionRequest.
            'funding_max_amount' => $this->regrasDeDinheiro(obrigatorio: false),
        ];
    }

    /**
     * Piso do `paid_on`: a despesa em aberto mais antiga do cartão — ou HOJE, se ela
     * for datada no futuro (achado de 24/09/2026 — `PagarFaturaComParcelaDatadaNoFuturoTest`).
     *
     * O teto é hoje (pagamento é fato consumado). Com a fatura anterior já paga, a
     * despesa mais antiga em aberto pode ser a PRÓXIMA PARCELA de uma compra parcelada,
     * datada no mês que vem: piso no futuro e teto hoje, nenhuma data valia — a tela
     * mostrava "Marcar como paga" e o servidor recusava qualquer uma, dizendo que o
     * pagamento vinha "antes da compra" (a compra é do mês passado; só a parcela é
     * datada adiante). Limitado a hoje, o piso continua fazendo o que existe para
     * fazer: barrar o pagamento datado antes de uma compra que já aconteceu.
     */
    protected function pisoDaDataDoPagamento(): string
    {
        return min($this->primeiraDespesaDoCartao(), now()->toDateString());
    }

    /**
     * Data da despesa mais antiga EM ABERTO do cartão.
     * Sem despesa em aberto, cai no piso genérico (não há o que pagar mesmo).
     */
    protected function primeiraDespesaDoCartao(): string
    {
        $cartao = $this->route('account');

        if (! $cartao instanceof Account) {
            return '2000-01-01';
        }

        $primeira = Transaction::where('account_id', $cartao->id)
            ->where('type', 'expense')
            ->whereNull('paid_at')
            ->min('date');

        return $primeira ? Carbon::parse($primeira)->toDateString() : '2000-01-01';
    }

    public function attributes(): array
    {
        return [
            'pay_account_id' => 'conta de pagamento',
            'paid_on' => 'data do pagamento',
            'ciclo' => 'fatura',
            'funding_max_amount' => 'valor aprovado',
        ];
    }

    public function messages(): array
    {
        return [
            'pay_account_id.required' => 'Escolha a conta que vai pagar a fatura.',
            'pay_account_id.exists' => 'A conta de pagamento precisa ser uma conta corrente ou poupança sua.',
            'paid_on.date_format' => 'Data de pagamento inválida.',
            'paid_on.before_or_equal' => 'A data do pagamento não pode ser no futuro.',
            'paid_on.after_or_equal' => 'A data do pagamento não pode ser anterior à compra mais antiga da fatura.',
            'ciclo.in' => 'Escolha qual fatura pagar: a do ciclo aberto ou a que já fechou.',
            'funding_source.in' => 'Escolha de onde sai o dinheiro é inválida.',
            'funding_investment_id.required_if' => 'Escolha de qual investimento resgatar.',
            'funding_investment_id.exists' => 'O investimento escolhido não existe ou não é da sua família.',
        ];
    }
}
