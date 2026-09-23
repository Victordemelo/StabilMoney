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

        // ----- Fluxo de caixa: semana, mês e ano saem da MESMA regra -----
        // Ver `seriesDeFluxo()`: o estorno de cartão abate só as despesas de CARTÃO
        // do período do card (nunca as de caixa), com piso 0 no agregado do período,
        // e a soma dos buckets É o stat. Antes cada fonte aplicava o piso numa
        // granularidade (dia, mês, período) e o mesmo agosto mostrava três números.

        // Semana atual (Seg..Dom)
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->addDays(6);
        $semana = $this->seriesDeFluxo(
            $this->fluxoDiario($userId, $weekStart, $weekEnd, $creditCardIds),
            7,
            fn (string $dia) => CarbonImmutable::parse($dia)->dayOfWeekIso - 1,
        );
        $weekPrev = $this->totals($userId, $weekStart->subWeek(), $weekStart->subDay(), $creditCardIds);

        // Mês atual (buckets Sem 1..Sem N, dias 1-7, 8-14, ...)
        $monthStart = $today->startOfMonth();
        $monthEnd = $today->endOfMonth();
        $bucketCount = (int) ceil($today->daysInMonth / 7);
        $mes = $this->seriesDeFluxo(
            $this->fluxoDiario($userId, $monthStart, $monthEnd, $creditCardIds),
            $bucketCount,
            fn (string $dia) => intdiv(((int) substr($dia, 8, 2)) - 1, 7),
        );
        $monthLabels = array_map(fn ($i) => 'Sem '.($i + 1), range(0, $bucketCount - 1));
        $monthPrev = $this->totals($userId, $monthStart->subMonth(), $monthStart->subDay(), $creditCardIds);

        // Ano atual (Jan..Dez). Agregado por DIA no SQL e por mês em PHP — o mesmo
        // caminho da semana e do mês, para o estorno seguir uma regra só. (Saiu o
        // MONTH()/strftime por driver: eram no máximo 366 linhas a mais por ano.)
        $yearStart = $today->startOfYear();
        $yearEnd = $today->endOfYear();
        $ano = $this->seriesDeFluxo(
            $this->fluxoDiario($userId, $yearStart, $yearEnd, $creditCardIds),
            12,
            fn (string $dia) => ((int) substr($dia, 5, 2)) - 1,
        );
        $yearPrev = $this->totals($userId, $yearStart->subYear(), $yearStart->subDay(), $creditCardIds);

        // ----- Períodos no formato do contrato -----
        $periods = [
            'semana' => $this->period(
                'Esta semana',
                ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
                $semana['income'],
                $semana['expense'],
                $weekPrev,
                $userId, $hasData, $saldoDisponivel, $initialTotal, $weekStart->subDay(), $cardIds,
            ),
            'mes' => $this->period(
                Str::ucfirst($today->translatedFormat('F')).' de '.$today->year,
                $monthLabels,
                $mes['income'],
                $mes['expense'],
                $monthPrev,
                $userId, $hasData, $saldoDisponivel, $initialTotal, $monthStart->subDay(), $cardIds,
            ),
            'ano' => $this->period(
                (string) $today->year,
                ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'],
                $ano['income'],
                $ano['expense'],
                $yearPrev,
                $userId, $hasData, $saldoDisponivel, $initialTotal, $yearStart->subDay(), $cardIds,
            ),
        ];

        // ----- Sparklines (últimos 7 dias) -----
        $sparks = $this->sparks($userId, $hasData, $initialTotal, $today, $cardIds, $creditCardIds);

        // ----- Gastos do mês por categoria (top 5 + "Outros") -----
        $cats = $this->categoryBreakdown($userId, $monthStart, $monthEnd, $creditCardIds);

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

        // Guardado por meta e aplicado por investimento numa query agregada CADA. Os
        // accessors `saved`/`aplicado` fazem 2 SUMs por item, e o dashboard lia todos eles
        // para somar e ordenar: com 5 metas e 4 investimentos eram 18 queries (V-3 da
        // auditoria de volume). Os números são os mesmos — ver `somaDasContribuicoes()`.
        $guardado = $this->somaDasContribuicoes(GoalContribution::class, 'goal_id', $goals);
        $aplicado = $this->somaDasContribuicoes(InvestmentContribution::class, 'investment_id', $investments);
        $saved = fn (Goal $g) => $guardado[$g->id] ?? 0.0;
        $aplicadoDe = fn (Investment $i) => $aplicado[$i->id] ?? 0.0;

        return [
            'metasResumo' => [
                'total' => round((float) $goals->sum($saved), 2),
                'count' => $goals->count(),
                'top' => $goals->sortByDesc($saved)->take(3)->map(fn (Goal $g) => [
                    'name' => $g->name,
                    'saved' => $saved($g),
                    'progress' => $this->progressoDaMeta($saved($g), $g),
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
                'total' => round((float) $investments->sum($aplicadoDe), 2),
                'count' => $investments->count(),
                'top' => $investments->sortByDesc($aplicadoDe)->take(3)->map(fn (Investment $i) => [
                    'name' => $i->name,
                    'aplicado' => $aplicadoDe($i),
                    'classe' => Investment::CLASSES[$i->classe] ?? $i->classe,
                ])->values()->all(),
            ],
        ];
    }

    /**
     * Σ aportes − Σ resgates de cada meta (ou investimento) da lista, numa query só.
     *
     * É a MESMA conta dos accessors `Goal::saved` e `Investment::aplicado` — aportes e
     * resgates somados em separado, subtraídos e arredondados em PHP; tipo que não seja
     * nenhum dos dois não entra —, só que agrupada. Os accessors não têm como receber o
     * valor pronto (o cache deles é privado), então o dashboard usa o daqui; o
     * `ResumosDoDashboardSemNMaisUmTest` confere, item a item, que os dois concordam.
     *
     * @param  class-string<GoalContribution|InvestmentContribution>  $modelo
     * @param  Collection<int, Goal|Investment>  $pais
     * @return array<int, float> [id da meta ou do investimento => valor]; sem contribuição fica de fora
     */
    private function somaDasContribuicoes(string $modelo, string $chave, Collection $pais): array
    {
        if ($pais->isEmpty()) {
            return [];
        }

        $linhas = $modelo::query()
            ->whereIn($chave, $pais->pluck('id')->all())
            ->groupBy($chave)
            ->selectRaw("{$chave} AS pai")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'aporte' THEN amount ELSE 0 END), 0) AS aportes")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'resgate' THEN amount ELSE 0 END), 0) AS resgates")
            ->get();

        $total = [];
        foreach ($linhas as $linha) {
            $total[(int) $linha->pai] = round((float) $linha->aportes - (float) $linha->resgates, 2);
        }

        return $total;
    }

    /**
     * Progresso da meta em % (0..100) a partir do guardado já somado — a regra de
     * `Goal::progress`, que só sabe calcular com o próprio SUM. Alvo zerado dá 0.
     */
    private function progressoDaMeta(float $guardado, Goal $meta): int
    {
        $alvo = (float) $meta->target_amount;
        if ($alvo <= 0) {
            return 0;
        }

        return (int) min(100, round($guardado / $alvo * 100));
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
     * @param  Collection<int, Account>  $cards
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
     * @return array<int, float> [account_id => reservado]
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
        // Cashflow (receitas/despesas/economia) inclui cartões — é gasto real. A janela
        // de 7 dias é o "período" da regra do estorno (`seriesDeFluxo`): a soma da spark
        // de despesas é o líquido do cartão na janela, com piso 0, mais o caixa.
        $fluxo = $this->seriesDeFluxo(
            $this->fluxoDiario($userId, $start, $today, $creditCardIds),
            7,
            fn (string $dia) => (int) $start->diffInDays(CarbonImmutable::parse($dia)),
        );
        $saved = 0.0;

        // Saldo NÃO inclui cartões e DESCONTA as reservas: o stat "saldo" do card é o
        // disponível, e a linha precisa terminar exatamente nele (ver `saldoSpark`).
        $sparks['saldo'] = $this->saldoSpark($userId, $initialTotal, $today, null, $cardIds, descontarReservas: true);

        for ($i = 0; $i < 7; $i++) {
            $income = $fluxo['income'][$i];
            $expense = $fluxo['expense'][$i];
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
     *
     * $descontarReservas (T-5 da auditoria de 02/09/2026): o stat "saldo" do dashboard
     * é o DISPONÍVEL (bruto − guardado em metas − investido), então a spark dele
     * precisa descontar as reservas dia a dia — senão um aporte de meta derruba o
     * número do card, não move a linha, e o último ponto nunca coincide com o stat
     * (medido: card 6.300 × linha 8.050). A sidebar mostra o patrimônio BRUTO e chama
     * sem o desconto: lá número e linha já concordavam.
     */
    public function saldoSpark(
        int $userId,
        float $initialTotal,
        CarbonImmutable $today,
        ?array $daily = null,
        array $excludeAccountIds = [],
        bool $descontarReservas = false,
    ): array {
        $start = $today->subDays(6);
        // `incluirQuitacoes: true` — esta série é o SALDO, e o pagamento da fatura
        // desconta do saldo de verdade.
        $daily ??= $this->dailySums($userId, $start, $today, $excludeAccountIds, incluirQuitacoes: true);
        $balance = round($initialTotal + $this->signedSumUntil($userId, $start->subDay(), $excludeAccountIds), 2);

        $reservas = [];
        if ($descontarReservas) {
            // O que já estava reservado antes da janela sai do ponto de partida; o que
            // foi aportado/resgatado dentro dela move a linha no dia em que aconteceu.
            $balance = round($balance - $this->reservedTotals($userId, $start->subDay())['total'], 2);
            $reservas = $this->reservasDiarias($userId, $start, $today);
        }

        $points = [];

        for ($i = 0; $i < 7; $i++) {
            $key = $start->addDays($i)->toDateString();
            $balance = round(
                $balance
                + ($daily[$key]['income'] ?? 0.0)
                - ($daily[$key]['expense'] ?? 0.0)
                - ($reservas[$key] ?? 0.0),
                2,
            );
            $points[] = $balance;
        }

        return $points;
    }

    /**
     * Variação diária do reservado (Σ aportes − Σ resgates de metas e investimentos)
     * no intervalo: ['Y-m-d' => delta]. Mesma base de `reservedTotals()` (sem filtrar
     * por conta), para a spark fechar no MESMO número que o stat.
     *
     * @return array<string, float>
     */
    private function reservasDiarias(int $userId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $metas = GoalContribution::query()
            ->join('goals', 'goals.id', '=', 'goal_contributions.goal_id')
            ->where('goals.user_id', $userId)
            ->whereBetween('goal_contributions.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('goal_contributions.date')
            ->selectRaw('goal_contributions.date AS dia')
            ->selectRaw("COALESCE(SUM(CASE WHEN goal_contributions.type = 'aporte' THEN goal_contributions.amount ELSE -goal_contributions.amount END), 0) AS delta")
            ->get();

        $investimentos = InvestmentContribution::query()
            ->join('investments', 'investments.id', '=', 'investment_contributions.investment_id')
            ->where('investments.user_id', $userId)
            ->whereBetween('investment_contributions.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('investment_contributions.date')
            ->selectRaw('investment_contributions.date AS dia')
            ->selectRaw("COALESCE(SUM(CASE WHEN investment_contributions.type = 'aporte' THEN investment_contributions.amount ELSE -investment_contributions.amount END), 0) AS delta")
            ->get();

        $out = [];
        foreach ([$metas, $investimentos] as $fonte) {
            foreach ($fonte as $row) {
                // O alias `dia` escapa do cast do model: chega como string nos dois drivers.
                $dia = substr((string) $row->dia, 0, 10);
                $out[$dia] = round(($out[$dia] ?? 0.0) + (float) $row->delta, 2);
            }
        }

        return $out;
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
     *
     * ESTORNO NO CARTÃO (T-2 da auditoria de 02/09/2026): um `income` num cartão de
     * crédito é devolução de compra e ABATE a categoria em que foi lançado — antes o
     * donut somava só `type = 'expense'` e mostrava 1.000 ao lado de um stat de
     * despesas de 600, na mesma tela. A fórmula é a MESMA de `seriesDeFluxo()`, só
     * que por categoria:
     *
     *   fatia = despesas de CAIXA da categoria + max(0, compras − estornos no CARTÃO)
     *
     * Decisões:
     * - o abate cai na categoria DO PRÓPRIO ESTORNO (é o que o usuário escolheu);
     *   estorno sem categoria abate só as compras de cartão em "Sem categoria";
     * - o estorno nunca toca despesa de caixa, nem dentro da categoria — o mercado
     *   pago no débito continua inteiro mesmo que o estorno seja maior;
     * - estorno em categoria diferente da compra (ou sem categoria) deixa o donut
     *   somar MAIS que o stat: o stat aplica o piso no cartão inteiro, o donut por
     *   categoria. Categorizar o estorno igual à compra é o que fecha a conta;
     * - categoria zerada pelo estorno some do donut (fixa volta zerada na legenda).
     */
    private function categoryBreakdown(int $userId, CarbonImmutable $monthStart, CarbonImmutable $monthEnd, array $creditCardIds = []): array
    {
        // Só `transactions` tem `account_id` neste join — o fragmento não fica ambíguo.
        $emCartao = $this->emCartaoSql($creditCardIds);

        $rows = Transaction::query()
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $userId)
            // Despesa de qualquer conta + `income` de CARTÃO (estorno). Receita de
            // caixa continua fora: não é gasto nem devolução de gasto.
            ->where(fn ($q) => $q->where('transactions.type', 'expense')->orWhereRaw($emCartao))
            // Quitação de fatura não é gasto novo — ver `settles_account_id`.
            ->whereNull('transactions.settles_account_id')
            // Transferência entre contas também não: o dinheiro só trocou de conta.
            ->whereNull('transactions.transfer_group_id')
            ->whereBetween('transactions.date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->groupBy('transactions.category_id', 'categories.name', 'categories.color', 'categories.is_locked')
            ->selectRaw(
                'categories.name AS cat_name, categories.color AS cat_color, categories.is_locked AS cat_locked, '.
                "COALESCE(SUM(CASE WHEN transactions.type = 'expense' AND NOT ({$emCartao}) THEN transactions.amount END), 0) AS caixa_total, ".
                "COALESCE(SUM(CASE WHEN {$emCartao} THEN (CASE WHEN transactions.type = 'expense' THEN transactions.amount ELSE -transactions.amount END) END), 0) AS cartao_total",
            )
            ->get();

        $items = $rows->map(fn ($row) => [
            'name' => $row->cat_name ?? 'Sem categoria',
            // Piso 0 só no líquido do CARTÃO: o caixa da categoria fica inteiro.
            'value' => round((float) $row->caixa_total + max(0.0, (float) $row->cartao_total), 2),
            'color' => $row->cat_name === null ? self::NO_CATEGORY_COLOR : ($row->cat_color ?: null),
            'locked' => (bool) $row->cat_locked,
        ])
            // Categoria que ficou em zero (só estorno, ou estorno ≥ compras) não vira
            // fatia negativa nem fatia de zero — sai do donut.
            ->filter(fn (array $cat) => $cat['value'] > 0.005)
            ->sortByDesc('value')
            ->values();

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
     * Usada pelas séries de SALDO (dashboard e sidebar), que já excluem os cartões
     * por `$excludeAccountIds` — por isso não há estorno a tratar aqui. Para o
     * fluxo de caixa (receitas/despesas) a fonte é `fluxoDiario()`.
     */
    private function dailySums(
        int $userId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $excludeAccountIds = [],
        bool $incluirQuitacoes = false,
    ): array {
        $rows = Transaction::where('user_id', $userId)
            // Quitação de fatura não é GASTO novo (o gasto foi a compra no cartão), mas
            // É saída de caixa. Quem mede despesa a exclui; quem mede SALDO precisa dela,
            // senão a linha do patrimônio ignora o dinheiro que saiu para pagar a fatura.
            ->when(! $incluirQuitacoes, fn ($q) => $q->whereNull('settles_account_id'))
            // Transferência entre contas de caixa: NÃO é receita nem despesa. Nas
            // séries de SALDO (`$incluirQuitacoes`) ela fica — cada ponta move a sua
            // conta, e no total as duas se anulam sozinhas.
            ->when(! $incluirQuitacoes, fn ($q) => $q->whereNull('transfer_group_id'))
            ->when($excludeAccountIds, fn ($q) => $q->whereNotIn('account_id', $excludeAccountIds))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('date, type, SUM(amount) AS total')
            ->groupBy('date', 'type')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $dia = $row->date->toDateString();
            $out[$dia][$row->type] = round(($out[$dia][$row->type] ?? 0.0) + round((float) $row->total, 2), 2);
        }

        return $out;
    }

    /**
     * Fluxo de caixa diário, já separado no que a regra do estorno precisa:
     *
     *   ['Y-m-d' => ['income' => receita de CAIXA,
     *                'caixa'  => despesa fora de cartão (sempre ≥ 0),
     *                'cartao' => compras − estornos no cartão de crédito, COM sinal]]
     *
     * Um `income` num cartão de crédito é ESTORNO de compra (devolução), não dinheiro
     * entrando: entra negativo em `cartao` e nunca em `income` — contá-lo como receita
     * inflava as "receitas do mês" e a "economia" com dinheiro que nunca existiu.
     * Quitação de fatura fica fora: não é gasto novo (ver `settles_account_id`).
     * Transferência entre contas também (ver `transfer_group_id`).
     *
     * @return array<string, array{income: float, caixa: float, cartao: float}>
     */
    private function fluxoDiario(int $userId, CarbonImmutable $from, CarbonImmutable $to, array $creditCardIds): array
    {
        $emCartao = $this->emCartaoSql($creditCardIds);

        $rows = Transaction::where('user_id', $userId)
            ->whereNull('settles_account_id')
            // Transferência entre contas fica fora das QUATRO fontes de fluxo (semana,
            // mês, ano, sparks — e `totals()`, que é esta função com um bucket):
            // mover R$ 300 da corrente para a poupança não é receita nem despesa, e
            // antes inflava as duas somas do mês.
            ->whereNull('transfer_group_id')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw(
                'date, '.
                "COALESCE(SUM(CASE WHEN type = 'income' AND NOT ({$emCartao}) THEN amount END), 0) AS income_total, ".
                "COALESCE(SUM(CASE WHEN type = 'expense' AND NOT ({$emCartao}) THEN amount END), 0) AS caixa_total, ".
                "COALESCE(SUM(CASE WHEN {$emCartao} THEN (CASE WHEN type = 'expense' THEN amount ELSE -amount END) END), 0) AS cartao_total",
            )
            ->groupBy('date')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->date->toDateString()] = [
                'income' => round((float) $row->income_total, 2),
                'caixa' => round((float) $row->caixa_total, 2),
                'cartao' => round((float) $row->cartao_total, 2),
            ];
        }

        return $out;
    }

    /**
     * Distribui o fluxo diário nos buckets do gráfico e devolve as séries prontas.
     *
     * REGRA DO ESTORNO (T-1 da auditoria de 02/09/2026) — UMA só, para as quatro
     * fontes (semana, mês, ano e sparks; `totals()` é o caso de 1 bucket):
     *
     *   despesas(período) = despesas de CAIXA + max(0, compras − estornos no CARTÃO)
     *
     * 1. O estorno abate SÓ as despesas de cartão. Antes o piso 0 era aplicado por dia
     *    sobre a soma de tudo, e um estorno de compra de julho lançado em agosto
     *    engolia o mercado pago no débito no mesmo dia: agosto ficava com gasto 0.
     * 2. O piso 0 vale no AGREGADO do período do card — não por dia (dailySums), nem
     *    por mês (ano). Compra de 1.000 no dia 12 e estorno de 400 no dia 13 dão 600
     *    na semana, no mês e na barra de agosto; antes o card Mês dizia 1.000 (o −400
     *    do dia 13 virava 0 e sumia) e o Ano, 600 — e o trend de setembro usava 600.
     * 3. Σ buckets = stat, sempre: o stat é a soma da série, não uma query à parte.
     *
     * Distribuição nos buckets (o gráfico de barras não desenha valor negativo): o
     * estorno abate as compras de cartão dos buckets ANTERIORES do período, da mais
     * recente para trás (devolução é de algo comprado antes); o que sobrar abate os
     * buckets SEGUINTES (estorno lançado antes da compra, no mesmo período); e o que
     * ainda sobrar é o excedente do piso 0 — descartado. Assim nenhuma barra fica
     * negativa, nenhuma despesa de caixa é tocada e a soma fecha no líquido.
     *
     * @param  array<string, array{income: float, caixa: float, cartao: float}>  $diario
     * @param  callable(string): int  $bucketOf  índice do bucket de um dia 'Y-m-d'
     * @return array{income: list<float>, expense: list<float>}
     */
    private function seriesDeFluxo(array $diario, int $buckets, callable $bucketOf): array
    {
        $income = array_fill(0, $buckets, 0.0);
        $caixa = array_fill(0, $buckets, 0.0);
        $cartao = array_fill(0, $buckets, 0.0);

        foreach ($diario as $dia => $sums) {
            $i = (int) $bucketOf($dia);
            if ($i < 0 || $i >= $buckets) {
                continue;
            }
            $income[$i] = round($income[$i] + $sums['income'], 2);
            $caixa[$i] = round($caixa[$i] + $sums['caixa'], 2);
            $cartao[$i] = round($cartao[$i] + $sums['cartao'], 2);
        }

        $cartao = $this->abaterEstornos($cartao);

        $expense = [];
        for ($i = 0; $i < $buckets; $i++) {
            $expense[$i] = round($caixa[$i] + $cartao[$i], 2);
        }

        return ['income' => $income, 'expense' => $expense];
    }

    /**
     * Aplica os estornos (buckets negativos) sobre as compras de cartão (positivos)
     * do mesmo período — ver `seriesDeFluxo()`. Devolve buckets todos ≥ 0 cuja soma
     * é max(0, Σ entrada).
     *
     * @param  list<float>  $cartao  líquido do cartão por bucket, com sinal
     * @return list<float>
     */
    private function abaterEstornos(array $cartao): array
    {
        $sobra = 0.0; // estorno que não achou compra ANTERIOR para abater

        foreach ($cartao as $i => $valor) {
            if ($valor >= 0) {
                continue;
            }

            $deficit = -$valor;
            $cartao[$i] = 0.0;

            for ($j = $i - 1; $j >= 0 && $deficit > 0; $j--) {
                $abate = min($cartao[$j], $deficit);
                $cartao[$j] = round($cartao[$j] - $abate, 2);
                $deficit = round($deficit - $abate, 2);
            }

            $sobra = round($sobra + $deficit, 2);
        }

        // O que sobrou abate as compras seguintes; o resto é o piso 0 (descartado).
        foreach ($cartao as $i => $valor) {
            if ($sobra <= 0) {
                break;
            }
            $abate = min($valor, $sobra);
            $cartao[$i] = round($valor - $abate, 2);
            $sobra = round($sobra - $abate, 2);
        }

        return $cartao;
    }

    /**
     * Totais de receitas e despesas do intervalo (uma query) — usados como base do
     * período ANTERIOR nos trends. É `seriesDeFluxo()` com um bucket só: a mesma
     * regra do estorno, no agregado do período, sem tocar nas despesas de caixa.
     * Divergir daqui faria o trend comparar um agosto de 600 com um de 1.000.
     *
     * @return array{income: float, expense: float}
     */
    private function totals(int $userId, CarbonImmutable $from, CarbonImmutable $to, array $creditCardIds = []): array
    {
        $series = $this->seriesDeFluxo(
            $this->fluxoDiario($userId, $from, $to, $creditCardIds),
            1,
            fn () => 0,
        );

        return ['income' => $series['income'][0], 'expense' => $series['expense'][0]];
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

        return 'account_id IN ('.implode(',', array_map('intval', $accountIds)).')';
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
