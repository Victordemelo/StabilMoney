<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Agrega os dados do dashboard com poucas queries (sem N+1):
 * - payload JSON do contrato consumido por resources/js/sm/dashboard.js
 *   (períodos semana/mês/ano, sparklines, gastos por categoria, hasData);
 * - dados server-rendered para o Blade (stats do mês, transações recentes,
 *   contas com saldo calculado e saldo total).
 */
class DashboardService
{
    /** Paleta do design para categorias sem cor própria (cicla na ordem). */
    private const PALETTE = ['#0F6B47', '#1FA06E', '#59C497', '#18B6BE', '#F0A93B', '#9FB0A7'];

    /** Cor fixa para despesas sem categoria. */
    private const NO_CATEGORY_COLOR = '#9FB0A7';

    /** Rótulos PT-BR dos tipos de conta. */
    private const ACCOUNT_TYPES = [
        'wallet' => 'Carteira',
        'bank' => 'Banco',
        'credit_card' => 'Cartão de crédito',
        'savings' => 'Poupança',
        'investment' => 'Investimento',
        'other' => 'Outros',
    ];

    /** Monta tudo que a view do dashboard precisa, escopado no usuário. */
    public function build(int $userId): array
    {
        $today = CarbonImmutable::today();

        // ----- Contas + saldos (uma única query agregada para todos os deltas) -----
        $accounts = Account::where('user_id', $userId)->orderBy('id')->get();

        $deltas = Transaction::where('user_id', $userId)
            ->selectRaw("account_id, SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END) AS delta")
            ->groupBy('account_id')
            ->pluck('delta', 'account_id');

        foreach ($accounts as $account) {
            $account->current_balance = round((float) $account->initial_balance + (float) ($deltas[$account->id] ?? 0), 2);
            $account->type_label = self::ACCOUNT_TYPES[$account->type] ?? Str::ucfirst($account->type);
        }

        // Cartão de débito ESPELHA as contas vinculadas (corrente + poupança) só
        // para exibição — não tem saldo próprio. (Fica fora do patrimônio abaixo,
        // senão somaria duas vezes o mesmo dinheiro.)
        $byId = $accounts->keyBy('id');
        foreach ($accounts as $account) {
            if ($account->type === 'debit_card') {
                $c = $account->checking_account_id ? (float) ($byId[$account->checking_account_id]->current_balance ?? 0) : 0;
                $s = $account->savings_account_id ? (float) ($byId[$account->savings_account_id]->current_balance ?? 0) : 0;
                $account->current_balance = round($c + $s, 2);
            }
        }

        // Crédito e débito NÃO são caixa próprio: ficam fora do saldo/patrimônio
        // (stat "saldo", trend, sparkline). Continuam na LISTA de contas (exibição).
        $excludedTypes = ['credit_card', 'debit_card'];
        $cardIds = $accounts->whereIn('type', $excludedTypes)->pluck('id')->all();
        $nonCardAccounts = $accounts->whereNotIn('type', $excludedTypes);

        $initialTotal = round((float) $nonCardAccounts->sum(fn ($a) => (float) $a->initial_balance), 2);
        $totalBalance = round((float) $nonCardAccounts->sum('current_balance'), 2);

        $hasData = Transaction::where('user_id', $userId)->exists();

        // ----- Semana atual (Seg..Dom) -----
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->addDays(6);
        $weekDaily = $this->dailySums($userId, $weekStart, $weekEnd);

        $weekIncome = [];
        $weekExpense = [];
        for ($i = 0; $i < 7; $i++) {
            $key = $weekStart->addDays($i)->toDateString();
            $weekIncome[] = $weekDaily[$key]['income'] ?? 0.0;
            $weekExpense[] = $weekDaily[$key]['expense'] ?? 0.0;
        }
        $weekPrev = $this->totals($userId, $weekStart->subWeek(), $weekStart->subDay());

        // ----- Mês atual (buckets Sem 1..Sem N, dias 1-7, 8-14, ...) -----
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth();
        $bucketCount = (int) ceil($today->daysInMonth / 7);
        $monthDaily = $this->dailySums($userId, $monthStart, $monthEnd);

        $monthIncome = array_fill(0, $bucketCount, 0.0);
        $monthExpense = array_fill(0, $bucketCount, 0.0);
        foreach ($monthDaily as $date => $sums) {
            $idx = intdiv(((int) substr($date, 8, 2)) - 1, 7);
            $monthIncome[$idx] = round($monthIncome[$idx] + ($sums['income'] ?? 0.0), 2);
            $monthExpense[$idx] = round($monthExpense[$idx] + ($sums['expense'] ?? 0.0), 2);
        }
        $monthLabels = array_map(fn ($i) => 'Sem ' . ($i + 1), range(0, $bucketCount - 1));
        $monthPrev = $this->totals($userId, $monthStart->subMonth(), $monthStart->subDay());

        // ----- Ano atual (Jan..Dez, agregado por mês direto no SQL) -----
        $yearStart = $today->startOfYear();
        $yearEnd = $today->endOfYear();

        // MONTH() é específico do MySQL; no sqlite (usado nos testes) o
        // equivalente é strftime('%m', ...). Mantemos o agregado no SQL
        // escolhendo a expressão conforme o driver da conexão.
        $monthExpr = Transaction::query()->getConnection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%m', date) AS INTEGER)"
            : 'MONTH(date)';

        $yearRows = Transaction::where('user_id', $userId)
            ->whereBetween('date', [$yearStart->toDateString(), $yearEnd->toDateString()])
            ->selectRaw("{$monthExpr} AS month_num, type, SUM(amount) AS total")
            ->groupBy('month_num', 'type')
            ->get();

        $yearIncome = array_fill(0, 12, 0.0);
        $yearExpense = array_fill(0, 12, 0.0);
        foreach ($yearRows as $row) {
            $idx = ((int) $row->month_num) - 1;
            if ($row->type === 'income') {
                $yearIncome[$idx] = round((float) $row->total, 2);
            } else {
                $yearExpense[$idx] = round((float) $row->total, 2);
            }
        }
        $yearPrev = $this->totals($userId, $yearStart->subYear(), $yearStart->subDay());

        // ----- Períodos no formato do contrato -----
        $periods = [
            'semana' => $this->period(
                'Esta semana',
                ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
                $weekIncome,
                $weekExpense,
                $weekPrev,
                $userId, $hasData, $totalBalance, $initialTotal, $weekStart->subDay(), $cardIds,
            ),
            'mes' => $this->period(
                Str::ucfirst($today->translatedFormat('F')) . ' de ' . $today->year,
                $monthLabels,
                $monthIncome,
                $monthExpense,
                $monthPrev,
                $userId, $hasData, $totalBalance, $initialTotal, $monthStart->subDay(), $cardIds,
            ),
            'ano' => $this->period(
                (string) $today->year,
                ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'],
                $yearIncome,
                $yearExpense,
                $yearPrev,
                $userId, $hasData, $totalBalance, $initialTotal, $yearStart->subDay(), $cardIds,
            ),
        ];

        // ----- Sparklines (últimos 7 dias) -----
        $sparks = $this->sparks($userId, $hasData, $initialTotal, $today, $cardIds);

        // ----- Gastos do mês por categoria (top 5 + "Outros") -----
        $cats = $this->categoryBreakdown($userId, $monthStart, $monthEnd);

        // ----- Transações recentes (server-rendered) -----
        $recent = Transaction::with(['account', 'category', 'madeBy'])
            ->where('user_id', $userId)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        foreach ($recent as $transaction) {
            $transaction->date_human = $this->humanDate(CarbonImmutable::parse($transaction->date), $today);
        }

        $payload = [
            'periods' => $periods,
            'sparks' => $sparks,
            'cats' => $cats,
            'hasData' => $hasData,
        ];

        return [
            'payload' => $payload,
            'payloadJson' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'hasData' => $hasData,
            'cats' => $cats,
            'monthStats' => $periods['mes']['stats'],
            'monthTrends' => $periods['mes']['trends'],
            'recent' => $recent,
            'accounts' => $accounts,
            'totalBalance' => $totalBalance,
            // Exibe "quem fez a compra" nas recentes só quando a família tem dependentes.
            'showAuthor' => User::where('account_owner_id', $userId)->exists(),
        ];
    }

    /** Monta um período do contrato (sub, labels, séries, stats e trends). */
    private function period(
        string $sub,
        array $labels,
        array $income,
        array $expense,
        array $prev,
        int $userId,
        bool $hasData,
        float $totalBalance,
        float $initialTotal,
        CarbonImmutable $prevPeriodEnd,
        array $cardIds = [],
    ): array {
        $incomeTotal = round(array_sum($income), 2);
        $expenseTotal = round(array_sum($expense), 2);

        return [
            'sub' => $sub,
            'labels' => $labels,
            'receitas' => $income,
            'despesas' => $expense,
            'stats' => [
                // Saldo total é o atual (contas que são caixa, sem cartões), independe do período
                'saldo' => $totalBalance,
                'receitas' => $incomeTotal,
                'despesas' => $expenseTotal,
                'economia' => round($incomeTotal - $expenseTotal, 2),
            ],
            'trends' => $this->trends($userId, $hasData, $totalBalance, $initialTotal, $prevPeriodEnd, $incomeTotal, $expenseTotal, $prev, $cardIds),
        ];
    }

    /**
     * Variação % vs período anterior equivalente. null quando a base anterior
     * é zero (sem dados para comparar) — o front renderiza "—" neutro.
     */
    private function trends(
        int $userId,
        bool $hasData,
        float $totalBalance,
        float $initialTotal,
        CarbonImmutable $prevPeriodEnd,
        float $income,
        float $expense,
        array $prev,
        array $cardIds = [],
    ): array {
        if (! $hasData) {
            return ['saldo' => null, 'receitas' => null, 'despesas' => null, 'economia' => null];
        }

        // Saldo ao fim do período anterior = saldos iniciais (sem cartões) + transações
        // até lá (também sem cartões — cartão não é caixa).
        $previousBalance = round($initialTotal + $this->signedSumUntil($userId, $prevPeriodEnd, $cardIds), 2);

        return [
            'saldo' => $this->pctChange($totalBalance, $previousBalance),
            'receitas' => $this->pctChange($income, $prev['income']),
            'despesas' => $this->pctChange($expense, $prev['expense']),
            'economia' => $this->pctChange(
                round($income - $expense, 2),
                round($prev['income'] - $prev['expense'], 2),
            ),
        ];
    }

    /** Variação percentual com base anterior; null quando a base é zero. */
    public function pctChange(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.005) {
            return null; // divisão por zero => sem comparação
        }

        return round(($current - $previous) / abs($previous) * 100, 1);
    }

    /**
     * Sparklines dos últimos 7 dias:
     * - saldo: acumulado dia a dia (saldos iniciais + transações até cada dia);
     * - receitas/despesas: somas diárias;
     * - economia: (receitas - despesas) diárias acumuladas na janela.
     * Arrays vazios quando o usuário ainda não tem transações.
     */
    private function sparks(int $userId, bool $hasData, float $initialTotal, CarbonImmutable $today, array $cardIds = []): array
    {
        $sparks = ['saldo' => [], 'receitas' => [], 'despesas' => [], 'economia' => []];
        if (! $hasData) {
            return $sparks;
        }

        $start = $today->subDays(6);
        // Cashflow (receitas/despesas/economia) inclui cartões — é gasto real.
        $daily = $this->dailySums($userId, $start, $today);
        $saved = 0.0;

        // Saldo (patrimônio) NÃO inclui cartões: deixa o saldoSpark montar a própria
        // série filtrada (passando $daily=null e os ids de cartão a excluir).
        $sparks['saldo'] = $this->saldoSpark($userId, $initialTotal, $today, null, $cardIds);

        for ($i = 0; $i < 7; $i++) {
            $key = $start->addDays($i)->toDateString();
            $income = $daily[$key]['income'] ?? 0.0;
            $expense = $daily[$key]['expense'] ?? 0.0;
            $saved = round($saved + $income - $expense, 2);

            $sparks['receitas'][] = $income;
            $sparks['despesas'][] = $expense;
            $sparks['economia'][] = $saved;
        }

        return $sparks;
    }

    /**
     * Saldo acumulado dia a dia dos últimos 7 dias (hoje incluso).
     * Compartilhado entre o sparkline "saldo" do dashboard e o card
     * "Patrimônio total" da sidebar (SidebarService) — uma lógica só.
     * $daily opcional evita repetir a query quando o chamador já tem dailySums().
     */
    public function saldoSpark(int $userId, float $initialTotal, CarbonImmutable $today, ?array $daily = null, array $excludeAccountIds = []): array
    {
        $start = $today->subDays(6);
        $daily ??= $this->dailySums($userId, $start, $today, $excludeAccountIds);
        $balance = round($initialTotal + $this->signedSumUntil($userId, $start->subDay(), $excludeAccountIds), 2);
        $points = [];

        for ($i = 0; $i < 7; $i++) {
            $key = $start->addDays($i)->toDateString();
            $balance = round(
                $balance + ($daily[$key]['income'] ?? 0.0) - ($daily[$key]['expense'] ?? 0.0),
                2,
            );
            $points[] = $balance;
        }

        return $points;
    }

    /**
     * Despesas do mês agrupadas por categoria (decrescente). Mais de 6
     * categorias => 5 maiores + "Outros" agregado. Sem categoria entra como
     * "Sem categoria". Cor: a da categoria ou a paleta do design (ciclando).
     */
    private function categoryBreakdown(int $userId, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $rows = Transaction::query()
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', 'expense')
            ->whereBetween('transactions.date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->groupBy('transactions.category_id', 'categories.name', 'categories.color')
            ->orderByDesc('total')
            ->selectRaw('categories.name AS cat_name, categories.color AS cat_color, SUM(transactions.amount) AS total')
            ->get();

        $items = $rows->map(fn ($row) => [
            'name' => $row->cat_name ?? 'Sem categoria',
            'value' => round((float) $row->total, 2),
            'color' => $row->cat_name === null ? self::NO_CATEGORY_COLOR : ($row->cat_color ?: null),
        ])->values();

        if ($items->count() > 6) {
            $rest = round($items->slice(5)->sum('value'), 2);
            $items = $items->take(5)->push(['name' => 'Outros', 'value' => $rest, 'color' => null]);
        }

        return $items->values()->map(function (array $cat, int $i) {
            $cat['color'] = $cat['color'] ?? self::PALETTE[$i % count(self::PALETTE)];

            return $cat;
        })->all();
    }

    /**
     * Somas diárias por tipo no intervalo: ['Y-m-d' => ['income' => x, 'expense' => y]].
     * $excludeAccountIds permite ignorar contas (ex.: cartões de crédito, que
     * não entram no cálculo de saldo/patrimônio).
     */
    private function dailySums(int $userId, CarbonImmutable $from, CarbonImmutable $to, array $excludeAccountIds = []): array
    {
        $rows = Transaction::where('user_id', $userId)
            ->when($excludeAccountIds, fn ($q) => $q->whereNotIn('account_id', $excludeAccountIds))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('date, type, SUM(amount) AS total')
            ->groupBy('date', 'type')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->date->toDateString()][$row->type] = round((float) $row->total, 2);
        }

        return $out;
    }

    /** Totais de receitas e despesas do intervalo (uma query). */
    private function totals(int $userId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = Transaction::where('user_id', $userId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type = 'income' THEN amount END), 0) AS income_total, " .
                "COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expense_total",
            )
            ->first();

        return [
            'income' => round((float) ($row->income_total ?? 0), 2),
            'expense' => round((float) ($row->expense_total ?? 0), 2),
        ];
    }

    /**
     * Soma com sinal (receita +, despesa −) de tudo até a data, inclusive.
     * $excludeAccountIds ignora contas no cálculo (cartões de crédito ficam
     * fora do saldo/patrimônio).
     */
    public function signedSumUntil(int $userId, CarbonImmutable $until, array $excludeAccountIds = []): float
    {
        $value = Transaction::where('user_id', $userId)
            ->when($excludeAccountIds, fn ($q) => $q->whereNotIn('account_id', $excludeAccountIds))
            ->where('date', '<=', $until->toDateString())
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) AS signed_total")
            ->value('signed_total');

        return round((float) $value, 2);
    }

    /** Data humanizada em PT-BR: Hoje / Ontem / "07 jun". */
    private function humanDate(CarbonImmutable $date, CarbonImmutable $today): string
    {
        if ($date->isSameDay($today)) {
            return 'Hoje';
        }
        if ($date->isSameDay($today->subDay())) {
            return 'Ontem';
        }

        return mb_strtolower($date->translatedFormat('d M'));
    }
}
