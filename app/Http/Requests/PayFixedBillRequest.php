<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use App\Support\FundingSource;
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
 */
class PayFixedBillRequest extends FormRequest
{
    use NormalizesMoneyInput;

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
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'paid_on' => [
                'nullable',
                'date',
                'after_or_equal:2000-01-01',
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
            'amount.min' => 'O valor mínimo é R$ 0,01.',
            'paid_on.before_or_equal' => 'A data do pagamento não pode ser no futuro.',
            'funding_investment_id.required_if' => 'Escolha de qual investimento resgatar.',
        ];
    }
}
