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
            'limiteDisponivel' => round($cards->sum(fn ($c) => max(0.0, (float) $c['availableLimit'])), 2),
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
            // Contas de caixa (corrente/poupança) que podem PAGAR uma fatura.
            'cashAccounts' => Account::where('user_id', $userId)
                ->whereIn('type', ['checking', 'savings'])
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'bank']),
            // Contas fixas mensais: competência do mês + as atrasadas.
            'contasFixas' => app(FixedBillService::class)->currentAndOverdue($userId),
            // Cadastro/edição de conta fixa (select de categoria de despesa).
            'fixedBills' => \App\Models\FixedBill::where('user_id', $userId)
                ->orderBy('due_day')->get(),
        ];
    }

    /**
     * Contas a vencer nos próximos $days dias (default 7) — para as notificações
     * da topbar: faturas de cartão em aberto (com vencimento) + recorrências não
     * pagas. Ordenadas por data de vencimento (mais perto primeiro).
     */
    public function upcomingDue(int $userId, int $days = 7): Collection
    {
        $today = CarbonImmutable::today();
        $limit = $today->addDays($days);
        $itens = collect();

        $cards = Account::where('user_id', $userId)->where('type', 'credit_card')->get();

        foreach ($cards as $card) {
            // (1) Fatura JÁ VENCIDA (ciclo fechado, não paga, vencimento no
            // passado). Sem este ramo a dívida do mês anterior sumia do sino no
            // dia em que o ciclo virava — o oposto de avisar que venceu.
            if ($atrasada = $card->overdueInvoice) {
                $itens->push(new Fluent([
                    'tipo' => 'fatura',
                    'nome' => 'Fatura ' . $card->name,
                    'valor' => $atrasada['valor'],
                    'due' => $atrasada['vencimento'],
                    'diasRestantes' => -$atrasada['diasAtraso'],
                    'vencida' => true,
                ]));
            }

            // (2) Fatura do ciclo aberto a vencer dentro da janela.
            $due = $card->dueDate;
            $devido = $card->openInvoiceDue;
            if (! $due || $devido <= 0.001 || $due->greaterThan($limit)) {
                continue;
            }
            $itens->push(new Fluent([
                'tipo' => 'fatura',
                'nome' => 'Fatura ' . $card->name,
                'valor' => $devido,
                'due' => $due,
                'diasRestantes' => (int) $today->diffInDays($due, false),
                'vencida' => false,
            ]));
        }

        // (3) Contas fixas mensais: a competência do mês e todas as atrasadas.
        foreach (app(FixedBillService::class)->currentAndOverdue($userId) as $ocorrencia) {
            if ($ocorrencia['paga'] || $ocorrencia['vencimento']->greaterThan($limit)) {
                continue;
            }
            $itens->push(new Fluent([
                'tipo' => 'conta_fixa',
                'nome' => $ocorrencia['bill']->name,
                'valor' => $ocorrencia['valor'],
                'due' => $ocorrencia['vencimento'],
                'diasRestantes' => $ocorrencia['diasRestantes'],
                'vencida' => $ocorrencia['vencida'],
            ]));
        }

        // (4) Recorrências legadas de cartão (em aberto, data <= limite).
        $rec = Transaction::with('account')
            ->where('user_id', $userId)
            ->where('recurring', true)
            ->whereNull('paid_at')
            ->whereDate('date', '<=', $limit->toDateString())
            ->get();
        foreach ($rec as $t) {
            $due = CarbonImmutable::parse($t->date);
            $itens->push(new Fluent([
                'tipo' => 'recorrente',
                'nome' => $t->description ?: 'Recorrência',
                'valor' => (float) $t->amount,
                'due' => $due,
                'diasRestantes' => (int) $today->diffInDays($due, false),
                'vencida' => $due->lessThan($today),
            ]));
        }

        // Vencidas primeiro (mais antiga no topo), depois as a vencer.
        return $itens
            ->sortBy(fn ($i) => [$i['vencida'] ? 0 : 1, $i['due']->timestamp])
            ->values();
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

                // Valor EM ABERTO (a pagar) e estado da fatura do ciclo.
                $devido = $card->openInvoiceDue;
                $temFatura = $card->currentInvoice > 0.001;

                // Fluent: a view acessa por -> (objeto) e os testes por []
                // (array) — Fluent suporta ambos (ArrayAccess + __get).
                return new Fluent([
                    'account' => $card,
                    'currentInvoice' => $card->currentInvoice,
                    'invoiceDue' => $devido,          // o que falta pagar do ciclo
                    'isPaid' => $temFatura && $devido <= 0.001,
                    'canPay' => $devido > 0.001,
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

    /**
     * Métodos de pagamento para o select do modal de lançar. Cartão de débito
     * aparece com o rótulo dele mas submete a conta corrente/poupança que ele
     * espelha (Account::paymentOptions).
     */
    private function accounts(int $userId): Collection
    {
        return Account::paymentOptions($userId);
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
        return User::familyOf($userId)->get();
    }
}
