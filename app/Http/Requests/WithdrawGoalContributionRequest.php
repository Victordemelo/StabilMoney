<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use App\Models\Goal;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resgate de uma meta (modelo "cofrinho"): devolve dinheiro guardado para
 * uma conta de destino (o disponível dela sobe). Nunca pode passar do que
 * está guardado na meta.
 */
class WithdrawGoalContributionRequest extends FormRequest
{
    use NormalizesMoneyInput;

    public function authorize(): bool
    {
        // A posse da meta é verificada pela GoalPolicy no controller.
        return true;
    }

    /** Normaliza o valor digitado no padrão pt-BR para decimal. */
    protected function prepareForValidation(): void
    {
        $this->normalizeMoneyField('amount');
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
                // Não pode resgatar mais do que está guardado na meta.
                function (string $attribute, mixed $value, Closure $fail) {
                    /** @var Goal|null $goal */
                    $goal = $this->route('meta');

                    if ($goal instanceof Goal && (float) $value > $goal->saved + 0.001) {
                        $fail('O valor do resgate é maior que o valor guardado na meta (R$ '
                            . number_format($goal->saved, 2, ',', '.') . ').');
                    }
                },
            ],
            'account_id' => [
                'required',
                // A conta de destino precisa pertencer à família e não pode ser
                // cartão de crédito (não faz sentido "devolver" reserva para um cartão).
                Rule::exists('accounts', 'id')->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)->whereIn('type', ['checking', 'savings']);
                }),
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
            'account_id.exists' => 'Escolha uma conta corrente ou poupança sua — cartões não guardam dinheiro.',
            'date.date' => 'Data inválida.',
            'date.before_or_equal' => 'A data está longe demais no futuro.',
        ];
    }
}
