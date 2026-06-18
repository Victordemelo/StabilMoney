<?php

namespace App\Http\Requests;

use App\Models\Investment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edição de investimento: só os metadados (nome/classe/indexador/taxa).
 * NÃO mexe no aplicado — isso só muda via aportes/resgates. A posse é
 * verificada pela InvestmentPolicy no controller.
 */
class UpdateInvestmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Normaliza a taxa digitada no padrão pt-BR ("110,00") para decimal. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->taxa)) {
            $valor = trim(str_replace(['R$', '%', ' '], '', $this->taxa));

            if ($valor === '') {
                $valor = null;
            } elseif (str_contains($valor, ',')) {
                $valor = str_replace('.', '', $valor);  // remove separador de milhar
                $valor = str_replace(',', '.', $valor); // vírgula decimal -> ponto
            } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $valor)) {
                $valor = str_replace('.', '', $valor);  // só milhares
            }

            $this->merge(['taxa' => $valor]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'classe' => ['required', Rule::in(array_keys(Investment::CLASSES))],
            'indexador' => ['nullable', Rule::in(array_keys(Investment::INDEX_BASE))],
            'taxa' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'classe' => 'classe',
            'indexador' => 'indexador',
            'taxa' => 'taxa',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome do investimento.',
            'name.max' => 'O nome pode ter no máximo 80 caracteres.',
            'classe.required' => 'Escolha a classe do investimento.',
            'classe.in' => 'Classe inválida.',
            'indexador.in' => 'Indexador inválido.',
            'taxa.numeric' => 'A taxa deve ser um número. Use vírgula para os decimais, ex.: 110,00.',
            'taxa.min' => 'A taxa não pode ser negativa.',
        ];
    }
}
