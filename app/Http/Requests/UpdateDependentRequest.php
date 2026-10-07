<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Edição de dependente — o titular, ou outro dependente se o titular deixou
 * (`User::podeEditarNaFamilia`). Senha é opcional (só troca se preenchida); foto também.
 */
class UpdateDependentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dependent = $this->route('dependent');

        // O titular, ou outro dependente com as permissões do titular ligadas (a regra é uma
        // só, no User; o controller confere de novo).
        return $dependent instanceof User
            && $this->user()?->podeEditarNaFamilia($dependent) === true;
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
            // Tirar a foto sem subir outra. Com arquivo novo junto, vale o arquivo.
            'remover_foto' => ['nullable', 'boolean'],
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
