<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $userId = $request->user()->ownerId();

        // Contas do usuário (usadas no select de filtro)
        $accounts = Account::where('user_id', $userId)->orderBy('name')->get();

        $query = Transaction::with(['account', 'category', 'madeBy'])
            ->where('user_id', $userId);

        // Filtros opcionais via GET — sempre restritos aos dados do próprio usuário
        $type = $request->query('type');
        if (in_array($type, ['income', 'expense'], true)) {
            $query->where('type', $type);
        }

        $accountId = (int) $request->query('account');
        if ($accountId && $accounts->contains('id', $accountId)) {
            $query->where('account_id', $accountId);
        }

        $transactions = $query
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        // Exibe "quem fez a compra" só quando a família tem dependentes.
        $showAuthor = User::where('account_owner_id', $userId)->exists();

        return view('transactions.index', compact('transactions', 'accounts', 'showAuthor'));
    }

    public function create(Request $request)
    {
        $userId = $request->user()->ownerId();

        return view('transactions.create', [
            'accounts' => Account::where('user_id', $userId)->orderBy('name')->get(),
            'categories' => Category::where('user_id', $userId)
                ->orderBy('type')
                ->orderBy('name')
                ->get(),
            'familyMembers' => $this->familyMembers($userId),
        ]);
    }

    public function store(StoreTransactionRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->ownerId();
        // Autor do lançamento: o informado no form, ou o usuário atual por padrão.
        $data['made_by_user_id'] = $data['made_by_user_id'] ?? $request->user()->id;

        Transaction::create($data);

        return redirect()->route('transactions.index')
            ->with('status', 'Transação registrada com sucesso.');
    }

    public function edit(Request $request, Transaction $transaction)
    {
        $this->authorize('update', $transaction);

        $userId = $request->user()->ownerId();

        return view('transactions.edit', [
            'transaction' => $transaction,
            'accounts' => Account::where('user_id', $userId)->orderBy('name')->get(),
            'categories' => Category::where('user_id', $userId)
                ->orderBy('type')
                ->orderBy('name')
                ->get(),
            'familyMembers' => $this->familyMembers($userId),
        ]);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction)
    {
        $this->authorize('update', $transaction);

        $data = $request->validated();
        $data['made_by_user_id'] = $data['made_by_user_id'] ?? $request->user()->id;
        $transaction->update($data);

        return redirect()->route('transactions.index')
            ->with('status', 'Transação atualizada.');
    }

    public function destroy(Transaction $transaction)
    {
        $this->authorize('delete', $transaction);

        $transaction->delete();

        return redirect()->route('transactions.index')
            ->with('status', 'Transação removida.');
    }

    /** Membros da família (titular + dependentes) para o seletor "quem fez a compra". */
    private function familyMembers(int $ownerId)
    {
        return User::where('id', $ownerId)
            ->orWhere('account_owner_id', $ownerId)
            ->orderBy('name')
            ->get();
    }
}
