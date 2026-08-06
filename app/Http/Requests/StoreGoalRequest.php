<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreGoalRequest extends FormRequest
{
    use NormalizesMoneyInput;

    public function authorize(): bool
    {
        // Dono dos dados é garantido pelo controller (user_id = ownerId)
        // e pela GoalPolicy na edição/exclusão.
        return true;
    }

    /**
     * Normaliza o valor-alvo digitado no padrão pt-BR (vírgula decimal,
     * ponto de milhar) para o formato decimal aceito pelo banco.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeMoneyField('target_amount');

        // Prazo vem de <input type="month"> como "AAAA-MM" → normaliza para o
        // primeiro dia do mês (a coluna é `date`); vazio vira null.
        if (is_string($this->target_date)) {
            $prazo = trim($this->target_date);

            if ($prazo === '') {
                $this->merge(['target_date' => null]);
            } elseif (preg_match('/^\d{4}-\d{2}$/', $prazo)) {
                $this->merge(['target_date' => $prazo.'-01']);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'target_amount' => $this->regrasDeDinheiro(),
            'target_date' => [
                'nullable',
                'date',
                'after_or_equal:'.now()->toDateString(),
                'before_or_equal:'.now()->addYears(10)->toDateString(),
            ],
            'emoji' => ['required', 'string', 'max:8'],
            'color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'target_amount' => 'valor-alvo',
            'target_date' => 'data-alvo',
            'emoji' => 'emoji',
            'color' => 'cor',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da meta.',
            'name.max' => 'O nome pode ter no máximo 80 caracteres.',
            'target_amount.required' => 'Informe o valor-alvo da meta.',
            'target_amount.numeric' => 'O valor-alvo deve ser um número. Use vírgula para os centavos, ex.: 1.500,00.',
            'target_amount.decimal' => 'Use no máximo duas casas decimais, ex.: 1.500,00.',
            'target_amount.min' => 'O valor-alvo mínimo é R$ 0,01.',
            'target_amount.max' => 'O valor-alvo informado é alto demais (o máximo é R$ 999.999.999.999,99).',
            'target_date.date' => 'Data-alvo inválida.',
            'target_date.after_or_equal' => 'A data-alvo deve ser de hoje em diante.',
            'target_date.before_or_equal' => 'A data-alvo está longe demais no futuro.',
            'emoji.required' => 'Escolha um emoji para a meta.',
            'emoji.max' => 'Emoji inválido.',
            'color.required' => 'Escolha uma cor para a meta.',
            'color.regex' => 'A cor deve ser um código hexadecimal, ex.: #1C9A70.',
        ];
    }
}
