<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        // Enquanto não há login, usa o usuário padrão (id 1). Ver CLAUDE.md.
        $userId = Auth::id() ?? 1;

        $accounts = Account::where('user_id', $userId)->get();
        $totalBalance = $accounts->sum(fn (Account $a) => $a->balance);

        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $monthIncome = Transaction::where('user_id', $userId)
            ->where('type', 'income')
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $monthExpense = Transaction::where('user_id', $userId)
            ->where('type', 'expense')
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $recent = Transaction::with(['account', 'category'])
            ->where('user_id', $userId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('dashboard', [
            'accounts' => $accounts,
            'totalBalance' => $totalBalance,
            'monthIncome' => (float) $monthIncome,
            'monthExpense' => (float) $monthExpense,
            'monthBalance' => (float) $monthIncome - (float) $monthExpense,
            'recent' => $recent,
        ]);
    }
}
