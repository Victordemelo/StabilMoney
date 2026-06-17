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

    /** Rótulos PT-BR dos tipos de conta (usados nas views). */
    public const TYPES = [
        'wallet' => 'Carteira',
        'bank' => 'Conta bancária',
        'credit_card' => 'Cartão de crédito',
        'savings' => 'Poupança',
        'investment' => 'Investimento',
        'other' => 'Outro',
    ];

    public function index(Request $request)
    {
        $accounts = Account::where('user_id', $request->user()->ownerId())
            ->orderBy('name')
            ->get();

        return view('accounts.index', [
            'accounts' => $accounts,
            'types' => self::TYPES,
        ]);
    }

    public function create()
    {
        return view('accounts.create', ['types' => self::TYPES]);
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

        return view('accounts.edit', [
            'account' => $account,
            'types' => self::TYPES,
        ]);
    }

    public function update(UpdateAccountRequest $request, Account $account)
    {
        $this->authorize('update', $account);

        $account->update($request->validated());

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

        $account->delete();

        return redirect()->route('accounts.index')
            ->with('status', 'Conta removida.');
    }
}
