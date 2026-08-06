<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use App\Models\Account;
use App\Models\Investment;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resgate de um investimento (modelo "cofrinho"): devolve dinheiro aplicado
 * para uma conta de destino (o disponível dela sobe). Nunca pode passar do que
 * AQUELA conta aplicou — só volta para a conta o que saiu dela.
 */
class WithdrawInvestmentContributionRequest extends FormRequest
{
    use NormalizesMoneyInput;

    /**
     * A posse é checada AQUI (e não só pela InvestmentPolicy no controller)
     * porque a validação roda antes do controller: sem isso, a mensagem de
     * erro revelava o valor aplicado no investimento de OUTRA família.
     */
    public function authorize(): bool
    {
        $investimento = $this->route('investimento');

        return $investimento instanceof Investment
            && $investimento->user_id === $this->user()?->ownerId();
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
                // Não pode resgatar mais do que ESTA conta aplicou no ativo
                // (o recheque final, sob lock, está em HandlesContributions).
                function (string $attribute, mixed $value, Closure $fail) use ($userId) {
                    /** @var Investment|null $investment */
                    $investment = $this->route('investimento');

                    $conta = Account::where('id', $this->input('account_id'))
                        ->where('user_id', $userId)
                        ->first();

                    // Sem ativo ou sem conta válida, quem reprova é a regra do account_id.
                    if (! $investment instanceof Investment || ! $conta) {
                        return;
                    }

                    $reservado = $investment->reservedFromAccount($conta->id);

                    if ((float) $value > $reservado + 0.001) {
                        $fail($investment->mensagemResgateAcimaDoReservado($conta, $reservado));
                    }
                },
            ],
            'account_id' => [
                'required',
                // A conta de destino precisa pertencer à família e não pode ser
                // cartão de crédito (não faz sentido "devolver" resgate para um cartão).
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
            'amount.required' => 'Informe o valor do resgate.',
            'amount.numeric' => 'O valor deve ser um número. Use vírgula para os centavos, ex.: 50,00.',
            'amount.decimal' => 'Use no máximo duas casas decimais, ex.: 50,00.',
            'amount.min' => 'O valor mínimo é R$ 0,01.',
            'amount.max' => 'O valor informado é alto demais (o máximo é R$ 999.999.999.999,99).',
            'account_id.required' => 'Escolha a conta de destino do resgate.',
            'account_id.exists' => 'Escolha uma conta corrente ou poupança sua — cartões não guardam dinheiro.',
            'date.date' => 'Data inválida.',
            'date.before_or_equal' => 'A data não pode ser no futuro — registre o resgate no dia em que ele acontecer.',
        ];
    }
}
