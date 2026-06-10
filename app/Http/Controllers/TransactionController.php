<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TransactionController extends Controller
{
    private function userId(): int
    {
        // Enquanto não há login, usa o usuário padrão (id 1). Ver CLAUDE.md.
        return Auth::id() ?? 1;
    }

    public function index(Request $request)
    {
        $transactions = Transaction::with(['account', 'category'])
            ->where('user_id', $this->userId())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(30);

        return view('transactions.index', compact('transactions'));
    }

    public function create()
    {
        return view('transactions.create', [
            'accounts' => Account::where('user_id', $this->userId())->get(),
            'categories' => Category::where('user_id', $this->userId())->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['user_id'] = $this->userId();

        Transaction::create($data);

        return redirect()->route('transactions.index')
            ->with('status', 'Transação registrada com sucesso.');
    }

    public function edit(Transaction $transaction)
    {
        $this->authorizeOwner($transaction);

        return view('transactions.edit', [
            'transaction' => $transaction,
            'accounts' => Account::where('user_id', $this->userId())->get(),
            'categories' => Category::where('user_id', $this->userId())->get(),
        ]);
    }

    public function update(Request $request, Transaction $transaction)
    {
        $this->authorizeOwner($transaction);

        $transaction->update($this->validateData($request));

        return redirect()->route('transactions.index')
            ->with('status', 'Transação atualizada.');
    }

    public function destroy(Transaction $transaction)
    {
        $this->authorizeOwner($transaction);

        $transaction->delete();

        return redirect()->route('transactions.index')
            ->with('status', 'Transação removida.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'in:income,expense'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'account_id' => ['required', 'exists:accounts,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'date' => ['required', 'date'],
        ]);
    }

    private function authorizeOwner(Transaction $transaction): void
    {
        abort_unless($transaction->user_id === $this->userId(), 403);
    }
}
