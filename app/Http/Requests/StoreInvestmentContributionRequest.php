<?php

namespace App\Http\Requests;

use App\Models\Account;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aporte num investimento (modelo "cofrinho"): reserva dinheiro de uma conta
 * (o disponível dela cai). Não vale para cartão de crédito e nunca pode passar
 * do saldo disponível da conta de origem.
 */
class StoreInvestmentContributionRequest extends FormRequest
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
                // Não pode aportar mais do que está disponível na conta de origem.
                function (string $attribute, mixed $value, Closure $fail) use ($userId) {
                    $account = Account::where('id', $this->input('account_id'))
                        ->where('user_id', $userId)
                        ->first();

                    if ($account && (float) $value > $account->available + 0.001) {
                        $fail('O valor do aporte é maior que o saldo disponível na conta de origem (R$ '
                            . number_format($account->available, 2, ',', '.') . ').');
                    }
                },
            ],
            'account_id' => [
                'required',
                // CRÍTICO: a conta precisa pertencer à família e não pode ser cartão de crédito.
                Rule::exists('accounts', 'id')->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)->where('type', '!=', 'credit_card');
                }),
            ],
            // Quem aportou: precisa ser membro da família (titular ou dependente).
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
            'amount.required' => 'Informe o valor do aporte.',
            'amount.numeric' => 'O valor deve ser um número. Use vírgula para os centavos, ex.: 50,00.',
            'amount.min' => 'O valor mínimo é R$ 0,01.',
            'amount.max' => 'O valor informado é alto demais.',
            'account_id.required' => 'Escolha a conta de origem do aporte.',
            'account_id.exists' => 'A conta escolhida não existe, não pertence a você ou é um cartão de crédito.',
            'date.date' => 'Data inválida.',
            'date.before_or_equal' => 'A data está longe demais no futuro.',
        ];
    }
}
