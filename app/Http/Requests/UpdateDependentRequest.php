<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Edição de dependente — só o titular dono pode. Senha é opcional (só troca se
 * preenchida); foto também opcional.
 */
class UpdateDependentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dependent = $this->route('dependent');

        return $this->user()?->isTitular() === true
            && $dependent instanceof User
            && $dependent->account_owner_id === $this->user()->id;
    }

    public function rules(): array
    {
        $dependent = $this->route('dependent');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($dependent->id),
            ],
            // Opcional: em branco mantém a senha atual.
            'password' => ['nullable', Password::defaults()],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'email' => 'e-mail',
            'password' => 'senha',
            'avatar' => 'foto',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Este e-mail já está em uso.',
            'email.lowercase' => 'O e-mail deve ser informado em minúsculas.',
            'avatar.image' => 'A foto precisa ser uma imagem.',
            'avatar.max' => 'A foto pode ter no máximo 2 MB.',
        ];
    }
}
