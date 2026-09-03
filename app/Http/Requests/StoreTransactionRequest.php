<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesMoneyInput;
use App\Models\Category;
use App\Support\FundingSource;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    use NormalizesMoneyInput;

    public function authorize(): bool
    {
        // Dono dos dados é garantido pelas regras (conta/categoria do próprio usuário)
        // e pelas Policies nos controllers.
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
        // TRANSFERÊNCIA entre contas chega pela MESMA rota (`type=transfer`) de
        // propósito: a fila offline e o service worker reenviam tudo para
        // `POST /transactions`, e um endpoint separado deixaria a transferência
        // feita sem internet morrer num 422 de "tipo inválido" na hora de
        // sincronizar. A rota `transactions.transfer` existe também, e as duas
        // caem nas mesmas regras (ver StoreTransferRequest).
        if ($this->aceitaTransferencia() && $this->input('type') === 'transfer') {
            return $this->regrasDeTransferencia();
        }

        // Escopo por família: conta/categoria precisam pertencer ao titular (ownerId).
        $userId = $this->user()->ownerId();

        return [
            // Idempotência da fila offline: gerado no cliente, opcional (web normal não usa).
            'client_uuid' => ['nullable', 'uuid'],
            'type' => ['required', 'in:income,expense'],
            // Travas do campo de dinheiro (decimal:0,2 + teto seguro) no trait:
            // `numeric` sozinho aceitava "1e12" e a terceira casa decimal.
            'amount' => $this->regrasDeDinheiro(),
            'account_id' => [
                'required',
                // CRÍTICO: a conta precisa pertencer ao usuário logado.
                // Cartão de DÉBITO é recusado: ele não tem saldo próprio (só
                // espelha a corrente/poupança), então uma transação nele não
                // descontava de conta nenhuma. Os selects já mandam a conta
                // vinculada quando o usuário escolhe o cartão.
                Rule::exists('accounts', 'id')->where(fn ($q) => $q
                    ->where('user_id', $userId)
                    // Métodos ESPELHO (débito e Pix) não têm saldo próprio: os
                    // selects já mandam o id da conta vinculada.
                    ->whereNotIn('type', ['debit_card', 'pix'])),
            ],
            'category_id' => [
                'nullable',
                // CRÍTICO: a categoria precisa pertencer ao usuário logado
                Rule::exists('categories', 'id')->where('user_id', $userId),
                // O tipo da categoria deve casar com o tipo da transação
                function (string $attribute, mixed $value, Closure $fail) use ($userId) {
                    $tipo = $this->input('type');
                    if (! $value || ! in_array($tipo, ['income', 'expense'], true)) {
                        return;
                    }

                    $categoria = Category::where('id', $value)
                        ->where('user_id', $userId)
                        ->first();

                    if ($categoria && $categoria->type !== $tipo) {
                        $fail($tipo === 'income'
                            ? 'A categoria escolhida é de despesa — escolha uma categoria de receita.'
                            : 'A categoria escolhida é de receita — escolha uma categoria de despesa.');
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
            // De onde sai o dinheiro quando o disponível não cobre. Só é exigido
            // pelo FundingService (que responde 409 pedindo a escolha); aqui é
            // opcional para o caminho normal não precisar mandar nada.
            'funding_source' => ['nullable', Rule::in(FundingSource::TODAS)],
            'funding_investment_id' => [
                'nullable',
                'required_if:funding_source,'.FundingSource::RESGATE_INVESTIMENTO,
                Rule::exists('investments', 'id')->where('user_id', $userId),
            ],
            // TETO do que o usuário aprovou no modal de fonte. O valor do resgate é
            // recalculado no servidor (o disponível muda entre aprovar e gravar), e
            // sem este teto um lançamento que dormiu na fila offline podia resgatar
            // muito mais do que o número que a pessoa viu e confirmou.
            'funding_max_amount' => $this->regrasDeDinheiro(obrigatorio: false),
            'description' => ['nullable', 'string', 'max:255'],
            'date' => [
                'required',
                'date',
                'after_or_equal:2000-01-01',
                'before_or_equal:'.now()->addYears(10)->toDateString(),
            ],
        ];
    }

    /**
     * A criação aceita `type=transfer`; a EDIÇÃO não (UpdateTransactionRequest
     * desliga). Sem isto, mandar `type=transfer` num PUT validaria com as regras
     * de transferência e gravaria "transfer" na coluna `type` de uma linha comum —
     * um tipo que `Account::balance` não conhece.
     */
    protected function aceitaTransferencia(): bool
    {
        return true;
    }

    /**
     * Regras da transferência entre contas de CAIXA da família.
     *
     * Lista de PERMISSÃO (`whereIn checking/savings`), não de negação: cartão de
     * crédito não é caixa, e débito/Pix não têm saldo próprio — nenhum deles pode
     * ser origem nem destino. Um método espelho novo nasce recusado aqui sozinho.
     *
     * Sem categoria: mover dinheiro entre as próprias contas não é gasto de nada.
     */
    protected function regrasDeTransferencia(): array
    {
        $userId = $this->user()->ownerId();

        $contaDeCaixaDaFamilia = Rule::exists('accounts', 'id')->where(fn ($q) => $q
            ->where('user_id', $userId)
            ->whereIn('type', ['checking', 'savings']));

        return [
            'client_uuid' => ['nullable', 'uuid'],
            'type' => ['required', 'in:transfer'],
            'amount' => $this->regrasDeDinheiro(),
            // Origem: de onde o dinheiro sai. É a conta que passa pelo guard.
            'account_id' => ['required', $contaDeCaixaDaFamilia],
            // Destino: precisa ser OUTRA conta — transferir para si mesma criaria
            // duas linhas que se anulam e só sujariam o extrato.
            'to_account_id' => ['required', 'different:account_id', $contaDeCaixaDaFamilia],
            'made_by_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(function ($q) use ($userId) {
                    $q->where('id', $userId)->orWhere('account_owner_id', $userId);
                }),
            ],
            // Mesmo contrato da despesa: a saída passa pelo FundingService, que pode
            // responder 409 pedindo a fonte — e o reenvio traz estes três campos.
            'funding_source' => ['nullable', Rule::in(FundingSource::TODAS)],
            'funding_investment_id' => [
                'nullable',
                'required_if:funding_source,'.FundingSource::RESGATE_INVESTIMENTO,
                Rule::exists('investments', 'id')->where('user_id', $userId),
            ],
            'funding_max_amount' => $this->regrasDeDinheiro(obrigatorio: false),
            'description' => ['nullable', 'string', 'max:255'],
            'date' => [
                'required',
                'date',
                'after_or_equal:2000-01-01',
                'before_or_equal:'.now()->addYears(10)->toDateString(),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'type' => 'tipo',
            'to_account_id' => 'conta de destino',
            'amount' => 'valor',
            'account_id' => 'conta',
            'category_id' => 'categoria',
            'description' => 'descrição',
            'date' => 'data',
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Escolha o tipo: receita ou despesa.',
            'type.in' => 'Tipo de transação inválido.',
            'amount.required' => 'Informe o valor da transação.',
            'amount.numeric' => 'O valor deve ser um número. Use vírgula para os centavos, ex.: 25,90.',
            'amount.decimal' => 'Use no máximo duas casas decimais, ex.: 25,90.',
            'amount.min' => 'O valor mínimo é R$ 0,01.',
            'amount.max' => 'O valor informado é alto demais (o máximo é R$ 999.999.999.999,99).',
            'account_id.required' => 'Escolha a conta da transação.',
            'account_id.exists' => $this->input('type') === 'transfer'
                ? 'A conta de origem precisa ser uma conta corrente ou poupança sua. Cartões e Pix não têm saldo próprio para transferir.'
                : 'A conta escolhida não existe ou não pertence a você. Cartão de débito e Pix não têm saldo próprio — escolha a conta que eles usam.',
            'to_account_id.required' => 'Escolha para qual conta o dinheiro vai.',
            'to_account_id.different' => 'A conta de destino precisa ser diferente da de origem.',
            'to_account_id.exists' => 'A conta de destino precisa ser uma conta corrente ou poupança sua. Cartões e Pix não recebem transferência.',
            'category_id.exists' => 'A categoria escolhida não existe ou não pertence a você.',
            'description.max' => 'A descrição pode ter no máximo 255 caracteres.',
            'funding_source.in' => 'Escolha de onde sai o dinheiro é inválida.',
            'funding_investment_id.required_if' => 'Escolha de qual investimento resgatar.',
            'funding_investment_id.exists' => 'O investimento escolhido não existe ou não é da sua família.',
            'date.required' => 'Informe a data da transação.',
            'date.date' => 'Data inválida.',
            'date.after_or_equal' => 'A data deve ser a partir de 01/01/2000.',
            'date.before_or_equal' => 'A data está longe demais no futuro.',
        ];
    }
}
