<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
    /**
     * Período que o dashboard abre selecionado ("semana" | "mes" | "ano").
     * Preferência do usuário: abrir na SEMANA. Vale para o server-render dos
     * stat cards e para o botão ativo do segmented.
     */
    public const DEFAULT_PERIOD = 'semana';

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

        // Reservado POR CONTA (metas + investimentos), em 2 queries agregadas — não uma
        // por conta. A lista precisa disto porque exibe o DISPONÍVEL, não o saldo cru:
        // antes o dashboard mostrava o cru e /accounts mostrava o disponível, então a
        // mesma conta aparecia com dois valores diferentes, e a soma da lista não batia
        // com o headline do próprio card.
        $reservados = $this->reservedByAccount($userId);

        foreach ($accounts as $account) {
            $bruto = round((float) $account->initial_balance + (float) ($deltas[$account->id] ?? 0), 2);
            // `gross_balance` = patrimônio (o dinheiro que está lá).
            // `current_balance` = o que a LISTA exibe: disponível, já sem as reservas.
            // Os dois precisam existir separados: o total do patrimônio soma o bruto e
            // desconta as reservas UMA vez, no fim. Misturar os dois descontava duas
            // vezes (1.000 − 300 − 300 = 400 no card que devia mostrar 700).
            $account->gross_balance = $bruto;
            $account->current_balance = round($bruto - (float) ($reservados[$account->id] ?? 0), 2);
            // `typeLabel()` do model: a constante local ACCOUNT_TYPES é legado e não tem
            // `checking` nem `debit_card`, então vazava "Checking"/"Debit_card" na tela.
            $account->type_label = $account->typeLabel();
        }

        // Cartão de débito ESPELHA as contas vinculadas (corrente + poupança) só
        // para exibição — não tem saldo próprio. (Fica fora do patrimônio abaixo,
        // senão somaria duas vezes o mesmo dinheiro.)
        $byId = $accounts->keyBy('id');
        foreach ($accounts as $account) {
            // Métodos ESPELHO (débito e Pix) exibem o saldo das contas vinculadas.
            // No Pix só uma existe; a outra entra como 0.
            if (in_array($account->type, ['debit_card', 'pix'], true)) {
                $c = $account->checking_account_id ? (float) ($byId[$account->checking_account_id]->current_balance ?? 0) : 0;
                $s = $account->savings_account_id ? (float) ($byId[$account->savings_account_id]->current_balance ?? 0) : 0;
                $account->current_balance = round($c + $s, 2);
            }
        }

        // Crédito e débito NÃO são caixa próprio: ficam fora do saldo/patrimônio
        // (stat "saldo", trend, sparkline). Continuam na LISTA de contas (exibição).
        // Fora do patrimônio: crédito (não é caixa) e os métodos espelho (débito e
        // Pix), que mostram dinheiro que JÁ está sendo contado na conta vinculada.
        // Deixar o Pix de fora desta lista somaria a mesma conta corrente duas vezes.
        $excludedTypes = ['credit_card', 'debit_card', 'pix'];
        $cardIds = $accounts->whereIn('type', $excludedTypes)->pluck('id')->all();
        // Só CARTÃO DE CRÉDITO: nele um `income` é estorno de compra, não receita.
        // (O de débito não recebe lançamento — o select manda a conta que ele espelha.)
        $creditCardIds = $accounts->where('type', 'credit_card')->pluck('id')->all();
        $nonCardAccounts = $accounts->whereNotIn('type', $excludedTypes);

        $initialTotal = round((float) $nonCardAccounts->sum(fn ($a) => (float) $a->initial_balance), 2);
        // Patrimônio soma o BRUTO: a subtração das reservas acontece uma única vez,
        // logo abaixo, em `$saldoDisponivel`.
        $totalBalance = round((float) $nonCardAccounts->sum('gross_balance'), 2);

        // O card do topo mostra o SALDO DISPONÍVEL: o que está nas contas menos
        // o que já está comprometido em metas e investimentos (modelo cofrinho —
        // o dinheiro continua na conta, mas não é para gastar).
        $reservado = $this->reservedTotals($userId);
        $saldoDisponivel = round($totalBalance - $reservado['total'], 2);

        $hasData = Transaction::where('user_id', $userId)->exists();

        // ----- Semana atual (Seg..Dom) -----
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->addDays(6);
        $weekDaily = $this->dailySums($userId, $weekStart, $weekEnd, refundAccountIds: $creditCardIds);

        $weekIncome = [];
        $weekExpense = [];
        for ($i = 0; $i < 7; $i++) {
            $key = $weekStart->addDays($i)->toDateString();
            $weekIncome[] = $weekDaily[$key]['income'] ?? 0.0;
            $weekExpense[] = $weekDaily[$key]['expense'] ?? 0.0;
        }
        $weekPrev = $this->totals($userId, $weekStart->subWeek(), $weekStart->subDay(), $creditCardIds);

        // ----- Mês atual (buckets Sem 1..Sem N, dias 1-7, 8-14, ...) -----
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth();
        $bucketCount = (int) ceil($today->daysInMonth / 7);
        $monthDaily = $this->dailySums($userId, $monthStart, $monthEnd, refundAccountIds: $creditCardIds);

        $monthIncome = array_fill(0, $bucketCount, 0.0);
        $monthExpense = array_fill(0, $bucketCount, 0.0);
        foreach ($monthDaily as $date => $sums) {
            $idx = intdiv(((int) substr($date, 8, 2)) - 1, 7);
            $monthIncome[$idx] = round($monthIncome[$idx] + ($sums['income'] ?? 0.0), 2);
            $monthExpense[$idx] = round($monthExpense[$idx] + ($sums['expense'] ?? 0.0), 2);
        }
        $monthLabels = array_map(fn ($i) => 'Sem ' . ($i + 1), range(0, $bucketCount - 1));
        $monthPrev = $this->totals($userId, $monthStart->subMonth(), $monthStart->subDay(), $creditCardIds);

        // ----- Ano atual (Jan..Dez, agregado por mês direto no SQL) -----
        $yearStart = $today->startOfYear();
        $yearEnd = $today->endOfYear();

        // MONTH() é específico do MySQL; no sqlite (usado nos testes) o
        // equivalente é strftime('%m', ...). Mantemos o agregado no SQL
        // escolhendo a expressão conforme o driver da conexão.
        $monthExpr = Transaction::query()->getConnection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%m', date) AS INTEGER)"
            : 'MONTH(date)';

        // A soma sai separada em três colunas para que o ESTORNO no cartão (income numa
        // conta de crédito) abata a despesa do mês em vez de virar receita — a mesma
        // regra de `dailySums`/`totals`. Sem isto, devolver uma compra de R$ 1.000
        // aparecia como R$ 1.000 de "receita" no gráfico do ano.
        $emCartao = $this->emCartaoSql($creditCardIds);
        $yearRows = Transaction::where('user_id', $userId)
            // Quitação de fatura não é gasto novo — ver `settles_account_id`.
            ->whereNull('settles_account_id')
            ->whereBetween('date', [$yearStart->toDateString(), $yearEnd->toDateString()])
            ->selectRaw(
                "{$monthExpr} AS month_num, " .
                "COALESCE(SUM(CASE WHEN type = 'income' AND NOT ({$emCartao}) THEN amount END), 0) AS income_total, " .
                "COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expense_total, " .
                "COALESCE(SUM(CASE WHEN type = 'income' AND {$emCartao} THEN amount END), 0) AS refund_total",
            )
            ->groupBy('month_num')
            ->get();

        $yearIncome = array_fill(0, 12, 0.0);
        $yearExpense = array_fill(0, 12, 0.0);
        foreach ($yearRows as $row) {
            $idx = ((int) $row->month_num) - 1;
            $yearIncome[$idx] = round((float) $row->income_total, 2);
            // Piso 0: estorno maior que as compras do mês significa gasto zero.
            $yearExpense[$idx] = max(0.0, round((float) $row->expense_total - (float) $row->refund_total, 2));
        }
        $yearPrev = $this->totals($userId, $yearStart->subYear(), $yearStart->subDay(), $creditCardIds);

        // ----- Períodos no formato do contrato -----
        $periods = [
            'semana' => $this->period(
                'Esta semana',
                ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
                $weekIncome,
                $weekExpense,
                $weekPrev,
                $userId, $hasData, $saldoDisponivel, $initialTotal, $weekStart->subDay(), $cardIds,
            ),
            'mes' => $this->period(
                Str::ucfirst($today->translatedFormat('F')) . ' de ' . $today->year,
                $monthLabels,
                $monthIncome,
                $monthExpense,
                $monthPrev,
                $userId, $hasData, $saldoDisponivel, $initialTotal, $monthStart->subDay(), $cardIds,
            ),
            'ano' => $this->period(
                (string) $today->year,
                ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'],
                $yearIncome,
                $yearExpense,
                $yearPrev,
                $userId, $hasData, $saldoDisponivel, $initialTotal, $yearStart->subDay(), $cardIds,
            ),
        ];

        // ----- Sparklines (últimos 7 dias) -----
        $sparks = $this->sparks($userId, $hasData, $initialTotal, $today, $cardIds, $creditCardIds);

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
            // As flags HEX são as mesmas do @json do Blade e existem porque este JSON é
            // embutido dentro de <script>: sem JSON_HEX_TAG, um nome de categoria igual a
            // `<!--<script>` leva o parser HTML ao "double escaped state" e engole o resto
            // da página (o dashboard some). `</script>` já era neutralizado pelo escape
            // de "/" que o json_encode faz por padrão.
            'payloadJson' => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ),
            'hasData' => $hasData,
            'cats' => $cats,
            // Período que abre selecionado no dashboard (server-render + botão ativo).
            'defaultPeriod' => self::DEFAULT_PERIOD,
            'initialStats' => $periods[self::DEFAULT_PERIOD]['stats'],
            'initialTrends' => $periods[self::DEFAULT_PERIOD]['trends'],
            'initialSub' => $periods[self::DEFAULT_PERIOD]['sub'],
            // Compat: alguns pontos ainda leem os números do mês explicitamente.
            'monthStats' => $periods['mes']['stats'],
            'monthTrends' => $periods['mes']['trends'],
            'recent' => $recent,
            'accounts' => $accounts,
            'totalBalance' => $totalBalance,
            // Exibe "quem fez a compra" nas recentes só quando a família tem dependentes.
            'showAuthor' => User::where('account_owner_id', $userId)->exists(),
            // Resumos das features (metas, faturas a pagar, investimentos) p/ os cards.
            ...$this->featureResumos($userId, $accounts),
            // Painel "Meus cartões": gasto e limite disponível de cada cartão.
            ...$this->creditCardsPanel($accounts),
        ];
    }

    /**
     * Resumos compactos p/ os cards do dashboard: total guardado em metas, total
     * a pagar nas faturas de cartão (ciclo atual) e total investido — cada um com
     * contagem e os 3 principais itens.
     */
    /**
     * Painel "Meus cartões": um item por cartão de CRÉDITO, em lista compacta,
     * com nome, gasto da fatura, limite ainda livre, dia de vencimento e o
     * MELHOR DIA DE COMPRA. Mais os totais da carteira.
     *
     * Melhor dia de compra = o dia seguinte ao fechamento: comprando nele, a
     * despesa cai só na fatura seguinte, dando o maior prazo possível para
     * pagar. Como o cadastro limita o fechamento a 1..28, o dia seguinte é
     * sempre válido (29 vira 1, para não cair num dia inexistente em fevereiro).
     *
     * @return array{cartoes: list<array<string, mixed>>, cartoesTotais: array<string, float>}
     */
    private function creditCardsPanel($accounts): array
    {
        $cartoes = $accounts->where('type', 'credit_card')->values()->map(function (Account $c) {
            $limite = (float) $c->credit_limit;
            $disponivel = $c->availableLimitDisplay;
            $usado = max(0.0, round($limite - $disponivel, 2));

            $fechamento = (int) $c->closing_day;
            $melhorDia = $fechamento > 0 ? ($fechamento >= 28 ? 1 : $fechamento + 1) : null;

            return [
                'id' => $c->id,
                'nome' => $c->name,
                'banco' => $c->bankLabel(),
                'imagem' => $c->bankImageUrl(),
                'gasto' => $c->currentInvoice,   // fatura do ciclo aberto
                'limite' => $limite,
                'disponivel' => $disponivel,
                'usadoPct' => $limite > 0 ? (int) min(100, round($usado / $limite * 100)) : 0,
                'vencimento' => $c->dueDate?->format('d/m'),
                'fechamento' => $fechamento ?: null,
                'melhorDia' => $melhorDia,
            ];
        })->all();

        return [
            'cartoes' => $cartoes,
            'cartoesTotais' => [
                'gasto' => round(array_sum(array_column($cartoes, 'gasto')), 2),
                'disponivel' => round(array_sum(array_column($cartoes, 'disponivel')), 2),
                'limite' => round(array_sum(array_column($cartoes, 'limite')), 2),
            ],
        ];
    }

    private function featureResumos(int $userId, $accounts): array
    {
        $goals = Goal::where('user_id', $userId)->orderByDesc('id')->get();
        $investments = Investment::where('user_id', $userId)->orderByDesc('id')->get();
        $cards = $accounts->where('type', 'credit_card');

        $aPagar = $this->obrigacoesEmAberto($userId, $cards);

        return [
            'metasResumo' => [
                'total' => round((float) $goals->sum(fn (Goal $g) => $g->saved), 2),
                'count' => $goals->count(),
                'top' => $goals->sortByDesc(fn (Goal $g) => $g->saved)->take(3)->map(fn (Goal $g) => [
                    'name' => $g->name,
                    'saved' => $g->saved,
                    'progress' => $g->progress,
                ])->values()->all(),
            ],
            'faturasResumo' => [
                // Total, contador e lista saem TODOS da mesma coleção — ver
                // `obrigacoesEmAberto()` para o porquê de isso importar.
                'total' => round((float) $aPagar->sum('invoice'), 2),
                'count' => $aPagar->count(),
                'top' => $aPagar->sortByDesc('invoice')->take(3)->values()->all(),
            ],
            'investimentosResumo' => [
                'total' => round((float) $investments->sum(fn (Investment $i) => $i->aplicado), 2),
                'count' => $investments->count(),
                'top' => $investments->sortByDesc(fn (Investment $i) => $i->aplicado)->take(3)->map(fn (Investment $i) => [
                    'name' => $i->name,
                    'aplicado' => $i->aplicado,
                    'classe' => Investment::CLASSES[$i->classe] ?? $i->classe,
                ])->values()->all(),
            ],
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
        float $saldoDisponivel,
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
                // Saldo DISPONÍVEL atual (contas que são caixa, menos o que está
                // guardado em metas/investimentos). É uma foto de agora — não
                // depende do período escolhido.
                'saldo' => $saldoDisponivel,
                'receitas' => $incomeTotal,
                'despesas' => $expenseTotal,
                // Receitas − despesas do período: positivo sobrou, negativo faltou.
                'economia' => round($incomeTotal - $expenseTotal, 2),
            ],
            'trends' => $this->trends($userId, $hasData, $saldoDisponivel, $initialTotal, $prevPeriodEnd, $incomeTotal, $expenseTotal, $prev, $cardIds),
        ];
    }

    /**
     * Dinheiro "reservado" no modelo cofrinho da família: guardado em metas +
     * aplicado em investimentos (Σ aportes − Σ resgates). Ele continua dentro
     * do saldo da conta, mas NÃO está disponível para gastar.
     *
     * $until limita por data (usado para comparar com o período anterior).
     *
     * @return array{guardado: float, investido: float, total: float}
     */
    /**
     * Tudo que a família deve HOJE, numa lista só: faturas de cartão em aberto e
     * competências de contas fixas não pagas — a MESMA dívida que o sino da topbar
     * conta (`FaturaService::upcomingDue`).
     *
     * Duas armadilhas se fecham aqui, e as duas faziam o card mentir:
     *
     * 1. A dívida do cartão é `openInvoiceDue` + `closedInvoiceDue`. Sozinho, o
     *    `openInvoiceDue` só enxerga o ciclo ABERTO: uma compra de junho não paga
     *    sumia do card no dia em que o ciclo virava, enquanto o sino e /faturas
     *    continuavam cobrando. As duas janelas são DISJUNTAS (a fechada termina no
     *    início do ciclo aberto; a aberta começa logo depois), então somar as duas
     *    não conta nada duas vezes.
     *
     * 2. O `count` que mostra o card sai DESTA lista, a mesma que gera o `total`.
     *    Antes ele contava só cartões: quem devia três aluguéis e não tinha cartão
     *    via "Nada a pagar 🎉" — com o total já somado, mas nunca renderizado.
     *
     * @param  \Illuminate\Support\Collection<int, Account>  $cards
     * @return Collection<int, array{name: string, invoice: float, due: ?string, vencida: bool}>
     */
    private function obrigacoesEmAberto(int $userId, $cards): Collection
    {
        $itens = collect();

        foreach ($cards as $cartao) {
            $valor = round($cartao->openInvoiceDue + $cartao->closedInvoiceDue, 2);

            if ($valor <= 0.001) {
                continue;
            }

            // Havendo dívida já fechada, o vencimento que interessa é o da fatura
            // mais antiga ainda em aberto. Derivar do ciclo aberto anunciava
            // "Vence 20/08" para uma dívida que venceu em 20/06.
            $fechada = $cartao->closedInvoice;

            $itens->push([
                'name' => (string) $cartao->name,
                'invoice' => $valor,
                'due' => ($fechada['vencimento'] ?? $cartao->dueDate)?->format('d/m'),
                'vencida' => (bool) ($fechada['vencida'] ?? false),
            ]);
        }

        foreach ($this->contasFixasEmAberto($userId) as $ocorrencia) {
            $itens->push([
                'name' => (string) ($ocorrencia['bill']->name ?? 'Conta fixa'),
                'invoice' => round((float) ($ocorrencia['valor'] ?? 0), 2),
                'due' => $ocorrencia['vencimento']?->format('d/m'),
                'vencida' => (bool) ($ocorrencia['vencida'] ?? false),
            ]);
        }

        return $itens->filter(fn ($i) => $i['invoice'] > 0)->values();
    }

    /**
     * Competências de contas fixas que ainda não foram pagas.
     *
     * Delega ao FixedBillService: a projeção das competências (e a regra do que já
     * venceu) mora lá, e duplicar isso aqui foi exatamente o que fez o card e o sino
     * discordarem no passado.
     *
     * @return Collection<int, mixed>
     */
    private function contasFixasEmAberto(int $userId): Collection
    {
        if (! class_exists(FixedBillService::class)) {
            return collect();
        }

        // Sem type hint de propósito: `currentAndOverdue()` devolve `Fluent`, não array
        // (mesma pegadinha de `Account::paymentOptions()` anotada no CLAUDE.md). O acesso
        // por offset funciona nos dois.
        return app(FixedBillService::class)
            ->currentAndOverdue($userId)
            ->reject(fn ($ocorrencia) => (bool) ($ocorrencia['paga'] ?? false))
            ->values();
    }

    /**
     * Reservado (metas + investimentos) agrupado POR CONTA de origem.
     *
     * Duas queries agregadas para a família inteira — nunca uma por conta. É o que
     * permite a lista de contas exibir o disponível sem cair no N+1 dos accessors.
     *
     * @return array<int, float>  [account_id => reservado]
     */
    public function reservedByAccount(int $userId): array
    {
        $metas = GoalContribution::query()
            ->join('goals', 'goals.id', '=', 'goal_contributions.goal_id')
            ->where('goals.user_id', $userId)
            ->whereNotNull('goal_contributions.account_id')
            ->groupBy('goal_contributions.account_id')
            ->selectRaw('goal_contributions.account_id AS conta')
            ->selectRaw("COALESCE(SUM(CASE WHEN goal_contributions.type = 'aporte' THEN goal_contributions.amount ELSE -goal_contributions.amount END), 0) AS reservado")
            ->pluck('reservado', 'conta');

        $investimentos = InvestmentContribution::query()
            ->join('investments', 'investments.id', '=', 'investment_contributions.investment_id')
            ->where('investments.user_id', $userId)
            ->whereNotNull('investment_contributions.account_id')
            ->groupBy('investment_contributions.account_id')
            ->selectRaw('investment_contributions.account_id AS conta')
            ->selectRaw("COALESCE(SUM(CASE WHEN investment_contributions.type = 'aporte' THEN investment_contributions.amount ELSE -investment_contributions.amount END), 0) AS reservado")
            ->pluck('reservado', 'conta');

        $total = [];

        foreach ([$metas, $investimentos] as $fonte) {
            foreach ($fonte as $contaId => $valor) {
                $total[(int) $contaId] = round(($total[(int) $contaId] ?? 0) + (float) $valor, 2);
            }
        }

        return $total;
    }

    public function reservedTotals(int $userId, ?CarbonImmutable $until = null): array
    {
        $guardado = (float) GoalContribution::query()
            ->join('goals', 'goals.id', '=', 'goal_contributions.goal_id')
            ->where('goals.user_id', $userId)
            ->when($until, fn ($q) => $q->where('goal_contributions.date', '<=', $until->toDateString()))
            ->selectRaw("COALESCE(SUM(CASE WHEN goal_contributions.type = 'aporte' THEN goal_contributions.amount ELSE -goal_contributions.amount END), 0) AS reservado")
            ->value('reservado');

        $investido = (float) InvestmentContribution::query()
            ->join('investments', 'investments.id', '=', 'investment_contributions.investment_id')
            ->where('investments.user_id', $userId)
            ->when($until, fn ($q) => $q->where('investment_contributions.date', '<=', $until->toDateString()))
            ->selectRaw("COALESCE(SUM(CASE WHEN investment_contributions.type = 'aporte' THEN investment_contributions.amount ELSE -investment_contributions.amount END), 0) AS aplicado")
            ->value('aplicado');

        $guardado = round($guardado, 2);
        $investido = round($investido, 2);

        return [
            'guardado' => $guardado,
            'investido' => $investido,
            'total' => round($guardado + $investido, 2),
        ];
    }

    /**
     * Variação % vs período anterior equivalente. null quando a base anterior
     * é zero (sem dados para comparar) — o front renderiza "—" neutro.
     */
    private function trends(
        int $userId,
        bool $hasData,
        float $saldoDisponivel,
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
        // até lá (também sem cartões — cartão não é caixa), MENOS o que já estava
        // reservado em metas/investimentos naquela data. Assim comparamos
        // disponível com disponível (maçã com maçã).
        $previousBalance = round(
            $initialTotal
            + $this->signedSumUntil($userId, $prevPeriodEnd, $cardIds)
            - $this->reservedTotals($userId, $prevPeriodEnd)['total'],
            2,
        );

        return [
            'saldo' => $this->pctChange($saldoDisponivel, $previousBalance),
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
    private function sparks(int $userId, bool $hasData, float $initialTotal, CarbonImmutable $today, array $cardIds = [], array $creditCardIds = []): array
    {
        $sparks = ['saldo' => [], 'receitas' => [], 'despesas' => [], 'economia' => []];
        if (! $hasData) {
            return $sparks;
        }

        $start = $today->subDays(6);
        // Cashflow (receitas/despesas/economia) inclui cartões — é gasto real.
        $daily = $this->dailySums($userId, $start, $today, refundAccountIds: $creditCardIds);
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
        // `incluirQuitacoes: true` — esta série é o SALDO, e o pagamento da fatura
        // desconta do saldo de verdade.
        $daily ??= $this->dailySums($userId, $start, $today, $excludeAccountIds, incluirQuitacoes: true);
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
     * Despesas do mês agrupadas por categoria (decrescente).
     *
     * As categorias FIXAS (is_locked) aparecem SEMPRE — mesmo sem gasto no
     * período, entrando zeradas no fim da lista — para o usuário acompanhar as
     * principais o tempo todo. Elas também nunca caem no agregado "Outros":
     * o corte de 5 maiores + "Outros" vale só para as demais.
     *
     * Sem nenhuma despesa no período devolve [] (o card mostra o estado vazio;
     * um donut todo zerado não diria nada).
     */
    private function categoryBreakdown(int $userId, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $rows = Transaction::query()
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', 'expense')
            // Quitação de fatura não é gasto novo — ver `settles_account_id`.
            ->whereNull('transactions.settles_account_id')
            ->whereBetween('transactions.date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->groupBy('transactions.category_id', 'categories.name', 'categories.color', 'categories.is_locked')
            ->orderByDesc('total')
            ->selectRaw('categories.name AS cat_name, categories.color AS cat_color, categories.is_locked AS cat_locked, SUM(transactions.amount) AS total')
            ->get();

        $items = $rows->map(fn ($row) => [
            'name' => $row->cat_name ?? 'Sem categoria',
            'value' => round((float) $row->total, 2),
            'color' => $row->cat_name === null ? self::NO_CATEGORY_COLOR : ($row->cat_color ?: null),
            'locked' => (bool) $row->cat_locked,
        ])->values();

        if ($items->isEmpty()) {
            return [];
        }

        // "Outros" agrega só as NÃO fixas que passarem do corte.
        $fixas = $items->where('locked', true)->values();
        $livres = $items->where('locked', false)->values();
        if ($livres->count() > 6) {
            $rest = round($livres->slice(5)->sum('value'), 2);
            $livres = $livres->take(5)->push(['name' => 'Outros', 'value' => $rest, 'color' => null, 'locked' => false]);
        }

        // Com gasto no período, ordenadas do maior para o menor.
        $comGasto = $fixas->concat($livres)->sortByDesc('value')->values();

        // Fixas sem gasto no período: entram zeradas, no fim (só na legenda —
        // o donut não desenha fatia de valor zero).
        $zeradas = Category::where('user_id', $userId)
            ->where('type', 'expense')
            ->where('is_locked', true)
            ->whereNotIn('name', $fixas->pluck('name')->all())
            ->orderBy('name')
            ->get(['name', 'color'])
            ->map(fn (Category $c) => ['name' => $c->name, 'value' => 0.0, 'color' => $c->color ?: null, 'locked' => true]);

        return $comGasto->concat($zeradas)->values()->map(function (array $cat, int $i) {
            $cat['color'] = $cat['color'] ?? self::PALETTE[$i % count(self::PALETTE)];
            unset($cat['locked']); // uso interno; o contrato do payload é {name, value, color}

            return $cat;
        })->all();
    }

    /**
     * Somas diárias por tipo no intervalo: ['Y-m-d' => ['income' => x, 'expense' => y]].
     * $excludeAccountIds permite ignorar contas (ex.: cartões de crédito, que
     * não entram no cálculo de saldo/patrimônio).
     * $refundAccountIds: contas onde `income` é ESTORNO, não receita — ver `abaterEstornos()`.
     */
    private function dailySums(
        int $userId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $excludeAccountIds = [],
        bool $incluirQuitacoes = false,
        array $refundAccountIds = [],
    ): array {
        $rows = Transaction::where('user_id', $userId)
            // Quitação de fatura não é GASTO novo (o gasto foi a compra no cartão), mas
            // É saída de caixa. Quem mede despesa a exclui; quem mede SALDO precisa dela,
            // senão a linha do patrimônio ignora o dinheiro que saiu para pagar a fatura.
            ->when(! $incluirQuitacoes, fn ($q) => $q->whereNull('settles_account_id'))
            ->when($excludeAccountIds, fn ($q) => $q->whereNotIn('account_id', $excludeAccountIds))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            // account_id entra no group by só quando há cartão a tratar: sem isso a
            // consulta ficaria mais larga do que precisa nas séries de saldo.
            ->when(
                $refundAccountIds,
                fn ($q) => $q->selectRaw('date, type, account_id, SUM(amount) AS total')->groupBy('date', 'type', 'account_id'),
                fn ($q) => $q->selectRaw('date, type, SUM(amount) AS total')->groupBy('date', 'type'),
            )
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $dia = $row->date->toDateString();
            $valor = round((float) $row->total, 2);

            // Estorno no cartão ABATE a despesa do dia em vez de virar receita.
            $estorno = $row->type === 'income'
                && $refundAccountIds
                && in_array((int) $row->account_id, $refundAccountIds, true);

            $chave = $estorno ? 'expense' : $row->type;
            $delta = $estorno ? -$valor : $valor;

            $out[$dia][$chave] = round(($out[$dia][$chave] ?? 0.0) + $delta, 2);
        }

        // Piso 0 por dia: um estorno maior que as compras daquele dia significa que não
        // se gastou nada — não que se "ganhou" com despesa negativa (o gráfico de barras
        // não representa valor negativo). Mesmo piso que `committed`/`currentInvoice` usam.
        foreach ($out as $dia => $sums) {
            if (isset($sums['expense'])) {
                $out[$dia]['expense'] = max(0.0, $sums['expense']);
            }
        }

        return $out;
    }

    /**
     * Totais de receitas e despesas do intervalo (uma query).
     *
     * $refundAccountIds: contas de CARTÃO DE CRÉDITO. Um `income` lançado num cartão é
     * ESTORNO de compra, não dinheiro entrando — contá-lo como receita inflava as
     * "receitas do mês" e a "economia" com dinheiro que nunca existiu. É a mesma regra
     * de sinal que `committed` e `currentInvoice` já aplicam na fatura.
     */
    private function totals(int $userId, CarbonImmutable $from, CarbonImmutable $to, array $refundAccountIds = []): array
    {
        $row = Transaction::where('user_id', $userId)
            // Quitação de fatura não é gasto novo — ver `settles_account_id`.
            ->whereNull('settles_account_id')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN type = 'income' THEN amount END), 0) AS income_total, " .
                "COALESCE(SUM(CASE WHEN type = 'expense' THEN amount END), 0) AS expense_total, " .
                'COALESCE(SUM(CASE WHEN type = ' . "'income'" . ' AND ' . $this->emCartaoSql($refundAccountIds) . ' THEN amount END), 0) AS refund_total',
            )
            ->first();

        $estornos = round((float) ($row->refund_total ?? 0), 2);

        return [
            'income' => round(round((float) ($row->income_total ?? 0), 2) - $estornos, 2),
            'expense' => max(0.0, round(round((float) ($row->expense_total ?? 0), 2) - $estornos, 2)),
        ];
    }

    /**
     * Fragmento SQL "a linha está num destes cartões". Sem cartão nenhum devolve uma
     * condição sempre falsa — `IN ()` é sintaxe inválida nos dois bancos. Os ids vêm de
     * `Account::pluck('id')`, nunca do usuário, e são forçados a inteiro aqui.
     */
    private function emCartaoSql(array $accountIds): string
    {
        if (! $accountIds) {
            return '1 = 0';
        }

        return 'account_id IN (' . implode(',', array_map('intval', $accountIds)) . ')';
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
