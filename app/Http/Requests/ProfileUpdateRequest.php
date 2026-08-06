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

            // Nascimento e sexo são OPCIONAIS: nada no app depende deles, e um app de
            // finanças não pede dado que não usa (minimização — LGPD art. 6º, III).
            'birth_date' => [
                'nullable',
                'date',
                // Nascer no futuro não existe; e o piso evita erro de digitação virar
                // uma data absurda (ex.: ano 0210 ao errar o 2).
                'before_or_equal:today',
                'after_or_equal:1900-01-01',
            ],
            'gender' => ['nullable', Rule::in(array_keys(User::GENEROS))],

            'avatar' => ['nullable', 'image', 'max:2048'],
            // Senha atual exigida SÓ quando o e-mail muda. O e-mail é o que recupera a
            // conta: quem consegue trocá-lo (sessão sequestrada, aparelho aberto) pedia
            // "esqueci a senha" e tomava a conta em definitivo. Trocar nome, telefone ou
            // foto não tem esse poder, então não faz sentido pedir senha para isso.
            'current_password' => [
                $this->trocandoEmail() ? 'required' : 'nullable',
                'current_password',
            ],
        ];
    }

    /** O e-mail enviado é diferente do que está na conta? */
    protected function trocandoEmail(): bool
    {
        $novo = $this->input('email');

        return is_string($novo)
            && mb_strtolower(trim($novo)) !== mb_strtolower((string) $this->user()->email);
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
            'birth_date.date' => 'Data de nascimento inválida.',
            'birth_date.before_or_equal' => 'A data de nascimento não pode ser no futuro.',
            'birth_date.after_or_equal' => 'Confira o ano da data de nascimento.',
            'gender.in' => 'Escolha uma das opções de sexo.',
            'avatar.image' => 'A foto precisa ser uma imagem.',
            'avatar.max' => 'A foto pode ter no máximo 2 MB.',
            'current_password.required' => 'Para trocar o e-mail, confirme sua senha atual.',
            'current_password.current_password' => 'A senha informada está incorreta.',
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
            'phone' => 'telefone',
            'birth_date' => 'data de nascimento',
            'gender' => 'sexo',
            'current_password' => 'senha atual',
        ];
    }
}
