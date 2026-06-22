<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Cadastro de dependente — só o titular pode. Inclui foto e parentesco (opcionais).
 */
class StoreDependentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isTitular() === true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'relationship' => ['nullable', Rule::in(array_keys(User::RELATIONSHIPS))],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'email' => 'e-mail',
            'password' => 'senha',
            'avatar' => 'foto',
            'relationship' => 'parentesco',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Este e-mail já está em uso.',
            'email.lowercase' => 'O e-mail deve ser informado em minúsculas.',
            'avatar.image' => 'A foto precisa ser uma imagem.',
            'avatar.max' => 'A foto pode ter no máximo 2 MB.',
            'relationship.in' => 'Parentesco inválido.',
        ];
    }
}
