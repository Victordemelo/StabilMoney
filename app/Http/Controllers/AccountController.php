<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $accounts = Account::with(['linkedChecking', 'linkedSavings'])
            ->where('user_id', $request->user()->ownerId())
            ->orderBy('name')
            ->get();

        // Saldo/reservado/comprometido de todas as contas em 4 queries agregadas.
        // Sem isto, cada card da tela dispara ~6 consultas (2 SUM em `balance`,
        // 4 em `reserved`, 1 em `committed`): 30 contas = ~195 queries.
        Account::preloadMoney($accounts);

        return view('accounts.index', [
            'accounts' => $accounts,
        ]);
    }

    public function create(Request $request)
    {
        return view('accounts.create', $this->formData($request->user()->ownerId()));
    }

    public function store(StoreAccountRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->ownerId();

        Account::create($data);

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
        // saldo desaparecer ou contar em dobro. Mesma trava do `destroy`.
        if ($erro = $account->travaDeClasse($dados['type'] ?? null)) {
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
