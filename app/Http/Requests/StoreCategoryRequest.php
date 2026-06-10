<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:income,expense'],
            'color' => ['nullable', 'string', 'max:30', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'type' => 'tipo',
            'color' => 'cor',
            'icon' => 'ícone',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da categoria.',
            'name.max' => 'O nome pode ter no máximo 255 caracteres.',
            'type.required' => 'Escolha o tipo: receita ou despesa.',
            'type.in' => 'Tipo de categoria inválido.',
            'color.regex' => 'A cor deve ser um código hexadecimal, ex.: #1C9A70.',
            'color.max' => 'Cor inválida.',
            'icon.max' => 'Ícone inválido.',
        ];
    }
}
