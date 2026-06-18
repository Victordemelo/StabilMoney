<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;

/**
 * Monta os dados da tela "Faturas / Despesas":
 * - um objeto por cartão de crédito da família (fatura atual, comprometido,
 *   limite disponível, % usado, vencimento e os itens do ciclo aberto);
 * - as despesas avulsas (contas/débito/Pix) do mês corrente;
 * - stats do topo, e os dados auxiliares do modal de lançamento.
 *
 * O ciclo/limite vêm dos accessors do Account (currentInvoice/committed/
 * availableLimit/dueDate) — uma fonte de verdade só.
 */
class FaturaService
{
    /** Monta tudo que a view de faturas precisa, escopado na família. */
    public function build(int $userId): array
    {
        $cards = $this->cards($userId);
        $accountExpenses = $this->accountExpenses($userId);

        $stats = [
            'totalFaturas' => round($cards->sum(fn ($c) => $c['currentInvoice']), 2),
            'numCartoes' => $cards->count(),
            'limiteDisponivel' => round($cards->sum(fn ($c) => $c['availableLimit']), 2),
        ];

        return [
            'cards' => $cards,
            'accountExpenses' => $accountExpenses,
            'stats' => $stats,
            // Todas as contas da família (inclui cartões) para o select do modal.
            'accounts' => $this->accounts($userId),
            // Categorias de despesa da família.
            'categories' => $this->categories($userId),
            // Titular + dependentes (seletor "quem fez a compra").
            'familyMembers' => $this->familyMembers($userId),
        ];
    }

    /** Um objeto por cartão de crédito, com a fatura do ciclo aberto e seus itens. */
    private function cards(int $userId): Collection
    {
        return Account::where('user_id', $userId)
            ->where('type', 'credit_card')
            ->orderBy('name')
            ->get()
            ->map(function (Account $card) {
                $cycle = $card->billingCycle();
                $items = $this->cycleItems($card, $cycle);

                $limit = (float) $card->credit_limit;
                $available = $card->availableLimit;
                $used = round($limit - $available, 2);
                $usedPct = $limit > 0 ? (int) min(100, round($used / $limit * 100)) : 0;

                // Fluent: a view acessa por -> (objeto) e os testes por []
                // (array) — Fluent suporta ambos (ArrayAccess + __get).
                return new Fluent([
                    'account' => $card,
                    'currentInvoice' => $card->currentInvoice,
                    'committed' => $card->committed,
                    'availableLimit' => $available,
                    'limitUsedPct' => $usedPct,
                    'dueDate' => $card->dueDate,
                    'items' => $items,
                ]);
            });
    }

    /**
     * Itens (transações) do cartão dentro do ciclo aberto — date em
     * (cycleStart, cycleEnd]. Cada item carrega category, madeBy e o "badge".
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $cycle
     */
    private function cycleItems(Account $card, ?array $cycle): Collection
    {
        if (! $cycle) {
            return collect();
        }

        [$start, $end] = $cycle;

        return Transaction::with(['category', 'madeBy'])
            ->where('account_id', $card->id)
            ->where('type', 'expense')
            ->whereDate('date', '>', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Despesas avulsas: type=expense de contas que NÃO são cartão, no mês
     * corrente. Carregam category, account e madeBy (e o badge via accessor).
     */
    private function accountExpenses(int $userId): Collection
    {
        $today = CarbonImmutable::today();

        return Transaction::with(['category', 'account', 'madeBy'])
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', 'expense')
            ->whereDate('date', '>=', $today->startOfMonth()->toDateString())
            ->whereDate('date', '<=', $today->endOfMonth()->toDateString())
            ->whereHas('account', fn ($q) => $q->where('type', '!=', 'credit_card'))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /** Contas da família (todas, inclusive cartões) para o select de método. */
    private function accounts(int $userId): Collection
    {
        return Account::where('user_id', $userId)
            ->orderBy('name')
            ->get(['id', 'name', 'icon', 'type', 'credit_limit', 'closing_day', 'due_day']);
    }

    /** Categorias de despesa da família. */
    private function categories(int $userId): Collection
    {
        return Category::where('user_id', $userId)
            ->where('type', 'expense')
            ->orderBy('name')
            ->get(['id', 'name', 'icon']);
    }

    /** Membros da família (titular + dependentes) para o seletor "quem fez a compra". */
    private function familyMembers(int $userId): Collection
    {
        return User::where('id', $userId)
            ->orWhere('account_owner_id', $userId)
            ->orderBy('name')
            ->get();
    }
}
