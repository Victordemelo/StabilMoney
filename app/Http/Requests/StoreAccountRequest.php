<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    use NormalizesMoneyInput;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza os valores monetários (pt-BR -> decimal) e zera os campos que
     * não pertencem ao tipo escolhido, para não persistir lixo nem disparar
     * validação à toa.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeMoneyField('initial_balance');
        $this->normalizeMoneyField('credit_limit');

        $type = $this->input('type');

        // Saldo inicial só existe para conta corrente/poupança.
        if (! in_array($type, ['checking', 'savings'], true)) {
            $this->merge(['initial_balance' => null]);
        }
        // Campos de cartão de crédito.
        if ($type !== 'credit_card') {
            $this->merge(['credit_limit' => null, 'closing_day' => null, 'due_day' => null]);
        }
        // Vínculos só existem para cartão de débito.
        if ($type !== 'debit_card') {
            $this->merge(['checking_account_id' => null, 'savings_account_id' => null]);
        }
    }

    public function rules(): array
    {
        $type = $this->input('type');
        $isAccount = in_array($type, ['checking', 'savings'], true); // tem saldo próprio
        $isCredit = $type === 'credit_card';
        $isDebit = $type === 'debit_card';
        $ownerId = $this->user()->ownerId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'bank' => ['required', Rule::in(array_keys(Account::BANKS))],

            // Saldo inicial: obrigatório p/ conta corrente/poupança; ausente nos cartões.
            'initial_balance' => $isAccount
                ? ['required', 'numeric', 'min:0', 'max:9999999999999.99']
                : ['nullable'],

            // Cartão de crédito: limite + dias.
            'credit_limit' => $isCredit
                ? ['required', 'numeric', 'min:0.01', 'max:9999999999999.99']
                : ['nullable'],
            'closing_day' => $isCredit ? ['required', 'integer', 'between:1,28'] : ['nullable'],
            'due_day' => $isCredit ? ['required', 'integer', 'between:1,28'] : ['nullable'],

            // Cartão de débito: espelha uma conta corrente e/ou poupança da família.
            // Pelo menos uma é obrigatória (required_without) e precisa ser do tipo certo.
            'checking_account_id' => $isDebit
                ? ['nullable', 'required_without:savings_account_id', $this->linkRule($ownerId, 'checking')]
                : ['nullable'],
            'savings_account_id' => $isDebit
                ? ['nullable', 'required_without:checking_account_id', $this->linkRule($ownerId, 'savings')]
                : ['nullable'],
        ];
    }

    /** Regra de existência da conta vinculada: precisa ser da família e do tipo certo. */
    private function linkRule(int $ownerId, string $type): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('accounts', 'id')->where(function ($q) use ($ownerId, $type) {
            $q->where('user_id', $ownerId)->where('type', $type);
        });
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'type' => 'tipo',
            'bank' => 'banco',
            'initial_balance' => 'saldo inicial',
            'credit_limit' => 'limite do cartão',
            'closing_day' => 'dia de fechamento',
            'due_day' => 'dia de vencimento',
            'checking_account_id' => 'conta corrente vinculada',
            'savings_account_id' => 'conta poupança vinculada',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da conta.',
            'name.max' => 'O nome pode ter no máximo 255 caracteres.',
            'type.required' => 'Escolha o tipo da conta.',
            'type.in' => 'Tipo de conta inválido.',
            'bank.required' => 'Escolha o banco.',
            'bank.in' => 'Banco inválido.',
            'initial_balance.required' => 'Informe o saldo inicial (pode ser 0,00).',
            'initial_balance.numeric' => 'O saldo inicial deve ser um número. Use vírgula para os centavos, ex.: 150,00.',
            'initial_balance.min' => 'O saldo inicial não pode ser negativo.',
            'initial_balance.max' => 'O saldo inicial informado é alto demais.',
            'credit_limit.required' => 'Informe o limite do cartão.',
            'credit_limit.numeric' => 'O limite deve ser um número. Use vírgula para os centavos, ex.: 5.000,00.',
            'credit_limit.min' => 'O limite do cartão deve ser maior que zero.',
            'credit_limit.max' => 'O limite informado é alto demais.',
            'closing_day.required' => 'Informe o dia de fechamento da fatura.',
            'closing_day.between' => 'O dia de fechamento deve ser entre 1 e 28.',
            'due_day.required' => 'Informe o dia de vencimento da fatura.',
            'due_day.between' => 'O dia de vencimento deve ser entre 1 e 28.',
            'checking_account_id.required_without' => 'Vincule pelo menos uma conta (corrente ou poupança) ao cartão de débito.',
            'checking_account_id.exists' => 'A conta corrente escolhida não existe ou não é sua.',
            'savings_account_id.exists' => 'A conta poupança escolhida não existe ou não é sua.',
        ];
    }
}
