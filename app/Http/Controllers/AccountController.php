<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Support\Brl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $accounts = Account::with(['linkedChecking', 'linkedSavings'])
            // Cada conta já vem sabendo se tem histórico: são 3 subconsultas
            // DENTRO da mesma query, e é isso que trava o tipo no modal de edição.
            // Perguntar `hasMoneyHistory()` card a card seriam 3 queries por conta
            // dentro do laço — exatamente o N+1 que o `preloadMoney` matou.
            ->withExists(['transactions', 'goalContributions', 'investmentContributions'])
            ->where('user_id', $request->user()->ownerId())
            ->orderBy('name')
            ->get();

        // Saldo/reservado/comprometido de todas as contas em 4 queries agregadas.
        // Sem isto, cada card da tela dispara ~6 consultas (2 SUM em `balance`,
        // 4 em `reserved`, 1 em `committed`): 30 contas = ~195 queries.
        Account::preloadMoney($accounts);

        return view('accounts.index', [
            'accounts' => $accounts,
            // A tela agora traz os formulários (um modal para criar, um por conta
            // para editar), então precisa dos mesmos dados de `formData()`. As
            // opções de vínculo (débito/Pix) saem das contas já carregadas — sem
            // query nova, e na mesma ordem alfabética.
            'types' => Account::TYPES,
            'banks' => Account::BANKS,
            'checkingAccounts' => $accounts->where('type', 'checking')->values(),
            'savingsAccounts' => $accounts->where('type', 'savings')->values(),
            'travados' => $accounts
                ->filter(fn (Account $conta) => $this->temHistorico($conta))
                ->pluck('id')
                ->all(),
            // Quem espelha cada conta (débito/Pix): com algum, o tipo dela também
            // fica travado no modal, e o aviso diz qual método depende dela.
            'espelhosPorConta' => $this->espelhosPorConta($accounts),
        ]);
    }

    /**
     * [id da conta => métodos espelho que tiram dinheiro dela], montado com as
     * contas que a listagem JÁ carregou. O vínculo só aponta para conta da mesma
     * família (`StoreAccountRequest::linkRule`), então todas estão na lista — e
     * perguntar `metodosQueEspelham()` card a card seria uma query por conta.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array<int, Collection<int, Account>>
     */
    private function espelhosPorConta(Collection $accounts): array
    {
        $porConta = [];

        foreach ($accounts as $metodo) {
            if (! $metodo->espelhaConta()) {
                continue;
            }

            $alvos = array_unique(array_filter([$metodo->checking_account_id, $metodo->savings_account_id]));

            foreach ($alvos as $alvo) {
                $porConta[(int) $alvo] ??= collect();
                $porConta[(int) $alvo]->push($metodo);
            }
        }

        return $porConta;
    }

    /**
     * Mesma pergunta do `Account::hasMoneyHistory()`, respondida com o que o
     * `withExists` do index já trouxe — para o laço dos cards não disparar
     * consulta nenhuma.
     */
    private function temHistorico(Account $conta): bool
    {
        return (float) $conta->initial_balance > 0
            || (bool) $conta->transactions_exists
            || (bool) $conta->goal_contributions_exists
            || (bool) $conta->investment_contributions_exists;
    }

    public function create(Request $request)
    {
        return view('accounts.create', $this->formData($request->user()->ownerId()));
    }

    public function store(StoreAccountRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->ownerId();

        $account = Account::create($data);

        // O modal da lista envia por AJAX (Accept: application/json) e recarrega
        // o conteúdo sozinho; a página cheia continua redirecionando. Os erros de
        // validação já saem em 422 JSON pelo próprio Form Request.
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'id' => $account->id,
                'message' => 'Conta criada com sucesso.',
                'redirect' => route('accounts.index'),
            ], 201);
        }

        return redirect()->route('accounts.index')
            ->with('status', 'Conta criada com sucesso.');
    }

    public function edit(Account $account)
    {
        $this->authorize('update', $account);

        return view('accounts.edit', $this->formData($account->user_id) + [
            'account' => $account,
        ]);
    }

    /**
     * Dados comuns dos formulários: tipos, bancos e as contas corrente/poupança
     * da família (opções de vínculo do cartão de débito).
     */
    private function formData(int $ownerId): array
    {
        return [
            'types' => Account::TYPES,
            'banks' => Account::BANKS,
            'checkingAccounts' => Account::where('user_id', $ownerId)->where('type', 'checking')->orderBy('name')->get(),
            'savingsAccounts' => Account::where('user_id', $ownerId)->where('type', 'savings')->orderBy('name')->get(),
        ];
    }

    public function update(UpdateAccountRequest $request, Account $account)
    {
        $this->authorize('update', $account);

        $dados = $request->validated();

        // Segunda linha de defesa (a 1ª é o UpdateAccountRequest): trocar a
        // CLASSE do tipo — caixa ↔ cartão — numa conta que já tem dinheiro faz
        // saldo desaparecer ou contar em dobro; trocar QUALQUER tipo de uma conta
        // que um débito/Pix espelha deixa o método lançando no lugar errado.
        if ($erro = $account->travaDeTipo($dados['type'] ?? null)) {
            if ($request->expectsJson()) {
                // Mesmo formato do 422 do Form Request: quem envia pelo modal lê
                // `errors` sem precisar saber de onde a recusa veio.
                return response()->json([
                    'message' => $erro,
                    'errors' => ['type' => [$erro]],
                ], 422);
            }

            return back()->withInput()->withErrors(['type' => $erro]);
        }

        // Nunca apagar o saldo inicial de uma conta que já carrega dinheiro:
        // `prepareForValidation` zera esse campo fora de corrente/poupança, e
        // NULL aqui seria irreversível (o valor original se perde).
        if ($account->hasMoneyHistory()
            && ($dados['initial_balance'] ?? null) === null
            && $account->initial_balance !== null) {
            unset($dados['initial_balance']);
        }

        $account->update($dados);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'id' => $account->id,
                'message' => 'Conta atualizada.',
                'redirect' => route('accounts.index'),
            ]);
        }

        return redirect()->route('accounts.index')
            ->with('status', 'Conta atualizada.');
    }

    public function destroy(Account $account)
    {
        $this->authorize('delete', $account);

        // A FK de transactions é cascadeOnDelete: excluir a conta apagaria
        // todo o histórico junto. Bloqueamos aqui para o usuário não perder
        // transações sem querer.
        if ($account->transactions()->exists()) {
            return back()->withErrors([
                'account' => 'Esta conta possui transações e não pode ser excluída. Exclua (ou mova) as transações dela primeiro.',
            ]);
        }

        // A FK de goal_contributions é restrictOnDelete: há dinheiro reservado/movimentado
        // em metas a partir desta conta. Bloqueamos para não quebrar o saldo das metas.
        if ($account->goalContributions()->exists()) {
            return back()->withErrors([
                'account' => 'Esta conta possui aportes ou resgates de metas e não pode ser excluída. Resgate o que está guardado por ela primeiro.',
            ]);
        }

        // Mesma trava para investimentos (FK também restrictOnDelete).
        if ($account->investmentContributions()->exists()) {
            return back()->withErrors([
                'account' => 'Esta conta possui aportes ou resgates de investimentos e não pode ser excluída. Resgate o que está aplicado por ela primeiro.',
            ]);
        }

        $account->delete();

        return redirect()->route('accounts.index')
            ->with('status', 'Conta removida.');
    }
}
