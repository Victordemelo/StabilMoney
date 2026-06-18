<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ];
    }

    /**
     * Mensagens de validação em PT-BR.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe seu nome.',
            'name.max' => 'O nome não pode passar de :max caracteres.',
            'email.required' => 'Informe seu e-mail.',
            'email.email' => 'Informe um e-mail válido.',
            'email.lowercase' => 'O e-mail deve estar em letras minúsculas.',
            'email.max' => 'O e-mail não pode passar de :max caracteres.',
            'email.unique' => 'Este e-mail já está em uso por outra conta.',
            'avatar.image' => 'A foto precisa ser uma imagem.',
            'avatar.max' => 'A foto pode ter no máximo 2 MB.',
        ];
    }

    /**
     * Nomes amigáveis dos campos (usados nas mensagens genéricas).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'email' => 'e-mail',
        ];
    }
}
