<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza o saldo inicial digitado no padrão pt-BR (vírgula decimal,
     * ponto de milhar) para o formato decimal aceito pelo banco.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->initial_balance)) {
            $valor = trim(str_replace(['R$', ' '], '', $this->initial_balance));

            if (str_contains($valor, ',')) {
                $valor = str_replace('.', '', $valor);  // remove separador de milhar
                $valor = str_replace(',', '.', $valor); // vírgula decimal -> ponto
            } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $valor)) {
                $valor = str_replace('.', '', $valor);  // só milhares: "1.234" -> "1234"
            }

            $this->merge(['initial_balance' => $valor]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:wallet,bank,credit_card,savings,investment,other'],
            'initial_balance' => ['required', 'numeric', 'between:-9999999999999.99,9999999999999.99'],
            'color' => ['nullable', 'string', 'max:30', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'type' => 'tipo',
            'initial_balance' => 'saldo inicial',
            'color' => 'cor',
            'icon' => 'ícone',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da conta.',
            'name.max' => 'O nome pode ter no máximo 255 caracteres.',
            'type.required' => 'Escolha o tipo da conta.',
            'type.in' => 'Tipo de conta inválido.',
            'initial_balance.required' => 'Informe o saldo inicial (pode ser 0,00).',
            'initial_balance.numeric' => 'O saldo inicial deve ser um número. Use vírgula para os centavos, ex.: 150,00.',
            'initial_balance.between' => 'O saldo inicial está fora do intervalo permitido.',
            'color.regex' => 'A cor deve ser um código hexadecimal, ex.: #1C9A70.',
            'color.max' => 'Cor inválida.',
            'icon.max' => 'Ícone inválido.',
        ];
    }
}
