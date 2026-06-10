<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    private function userId(): int
    {
        // Enquanto não há login, usa o usuário padrão (id 1). Ver CLAUDE.md.
        return Auth::id() ?? 1;
    }

    public function index()
    {
        $accounts = Account::where('user_id', $this->userId())->get();

        return view('accounts.index', compact('accounts'));
    }

    public function create()
    {
        return view('accounts.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['user_id'] = $this->userId();

        Account::create($data);

        return redirect()->route('accounts.index')
            ->with('status', 'Conta criada com sucesso.');
    }

    public function edit(Account $account)
    {
        $this->authorizeOwner($account);

        return view('accounts.edit', compact('account'));
    }

    public function update(Request $request, Account $account)
    {
        $this->authorizeOwner($account);

        $account->update($this->validateData($request));

        return redirect()->route('accounts.index')
            ->with('status', 'Conta atualizada.');
    }

    public function destroy(Account $account)
    {
        $this->authorizeOwner($account);

        $account->delete();

        return redirect()->route('accounts.index')
            ->with('status', 'Conta removida.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:wallet,bank,credit_card,savings,investment,other'],
            'initial_balance' => ['required', 'numeric'],
            'color' => ['nullable', 'string', 'max:30'],
            'icon' => ['nullable', 'string', 'max:30'],
        ]);
    }

    private function authorizeOwner(Account $account): void
    {
        abort_unless($account->user_id === $this->userId(), 403);
    }
}
