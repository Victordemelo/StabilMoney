<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro de conta fixa mensal (condomínio, aluguel, carro…).
 *
 * `due_day` aceita 1..31 — diferente do 1..28 dos cartões — porque o clamp de
 * mês curto é feito no model (FixedBill::dueDateFor). Aluguel que vence dia 30
 * é comum e precisa poder ser cadastrado com o dia verdadeiro.
 */
class StoreFixedBillRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'account_id' => [
                'nullable',
                // Só conta de caixa ou cartão de crédito pagam uma conta fixa;
                // cartão de débito não tem saldo próprio.
                Rule::exists('accounts', 'id')->where(fn ($q) => $q
                    ->where('user_id', $ownerId)
                    ->where('type', '!=', 'debit_card')),
            ],
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where(fn ($q) => $q
                    ->where('user_id', $ownerId)
                    ->where('type', 'expense')),
            ],
            'starts_on' => ['required', 'date', 'after_or_equal:2000-01-01'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'amount' => 'valor',
            'due_day' => 'dia do vencimento',
            'account_id' => 'método de pagamento',
            'category_id' => 'categoria',
            'starts_on' => 'início',
            'ends_on' => 'fim',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da conta fixa (ex.: Condomínio).',
            'name.max' => 'O nome pode ter no máximo 255 caracteres.',
            'amount.required' => 'Informe o valor mensal.',
            'amount.numeric' => 'O valor deve ser um número. Use vírgula para os centavos, ex.: 800,00.',
            'amount.min' => 'O valor mínimo é R$ 0,01.',
            'due_day.required' => 'Informe o dia do vencimento.',
            'due_day.between' => 'O dia do vencimento deve ser entre 1 e 31.',
            'account_id.exists' => 'O método escolhido não existe ou não pertence a você.',
            'category_id.exists' => 'A categoria escolhida não existe, não é sua ou não é de despesa.',
            'starts_on.required' => 'Informe a partir de quando esta conta começa.',
            'ends_on.after_or_equal' => 'O fim não pode ser antes do início.',
        ];
    }
}
