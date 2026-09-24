<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
use App\Models\CreditSettlement;
use App\Models\EndedRecurrence;
use App\Models\FixedBill;
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
        $contasFixas = app(FixedBillService::class)->currentAndOverdue($userId);

        // Contas fixas ainda EM ABERTO. O valor é o previsto da conta — conta de luz
        // varia, e o valor real só existe quando o pagamento acontece (é ele que vai
        // na transação). Então este total é uma ESTIMATIVA do que falta pagar, e a
        // tela precisa dizer isso: prometer exatidão num número que muda todo mês
        // seria mentir para quem se programa por ele.
        $fixasEmAberto = $contasFixas->where('paga', false);

        $stats = [
            // SÓ o que está EM ABERTO: fatura a pagar do ciclo aberto
            // (`openInvoiceDue`) + o que já fechou e não foi pago. Somar só o
            // ciclo aberto anunciava "R$ 0,00" a quem devia três faturas
            // atrasadas — a mesma dívida que sumia dos cards sumia do topo.
            // E somar `currentInvoice` (T-3, auditoria de 02/09/2026) contava
            // a fatura do ciclo aberto JÁ PAGA: o card dizia "Fatura paga" e o
            // topo seguia cobrando. É a mesma conta do dashboard
            // (`DashboardService::obrigacoesEmAberto`). As duas janelas são
            // disjuntas, então não há dupla contagem.
            'totalFaturas' => round(
                $cards->sum(fn ($c) => (float) $c['invoiceDue'] + (float) ($c['closedInvoice']['valor'] ?? 0)),
                2,
            ),
            'numCartoes' => $cards->count(),

            // Total das CONTAS FIXAS em aberto (o que ainda falta pagar) + quantas
            // são e quantas já venceram. Substituiu "Limite disponível" no topo: o
            // limite já aparece no card de cada cartão, e o que faltava na tela era
            // justamente o tamanho do que ainda tem de ser pago.
            'totalContas' => round((float) $fixasEmAberto->sum('valor'), 2),
            'numContas' => $fixasEmAberto->count(),
            'contasVencidas' => $fixasEmAberto->where('vencida', true)->count(),
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
            'contasFixas' => $contasFixas,
            // Cadastro/edição de conta fixa (select de categoria de despesa).
            'fixedBills' => FixedBill::where('user_id', $userId)
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
            // (1) Fatura JÁ FECHADA e não paga: entra no sino se já venceu ou se
            // vence dentro da janela. Sem este ramo a dívida sumia do sino no dia
            // em que o ciclo virava — o oposto de avisar que venceu.
            $fechada = $card->closedInvoice;
            $vencFechada = $fechada['vencimento'] ?? null;

            if ($fechada && $vencFechada && ($fechada['vencida'] || $vencFechada->lessThanOrEqualTo($limit))) {
                $itens->push(new Fluent([
                    'tipo' => 'fatura',
                    'nome' => 'Fatura '.$card->name,
                    'valor' => $fechada['valor'],
                    'due' => $vencFechada,
                    'diasRestantes' => $fechada['vencida']
                        ? -$fechada['diasAtraso']
                        : (int) $today->diffInDays($vencFechada, false),
                    'vencida' => $fechada['vencida'],
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
                'nome' => 'Fatura '.$card->name,
                'valor' => $devido,
                'due' => $due,
                'diasRestantes' => (int) $today->diffInDays($due, false),
                'vencida' => false,
            ]));
        }

        // (3) Contas fixas mensais: a competência do mês e todas as atrasadas.
        // Sem as relações (conta/categoria): o sino roda em TODA página e só lê
        // nome, valor e datas — ver `FixedBillService::occurrences`.
        foreach (app(FixedBillService::class)->currentAndOverdue($userId, comRelacoes: false) as $ocorrencia) {
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

        // (4) Recorrências legadas FORA do cartão (em aberto, data <= limite).
        //
        // Recorrência lançada num CARTÃO já foi contada no bloco (1)/(2): ela é
        // uma despesa do cartão como outra qualquer e entra em `openInvoiceDue`
        // /`overdueInvoice`. Sem este filtro, a mesma dívida aparecia duas vezes
        // no sino — uma como "Fatura Nubank", outra como "Streaming" — e o total
        // avisado ao usuário vinha inflado.
        //
        // Série ENCERRADA (`EndedRecurrence`) também fica de fora. Excluir pelo
        // Histórico uma ocorrência já paga com "Encerrar também a recorrência" deixa
        // de pé a que estava em aberto — e nela o "pagar" é recusado, porque a série
        // não gera mais nada. No sino ela viraria um aviso que nada resolve, e
        // "vencida" para sempre depois da data. A cobrança continua no saldo e no
        // Histórico, como qualquer despesa.
        $rec = Transaction::with('account')
            ->where('user_id', $userId)
            ->where('recurring', true)
            ->whereNull('paid_at')
            ->where('date', '<=', $limit->toDateString())
            ->whereDoesntHave('account', fn ($q) => $q->where('type', 'credit_card'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('ended_recurrences')
                ->whereColumn('ended_recurrences.user_id', 'transactions.user_id')
                ->whereColumn('ended_recurrences.group_id', 'transactions.group_id'))
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
        $cartoes = Account::where('user_id', $userId)
            ->where('type', 'credit_card')
            ->orderBy('name')
            ->get();

        // Dinheiro de todos os cartões em queries agregadas (regra do projeto:
        // nunca ler `committed` conta a conta dentro do laço).
        Account::preloadMoney($cartoes);

        $pisosDePagamento = $this->pisosDePagamento($cartoes);
        $quitacoesPeloCredito = $this->ultimasQuitacoesPeloCredito($cartoes);

        // Séries recorrentes ENCERRADAS da família (R2-2), numa query só — e não uma por
        // ocorrência da lista: é o que decide o "Lançar próxima" abaixo.
        $seriesEncerradas = EndedRecurrence::where('user_id', $userId)->pluck('group_id')->flip();

        return $cartoes->map(function (Account $card) use ($pisosDePagamento, $quitacoesPeloCredito, $seriesEncerradas) {
            $cycle = $card->billingCycle();
            $items = $this->cycleItems($card, $cycle);

            $limit = (float) $card->credit_limit;
            $available = $card->availableLimit;
            $used = round($limit - $available, 2);
            $usedPct = $limit > 0 ? (int) min(100, round($used / $limit * 100)) : 0;

            // Valor EM ABERTO (a pagar) do ciclo.
            $devido = $card->openInvoiceDue;

            // O lote que quitar a fatura do ciclo aberto alcança AGORA, pela mesma
            // régua de quem quita (`Account::linhasAQuitar`), e o líquido dele — é o
            // que decide o estado. `max(0, $liquido)` é o `$devido`.
            $lote = $card->linhasAQuitar('aberto');
            $liquido = Account::liquidoComSinal($lote);
            $estado = $this->estadoDaFatura($lote, $liquido, $items, $card->currentInvoice);

            // Fluent: a view acessa por -> (objeto) e os testes por []
            // (array) — Fluent suporta ambos (ArrayAccess + __get).
            return new Fluent([
                'account' => $card,
                'currentInvoice' => $card->currentInvoice,
                'invoiceDue' => $devido,          // o que falta pagar do ciclo
                'estado' => $estado,
                // "Fatura paga" só quando saiu dinheiro de uma conta (ver `estadoDaFatura`).
                'isPaid' => $estado === 'paga',
                'canPay' => $estado === 'a_pagar',
                // Crédito de estorno que sobra depois de cobrir a fatura inteira.
                'creditoSobrando' => $estado === 'credito_sobrando' ? -$liquido : 0.0,
                'committed' => $card->committed,
                'availableLimit' => $available,
                'limitUsedPct' => $usedPct,
                'dueDate' => $card->dueDate,
                'items' => $items,
                // Ids das linhas da lista que oferecem "Lançar próxima": recorrência já
                // quitada com a fatura dela (em aberto, a próxima consumiria limite antes
                // da hora) e de série NÃO encerrada. Numa série encerrada o servidor
                // recusa o clique (`FaturaController::pay`) — um botão que só existe
                // para ser recusado não pode estar na tela.
                'lancarProxima' => $items
                    ->filter(fn (Transaction $linha) => $linha->recurring
                        && $linha->paid_at !== null
                        && ! ($linha->group_id !== null && $seriesEncerradas->has($linha->group_id)))
                    ->pluck('id')
                    ->flip(),
                // Fatura JÁ FECHADA e não paga (valor, vencimento, se venceu e
                // há quantos dias). O ramo `ciclo=fechado` do payInvoice existia
                // desde a auditoria, mas NENHUMA tela o acionava: a fatura que
                // fechava ficava sem botão nenhum, impagável pela interface.
                'closedInvoice' => $card->closedInvoice,
                // Piso do campo "data do pagamento" (mesma regra do
                // PayInvoiceRequest: não se paga antes de a compra existir).
                'payFloor' => $pisosDePagamento[$card->id] ?? null,
                // Último pagamento de fatura deste cartão — é o que o botão
                // "Estornar" desfaz. Null quando nunca se pagou nada.
                'settlement' => $this->lastSettlement($card),
                // Última quitação PELO CRÉDITO de um estorno — o que o "Desfazer
                // quitação" desfaz. Independente do `settlement`: são lotes de
                // linhas diferentes, e desfazer um não mexe no outro.
                'quitacaoPeloCredito' => $quitacoesPeloCredito[$card->id] ?? null,
                // Recorrências cuja última ocorrência já FECHOU e ainda não têm
                // a ocorrência deste ciclo. É onde vive o botão "Lançar neste
                // ciclo": a ocorrência fechada não está na lista do ciclo aberto,
                // e sem este bloco a recorrência não teria como avançar.
                'recorrenciasParaAvancar' => $this->recorrenciasParaAvancar($card, $cycle),
            ]);
        });
    }

    /**
     * Data da despesa mais antiga EM ABERTO de cada cartão, numa query só —
     * é o piso que o `PayInvoiceRequest` aplica ao `paid_on`. Serve para o
     * `min` do input de data não oferecer o que o servidor vai recusar.
     *
     * Limitado a HOJE, como no request: a mais antiga em aberto pode ser a próxima
     * parcela, datada no futuro, e um `min` depois do `max` (hoje) deixava o campo
     * sem data possível (`PagarFaturaComParcelaDatadaNoFuturoTest`).
     *
     * @param  Collection<int, Account>  $cartoes
     * @return Collection<int, string>
     */
    private function pisosDePagamento(Collection $cartoes): Collection
    {
        if ($cartoes->isEmpty()) {
            return collect();
        }

        return Transaction::whereIn('account_id', $cartoes->pluck('id'))
            ->where('type', 'expense')
            ->whereNull('paid_at')
            ->groupBy('account_id')
            ->selectRaw('account_id')
            ->selectRaw('MIN(date) AS primeira')
            ->pluck('primeira', 'account_id')
            ->map(fn ($data) => min(CarbonImmutable::parse($data)->toDateString(), CarbonImmutable::today()->toDateString()));
    }

    /**
     * A saída de caixa mais recente que quitou uma fatura deste cartão.
     *
     * Só a última: estornar é "desfazer o que acabei de fazer". Deixar todo o
     * histórico de pagamentos estornável convidaria a desfazer um pagamento
     * antigo cujas compras já foram reprocessadas em outros ciclos.
     */
    private function lastSettlement(Account $card): ?Transaction
    {
        return Transaction::where('settles_account_id', $card->id)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * A quitação pelo crédito mais recente de cada cartão, numa query só — é o que o
     * "Desfazer quitação" desfaz. Só a última, como no `lastSettlement`: desfazer é
     * "desfazer o que acabei de fazer".
     *
     * @param  Collection<int, Account>  $cartoes
     * @return Collection<int, CreditSettlement> indexada pelo id do cartão
     */
    private function ultimasQuitacoesPeloCredito(Collection $cartoes): Collection
    {
        if ($cartoes->isEmpty()) {
            return collect();
        }

        return CreditSettlement::whereIn('id', CreditSettlement::query()
            ->selectRaw('MAX(id)')
            ->whereIn('account_id', $cartoes->pluck('id'))
            ->groupBy('account_id'))
            ->get()
            ->keyBy('account_id');
    }

    /**
     * Estado da fatura do ciclo aberto, dito com honestidade (R2-5 da auditoria
     * financeira, rodada 2):
     *
     *  - `a_pagar`: o lote tem o que cobrar ("Marcar como paga");
     *  - `coberta`: o estorno cobre EXATAMENTE as compras em aberto — nada a pagar,
     *    mas as linhas seguem em aberto até alguém encerrar ("Quitar pelo crédito");
     *  - `credito_sobrando`: o estorno cobre tudo e ainda sobra — as linhas ficam em
     *    aberto de propósito, levando o crédito para a próxima fatura;
     *  - `paga`: nada em aberto, e saiu dinheiro de uma conta para quitar o ciclo;
     *  - `quitada_pelo_credito`: nada em aberto, e o que o ciclo tem foi quitado só
     *    pelo crédito de um estorno — sem pagamento nenhum;
     *  - null: ciclo sem fatura.
     *
     * Antes "Fatura paga" era "tem gasto no ciclo e nada a pagar": um estorno do mês
     * passado cobrindo as compras deste aparecia como fatura PAGA, sem uma linha paga
     * nem pagamento nenhum no extrato.
     *
     * @param  Collection<int, Transaction>  $lote  as linhas que quitar o ciclo aberto alcança
     * @param  Collection<int, Transaction>  $itens  todas as linhas do ciclo aberto
     */
    private function estadoDaFatura(Collection $lote, float $liquido, Collection $itens, float $gastoDoCiclo): ?string
    {
        if ($lote->isNotEmpty()) {
            return match (true) {
                $liquido > 0 => 'a_pagar',
                $liquido < 0 => 'credito_sobrando',
                default => 'coberta',
            };
        }

        // Nada em aberto: como foi quitado o que o ciclo tem?
        if ($itens->contains(fn (Transaction $linha) => $linha->settled_by_id !== null)) {
            return 'paga';
        }
        if ($itens->contains(fn (Transaction $linha) => $linha->credit_settlement_id !== null)) {
            return 'quitada_pelo_credito';
        }

        // Linha paga sem registro de quitação nenhum (dado anterior a `settled_by_id`
        // e a `credit_settlement_id`): a tela diz o que sempre disse.
        return $gastoDoCiclo > 0.001 ? 'paga' : null;
    }

    /**
     * Itens (transações) do cartão dentro do ciclo aberto — date em
     * (cycleStart, cycleEnd]. Cada item carrega category, madeBy e o "badge".
     *
     * Entram DESPESAS e RECEITAS: a receita lançada num cartão é um ESTORNO —
     * abate a fatura, devolve limite e, sobrando, rola de ciclo
     * (`Account::closedInvoiceNet`). Antes só a despesa aparecia: a fatura
     * "encolhia" sem nenhuma linha explicando por quê, e o estorno não tinha
     * onde ser visto nem excluído.
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
            ->whereIn('type', ['expense', 'income'])
            ->where('date', '>', $start->toDateString())
            ->where('date', '<=', $end->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Última ocorrência de cada recorrência deste cartão que já pertence a um
     * ciclo FECHADO (date ≤ início do ciclo aberto) e ainda não tem sucessora.
     *
     * A próxima ocorrência só nasce por clique e só quando a atual já fechou
     * (`FaturaController::pay`), mas a ocorrência fechada não aparece na lista
     * do ciclo aberto — este é o lugar dela. Some no instante em que a
     * sucessora é lançada (ela passa a ser a última, e cai no ciclo aberto).
     *
     * Série ENCERRADA (excluída em /faturas — `EndedRecurrence`) fica de fora:
     * era aqui que a assinatura excluída ressuscitava (R2-2 da auditoria
     * financeira, rodada 2), com a ocorrência paga que sobrou oferecendo
     * "Lançar neste ciclo".
     *
     * A "mais recente de cada série" é calculada NO BANCO (V-4 da auditoria de
     * volume de 06/09/2026): antes vinham todas as ocorrências de todas as
     * recorrências do cartão desde sempre — 36 linhas por assinatura com três
     * anos de uso, hidratadas em PHP — só para ficar com uma de cada. Agora o
     * banco devolve, por série, a data da ocorrência mais recente (já filtrada
     * pelo início do ciclo aberto), e só as linhas dessa data são carregadas. O
     * resultado é o mesmo, na mesma ordem: data desc, id desc — e, se duas
     * ocorrências da série caírem na mesma data, fica a de id maior, como antes.
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $cycle
     * @return Collection<int, Transaction>
     */
    private function recorrenciasParaAvancar(Account $card, ?array $cycle): Collection
    {
        if (! $cycle) {
            return collect();
        }

        [$inicioAberto] = $cycle;

        // Data da ocorrência MAIS RECENTE de cada série, só das séries cuja mais
        // recente já fechou. `MAX(date)` devolve o valor gravado (texto no sqlite,
        // DATE no MySQL), então a junção abaixo casa pela igualdade exata.
        $ultimas = Transaction::query()
            ->select('group_id')
            ->selectRaw('MAX(date) AS ultima')
            ->where('account_id', $card->id)
            ->where('type', 'expense')
            ->where('recurring', true)
            ->whereNotNull('group_id')
            ->groupBy('group_id')
            ->havingRaw('MAX(date) <= ?', [$inicioAberto->toDateString()]);

        return Transaction::with(['category'])
            ->select('transactions.*')
            ->joinSub($ultimas, 'ultimas', fn ($join) => $join
                ->on('ultimas.group_id', '=', 'transactions.group_id')
                ->on('ultimas.ultima', '=', 'transactions.date'))
            ->where('transactions.account_id', $card->id)
            ->where('transactions.type', 'expense')
            ->where('transactions.recurring', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('ended_recurrences')
                ->whereColumn('ended_recurrences.user_id', 'transactions.user_id')
                ->whereColumn('ended_recurrences.group_id', 'transactions.group_id'))
            ->orderByDesc('transactions.date')
            ->orderByDesc('transactions.id')
            ->get()
            ->unique('group_id')           // empate de data na série: fica a de id maior
            ->values();
    }

    /**
     * Despesas avulsas: type=expense de contas que NÃO são cartão, no mês
     * corrente. Carregam category, account e madeBy (e o badge via accessor).
     *
     * A linha que QUITA uma fatura (settles_account_id) fica de fora: ela não é
     * um gasto novo — as compras que ela pagou já estão listadas no cartão. Sem
     * este filtro o mesmo dinheiro aparecia duas vezes na tela (igual ao que o
     * DashboardService já evita) e ainda oferecia um "x" para excluí-la.
     */
    private function accountExpenses(int $userId): Collection
    {
        $today = CarbonImmutable::today();

        return Transaction::with(['category', 'account', 'madeBy'])
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', 'expense')
            ->whereNull('transactions.settles_account_id')
            // A ponta de saída de uma transferência não é despesa avulsa: é o
            // mesmo dinheiro chegando noutra conta da família.
            ->whereNull('transactions.transfer_group_id')
            ->where('date', '>=', $today->startOfMonth()->toDateString())
            ->where('date', '<=', $today->endOfMonth()->toDateString())
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
