<?php

namespace App\Http\Requests;

use App\Models\Investment;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resgate de um investimento (modelo "cofrinho"): devolve dinheiro aplicado
 * para uma conta de destino (o disponível dela sobe). Nunca pode passar do que
 * está aplicado no investimento (principal).
 */
class WithdrawInvestmentContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A posse do investimento é verificada pela InvestmentPolicy no controller.
        return true;
    }

    /** Normaliza o valor digitado no padrão pt-BR para decimal. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->amount)) {
            $valor = trim(str_replace(['R$', ' '], '', $this->amount));

            if (str_contains($valor, ',')) {
                $valor = str_replace('.', '', $valor);  // remove separador de milhar
                $valor = str_replace(',', '.', $valor); // vírgula decimal -> ponto
            } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $valor)) {
                $valor = str_replace('.', '', $valor);  // só milhares: "1.234" -> "1234"
            }

            $this->merge(['amount' => $valor]);
        }
    }

    public function rules(): array
    {
        // Escopo por família: conta/autor precisam pertencer ao titular (ownerId).
        $userId = $this->user()->ownerId();

        return [
            'amount' => [
                'required',
                'numeric',
                'min:0.01',
                'max:9999999999999.99',
                // Não pode resgatar mais do que está aplicado no investimento.
                function (string $attribute, mixed $value, Closure $fail) {
                    /** @var Investment|null $investment */
                    $investment = $this->route('investimento');

                    if ($investment instanceof Investment && (float) $value > $investment->aplicado + 0.001) {
                        $fail('O valor do resgate é maior que o valor aplicado no investimento (R$ '
                            . number_format($investment->aplicado, 2, ',', '.') . ').');
                    }
                },
            ],
            'account_id' => [
                'required',
                // A conta de destino precisa pertencer à família.
                Rule::exists('accounts', 'id')->where('user_id', $userId),
            ],
            'made_by_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(function ($q) use ($userId) {
                    $q->where('id', $userId)->orWhere('account_owner_id', $userId);
                }),
            ],
            'date' => [
                'nullable',
                'date',
                'after_or_equal:2000-01-01',
                'before_or_equal:' . now()->addYears(10)->toDateString(),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'amount' => 'valor',
            'account_id' => 'conta',
            'date' => 'data',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Informe o valor do resgate.',
            'amount.numeric' => 'O valor deve ser um número. Use vírgula para os centavos, ex.: 50,00.',
            'amount.min' => 'O valor mínimo é R$ 0,01.',
            'amount.max' => 'O valor informado é alto demais.',
            'account_id.required' => 'Escolha a conta de destino do resgate.',
            'account_id.exists' => 'A conta escolhida não existe ou não pertence a você.',
            'date.date' => 'Data inválida.',
            'date.before_or_equal' => 'A data está longe demais no futuro.',
        ];
    }
}
