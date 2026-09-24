<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use App\Models\Account;
use App\Models\Investment;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criação de investimento (modelo "cofrinho"). Metadados (classe, indexador,
 * taxa) + um aporte inicial OPCIONAL: se `valor_inicial` > 0, o controller cria
 * uma contribution 'aporte' a partir da conta escolhida (que não pode ser cartão
 * de crédito e precisa ter saldo disponível suficiente).
 */
class StoreInvestmentRequest extends FormRequest
{
    use NormalizesMoneyInput;

    public function authorize(): bool
    {
        // Dono dos dados é garantido pelo controller (user_id = ownerId)
        // e pela InvestmentPolicy na edição/exclusão.
        return true;
    }

    /**
     * Normaliza valor inicial e taxa digitados no padrão pt-BR para decimal.
     * Ambos removem "%" e tratam vazio como null (campos opcionais).
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeMoneyField('valor_inicial', emptyToNull: true, stripPercent: true);
        $this->normalizeMoneyField('taxa', emptyToNull: true, stripPercent: true);
    }

    public function rules(): array
    {
        // Escopo por família: conta/autor precisam pertencer ao titular (ownerId).
        $userId = $this->user()->ownerId();
        $temValorInicial = (float) $this->input('valor_inicial') > 0;

        return [
            'name' => ['required', 'string', 'max:80'],
            'classe' => ['required', Rule::in(array_keys(Investment::CLASSES))],
            'indexador' => ['nullable', Rule::in(array_keys(Investment::INDEX_BASE))],
            'taxa' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            // Aporte inicial opcional.
            'valor_inicial' => $this->regrasDeDinheiro(obrigatorio: false, min: '0'),

            'account_id' => [
                // Só obrigatória se houver aporte inicial.
                Rule::requiredIf($temValorInicial),
                'nullable',
                // CRÍTICO: a conta precisa pertencer à família e não pode ser cartão de crédito.
                Rule::exists('accounts', 'id')->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)->whereIn('type', ['checking', 'savings']);
                }),
                // O aporte inicial não pode passar do saldo disponível da conta.
                function (string $attribute, mixed $value, Closure $fail) use ($userId, $temValorInicial) {
                    if (! $temValorInicial || ! $value) {
                        return;
                    }

                    $account = Account::where('id', $value)
                        ->where('user_id', $userId)
                        ->first();

                    if ($account && (float) $this->input('valor_inicial') > $account->available + 0.001) {
                        $fail('O valor inicial é maior que o saldo disponível na conta de origem (R$ '
                            .number_format($account->available, 2, ',', '.').').');
                    }
                },
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
                'date_format:Y-m-d',
                'after_or_equal:2000-01-01',
                // Data do APORTE INICIAL (o controller grava uma `investment_contribution`
                // com ela quando `valor_inicial` > 0), então vale a mesma regra dos
                // outros quatro Form Requests de aporte/resgate: nada de futuro.
                //
                // `Account::reserved` soma as contribuições SEM olhar data (igual ao
                // `balance` — decisão D-4 da spec). Um aporte datado em dezembro
                // derrubava o disponível de HOJE e fazia o app recusar despesa que
                // cabe. Esta era a 5ª porta para o mesmo buraco, deixada aberta quando
                // as outras quatro foram fechadas.
                'before_or_equal:'.now()->toDateString(),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'classe' => 'classe',
            'indexador' => 'indexador',
            'taxa' => 'taxa',
            'valor_inicial' => 'valor inicial',
            'account_id' => 'conta',
            'date' => 'data',
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
            'valor_inicial.numeric' => 'O valor inicial deve ser um número. Use vírgula para os centavos, ex.: 1.000,00.',
            'valor_inicial.decimal' => 'Use no máximo duas casas decimais, ex.: 1.000,00.',
            'valor_inicial.min' => 'O valor inicial não pode ser negativo.',
            'valor_inicial.max' => 'O valor inicial informado é alto demais (o máximo é R$ 999.999.999.999,99).',
            'account_id.required' => 'Escolha a conta de origem do valor inicial.',
            'account_id.exists' => 'Escolha uma conta corrente ou poupança sua — cartões não guardam dinheiro.',
            'date.date_format' => 'Data inválida.',
            'date.before_or_equal' => 'A data não pode ser no futuro — registre o aporte no dia em que ele acontecer.',
        ];
    }
}
