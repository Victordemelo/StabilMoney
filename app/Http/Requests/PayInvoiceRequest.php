<?php

namespace App\Http\Requests;

use App\Support\FundingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pagamento da fatura de um cartão de crédito.
 *
 * Antes isto era um `$request->validate()` inline no controller, com um campo
 * só e a data forçada em `now()`. Agora tem Form Request (convenção do projeto)
 * e a DATA DO PAGAMENTO é informável: quitar uma fatura em atraso registra o
 * dia em que o dinheiro saiu, não o dia do clique.
 *
 * A posse do cartão é verificada pela AccountPolicy no controller.
 */
class PayInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
                'date',
                'after_or_equal:2000-01-01',
                // Pagamento é fato consumado: não se paga no futuro.
                'before_or_equal:' . now()->toDateString(),
            ],
            // Se o caixa escolhido não cobrir, o FundingService pergunta a fonte.
            'funding_source' => ['nullable', Rule::in(FundingSource::TODAS)],
            'funding_investment_id' => [
                'nullable',
                'required_if:funding_source,' . FundingSource::RESGATE_INVESTIMENTO,
                Rule::exists('investments', 'id')->where('user_id', $ownerId),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'pay_account_id' => 'conta de pagamento',
            'paid_on' => 'data do pagamento',
        ];
    }

    public function messages(): array
    {
        return [
            'pay_account_id.required' => 'Escolha a conta que vai pagar a fatura.',
            'pay_account_id.exists' => 'A conta de pagamento precisa ser uma conta corrente ou poupança sua.',
            'paid_on.date' => 'Data de pagamento inválida.',
            'paid_on.before_or_equal' => 'A data do pagamento não pode ser no futuro.',
            'funding_source.in' => 'Escolha de onde sai o dinheiro é inválida.',
            'funding_investment_id.required_if' => 'Escolha de qual investimento resgatar.',
            'funding_investment_id.exists' => 'O investimento escolhido não existe ou não é da sua família.',
        ];
    }
}
