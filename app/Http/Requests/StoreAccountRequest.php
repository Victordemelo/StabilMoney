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
     * Normaliza os valores monetários digitados no padrão pt-BR (vírgula
     * decimal, ponto de milhar) para o formato decimal aceito pelo banco.
     */
    protected function prepareForValidation(): void
    {
        foreach (['initial_balance', 'credit_limit'] as $campo) {
            if (is_string($this->{$campo})) {
                $this->merge([$campo => $this->normalizeMoney($this->{$campo})]);
            }
        }

        // Campos de cartão só fazem sentido para credit_card: nas demais contas
        // os zeramos para não persistir lixo (e não disparar validação à toa).
        if ($this->input('type') !== 'credit_card') {
            $this->merge([
                'credit_limit' => null,
                'closing_day' => null,
                'due_day' => null,
            ]);
        }
    }

    /** Converte "1.234,56" / "R$ 1.234" para "1234.56" / "1234". */
    private function normalizeMoney(string $valor): string
    {
        $valor = trim(str_replace(['R$', ' '], '', $valor));

        if (str_contains($valor, ',')) {
            $valor = str_replace('.', '', $valor);  // remove separador de milhar
            $valor = str_replace(',', '.', $valor); // vírgula decimal -> ponto
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $valor)) {
            $valor = str_replace('.', '', $valor);  // só milhares: "1.234" -> "1234"
        }

        return $valor;
    }

    public function rules(): array
    {
        $isCard = $this->input('type') === 'credit_card';

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:wallet,bank,credit_card,savings,investment,other'],
            'initial_balance' => ['required', 'numeric', 'between:-9999999999999.99,9999999999999.99'],
            // Campos de cartão: obrigatórios SÓ para credit_card; nullable nas demais.
            'credit_limit' => $isCard
                ? ['required', 'numeric', 'min:0.01', 'max:9999999999999.99']
                : ['nullable'],
            'closing_day' => $isCard
                ? ['required', 'integer', 'between:1,28']
                : ['nullable'],
            'due_day' => $isCard
                ? ['required', 'integer', 'between:1,28']
                : ['nullable'],
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
            'credit_limit' => 'limite do cartão',
            'closing_day' => 'dia de fechamento',
            'due_day' => 'dia de vencimento',
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
            'credit_limit.required' => 'Informe o limite do cartão.',
            'credit_limit.numeric' => 'O limite deve ser um número. Use vírgula para os centavos, ex.: 5.000,00.',
            'credit_limit.min' => 'O limite do cartão deve ser maior que zero.',
            'credit_limit.max' => 'O limite informado é alto demais.',
            'closing_day.required' => 'Informe o dia de fechamento da fatura.',
            'closing_day.integer' => 'O dia de fechamento deve ser um número.',
            'closing_day.between' => 'O dia de fechamento deve ser entre 1 e 28.',
            'due_day.required' => 'Informe o dia de vencimento da fatura.',
            'due_day.integer' => 'O dia de vencimento deve ser um número.',
            'due_day.between' => 'O dia de vencimento deve ser entre 1 e 28.',
            'color.regex' => 'A cor deve ser um código hexadecimal, ex.: #1C9A70.',
            'color.max' => 'Cor inválida.',
            'icon.max' => 'Ícone inválido.',
        ];
    }
}
