<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use App\Models\Account;
use App\Models\Category;
use App\Support\FundingSource;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lançamento de DESPESA na feature "Faturas / Despesas".
 *
 * mode:
 *  - avista:      qualquer método (conta/débito/Pix/cartão) — 1 transação.
 *  - parcelado N: SÓ cartão de crédito — N transações (uma por mês).
 *  - recorrente:  SÓ cartão de crédito — 12 transações mensais.
 *
 * A GERAÇÃO das linhas fica no FaturaController; aqui só validamos a entrada.
 */
class StoreFaturaLaunchRequest extends FormRequest
{
    use NormalizesMoneyInput;

    public function authorize(): bool
    {
        // Posse garantida pelas regras (conta/categoria/autor da própria família).
        return true;
    }

    /**
     * Normaliza o valor digitado no padrão pt-BR (vírgula decimal,
     * ponto de milhar) para o formato decimal aceito pelo banco.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeMoneyField('amount');
    }

    public function rules(): array
    {
        // Escopo por família: conta/categoria/autor pertencem ao titular (ownerId).
        $userId = $this->user()->ownerId();

        return [
            // Idempotência do clique: o mesmo formulário reenviado (duplo clique,
            // botão "voltar", resposta perdida) chega com o MESMO uuid e o
            // controller devolve o lançamento existente em vez de criar outro.
            // Sem isto, um duplo clique num parcelado em 12x criava 24 linhas.
            'client_uuid' => ['nullable', 'uuid'],
            'description' => ['required', 'string', 'max:255'],
            // Travas do campo de dinheiro (decimal:0,2 + teto seguro) no trait:
            // `numeric` sozinho aceitava "1e12" e a terceira casa decimal — e
            // aqui o estrago é multiplicado pelas parcelas.
            'amount' => $this->regrasDeDinheiro(),
            'date' => [
                'required',
                'date',
                'after_or_equal:2000-01-01',
                'before_or_equal:'.now()->addYears(10)->toDateString(),
            ],
            'account_id' => [
                'required',
                // CRÍTICO: a conta (método) precisa pertencer à família.
                // Cartão de débito é recusado (não tem saldo próprio) — o select
                // manda a conta corrente/poupança que ele espelha.
                Rule::exists('accounts', 'id')->where(fn ($q) => $q
                    ->where('user_id', $userId)
                    // Métodos ESPELHO (débito e Pix) não têm saldo próprio: os
                    // selects já mandam o id da conta vinculada.
                    ->whereNotIn('type', ['debit_card', 'pix'])),
            ],
            // De onde sai o dinheiro quando o disponível não cobre (o
            // FundingService responde 409 pedindo a escolha).
            'funding_source' => ['nullable', Rule::in(FundingSource::TODAS)],
            'funding_investment_id' => [
                'nullable',
                'required_if:funding_source,'.FundingSource::RESGATE_INVESTIMENTO,
                Rule::exists('investments', 'id')->where('user_id', $userId),
            ],
            'category_id' => [
                'nullable',
                // CRÍTICO: a categoria precisa pertencer à família e ser de despesa.
                Rule::exists('categories', 'id')->where('user_id', $userId),
                function (string $attribute, mixed $value, Closure $fail) use ($userId) {
                    if (! $value) {
                        return;
                    }

                    $categoria = Category::where('id', $value)
                        ->where('user_id', $userId)
                        ->first();

                    if ($categoria && $categoria->type !== 'expense') {
                        $fail('A categoria escolhida não é de despesa.');
                    }
                },
            ],
            // Quem fez a compra: precisa ser membro da família (titular ou dependente).
            'made_by_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(function ($q) use ($userId) {
                    $q->where('id', $userId)->orWhere('account_owner_id', $userId);
                }),
            ],
            'mode' => ['required', 'in:avista,parcelado,recorrente'],
            'installments' => ['required_if:mode,parcelado', 'integer', 'between:2,24'],
        ];
    }

    /**
     * Regra cruzada: parcelado/recorrente SÓ em cartão de crédito.
     * Conta/débito/Pix é apenas à vista.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $mode = $this->input('mode');
            if (! in_array($mode, ['parcelado', 'recorrente'], true)) {
                return;
            }

            $account = Account::where('id', $this->input('account_id'))
                ->where('user_id', $this->user()->ownerId())
                ->first();

            if ($account && ! $account->isCard()) {
                $validator->errors()->add(
                    'mode',
                    'Parcelamento e recorrência só estão disponíveis para cartão de crédito. Para esta conta, use à vista.',
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'description' => 'descrição',
            'amount' => 'valor',
            'date' => 'data',
            'account_id' => 'método de pagamento',
            'category_id' => 'categoria',
            'made_by_user_id' => 'responsável',
            'mode' => 'forma de pagamento',
            'installments' => 'parcelas',
        ];
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Informe a descrição da despesa.',
            'description.max' => 'A descrição pode ter no máximo 255 caracteres.',
            'amount.required' => 'Informe o valor da despesa.',
            'amount.numeric' => 'O valor deve ser um número. Use vírgula para os centavos, ex.: 25,90.',
            'amount.decimal' => 'Use no máximo duas casas decimais, ex.: 25,90.',
            'amount.min' => 'O valor mínimo é R$ 0,01.',
            'amount.max' => 'O valor informado é alto demais (o máximo é R$ 999.999.999.999,99).',
            'date.required' => 'Informe a data da despesa.',
            'date.date' => 'Data inválida.',
            'date.after_or_equal' => 'A data deve ser a partir de 01/01/2000.',
            'date.before_or_equal' => 'A data está longe demais no futuro.',
            'account_id.required' => 'Escolha o método de pagamento.',
            'account_id.exists' => 'O método escolhido não existe ou não pertence a você. Cartão de débito e Pix não têm saldo próprio — escolha a conta que eles usam.',
            'funding_source.in' => 'Escolha de onde sai o dinheiro é inválida.',
            'funding_investment_id.required_if' => 'Escolha de qual investimento resgatar.',
            'funding_investment_id.exists' => 'O investimento escolhido não existe ou não é da sua família.',
            'category_id.exists' => 'A categoria escolhida não existe ou não pertence a você.',
            'mode.required' => 'Escolha a forma de pagamento.',
            'mode.in' => 'Forma de pagamento inválida.',
            'installments.required_if' => 'Informe em quantas parcelas dividir.',
            'installments.integer' => 'O número de parcelas deve ser inteiro.',
            'installments.between' => 'O parcelamento deve ser entre 2 e 24 vezes.',
        ];
    }
}
