<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Category;
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

        // (4) Recorrências legadas FORA do cartão (em aberto, data <= limite).
        //
        // Recorrência lançada num CARTÃO já foi contada no bloco (1)/(2): ela é
        // uma despesa do cartão como outra qualquer e entra em `openInvoiceDue`
        // /`overdueInvoice`. Sem este filtro, a mesma dívida aparecia duas vezes
        // no sino — uma como "Fatura Nubank", outra como "Streaming" — e o total
        // avisado ao usuário vinha inflado.
        $rec = Transaction::with('account')
            ->where('user_id', $userId)
            ->where('recurring', true)
            ->whereNull('paid_at')
            ->where('date', '<=', $limit->toDateString())
            ->whereDoesntHave('account', fn ($q) => $q->where('type', 'credit_card'))
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

        return $cartoes->map(function (Account $card) use ($pisosDePagamento) {
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
            ]);
        });
    }

    /**
     * Data da despesa mais antiga EM ABERTO de cada cartão, numa query só —
     * é o piso que o `PayInvoiceRequest` aplica ao `paid_on`. Serve para o
     * `min` do input de data não oferecer o que o servidor vai recusar.
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
            ->map(fn ($data) => CarbonImmutable::parse($data)->toDateString());
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
            ->where('date', '>', $start->toDateString())
            ->where('date', '<=', $end->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
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
