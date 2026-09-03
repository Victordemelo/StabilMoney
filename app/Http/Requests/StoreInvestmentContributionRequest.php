<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
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
    use NormalizesMoneyInput;

    public function authorize(): bool
    {
        // A posse do investimento é verificada pela InvestmentPolicy no controller.
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
                // Travas padrão do campo de dinheiro (inclui `decimal:0,2`, que é o
                // que barra "1e12" e a terceira casa decimal) + o teto seguro.
                ...$this->regrasDeDinheiro(),
                // Não pode aportar mais do que está disponível na conta de origem.
                function (string $attribute, mixed $value, Closure $fail) use ($userId) {
                    $account = Account::where('id', $this->input('account_id'))
                        ->where('user_id', $userId)
                        ->first();

                    if ($account && (float) $value > $account->available + 0.001) {
                        $fail('O valor do aporte é maior que o saldo disponível na conta de origem (R$ '
                            .number_format($account->available, 2, ',', '.').').');
                    }
                },
            ],
            'account_id' => [
                'required',
                // CRÍTICO: a conta precisa pertencer à família e não pode ser cartão de crédito.
                Rule::exists('accounts', 'id')->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)->whereIn('type', ['checking', 'savings']);
                }),
            ],
            // Quem aportou: precisa ser membro da família (titular ou dependente).
            'made_by_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(function ($q) use ($userId) {
                    $q->where('id', $userId)->orWhere('account_owner_id', $userId);
                }),
            ],
            // Idempotência (convenção do projeto: toda escrita de dinheiro por
            // clique leva uuid). Gerado pelo cliente a cada abertura do modal;
            // o reenvio com o mesmo uuid não grava de novo.
            'client_uuid' => ['nullable', 'uuid'],
            'date' => [
                'nullable',
                'date',
                'after_or_equal:2000-01-01',
                // Aporte/resgate NÃO aceita data futura. `Account::reserved` soma todas
                // as contributions sem olhar data (igual ao `balance`, que também ignora
                // `date`/`paid_at` — decisão D-4 da spec). Um aporte datado no mês que
                // vem já derrubava o disponível de HOJE, e um resgate futuro já o
                // levantava: dinheiro andando antes da hora, e o limite de gasto
                // decidindo com um número que ainda não é verdade.
                //
                // A saída coerente com o modelo é não deixar entrar movimentação que
                // ainda não aconteceu — tornar `reserved` sensível à data faria ele
                // discordar do `balance`, que continua somando tudo.
                'before_or_equal:'.now()->toDateString(),
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
            'amount.decimal' => 'Use no máximo duas casas decimais, ex.: 50,00.',
            'amount.min' => 'O valor mínimo é R$ 0,01.',
            'amount.max' => 'O valor informado é alto demais (o máximo é R$ 999.999.999.999,99).',
            'account_id.required' => 'Escolha a conta de origem do aporte.',
            'account_id.exists' => 'Escolha uma conta corrente ou poupança sua — cartões não guardam dinheiro.',
            'date.date' => 'Data inválida.',
            'date.before_or_equal' => 'A data não pode ser no futuro — registre o aporte no dia em que ele acontecer.',
        ];
    }
}
