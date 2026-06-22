<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Cadastro de dependente — só o titular pode. Inclui foto (opcional) e o
 * saldo/limite de gasto (opcional): quanto o dependente pode gastar.
 */
class StoreDependentRequest extends FormRequest
{
    use NormalizesMoneyInput;

    public function authorize(): bool
    {
        return $this->user()?->isTitular() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeMoneyField('spending_limit', emptyToNull: true);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'spending_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'email' => 'e-mail',
            'password' => 'senha',
            'avatar' => 'foto',
            'spending_limit' => 'saldo para gastar',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Este e-mail já está em uso.',
            'email.lowercase' => 'O e-mail deve ser informado em minúsculas.',
            'avatar.image' => 'A foto precisa ser uma imagem.',
            'avatar.max' => 'A foto pode ter no máximo 2 MB.',
            'spending_limit.numeric' => 'O saldo deve ser um número. Use vírgula para os centavos, ex.: 200,00.',
            'spending_limit.min' => 'O saldo não pode ser negativo.',
        ];
    }
}
