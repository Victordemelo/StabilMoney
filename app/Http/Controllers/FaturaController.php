<?php

namespace App\Http\Controllers;

use App\Http\Requests\PayInvoiceRequest;
use App\Http\Requests\StoreFaturaLaunchRequest;
use App\Models\Account;
use App\Models\CreditSettlement;
use App\Models\EndedRecurrence;
use App\Models\Transaction;
use App\Services\FaturaService;
use App\Services\FundingService;
use App\Support\Brl;
use App\Support\Texto;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Feature "Faturas / Despesas": faturas por cartão (com parcelas/recorrência)
 * e despesas avulsas em conta. Lançamento gera N transações (1 por parcela,
 * datada no mês dela) — decisão fechada. Compartilhado na família (ownerId).
 */
class FaturaController extends Controller
{
    use AuthorizesRequests;

    /** Resposta a qualquer tentativa de avançar uma série recorrente já excluída. */
    private const AVISO_SERIE_ENCERRADA = 'Esta recorrência foi excluída: nenhuma cobrança nova é lançada. As já pagas continuam no histórico.';

    public function index(Request $request, FaturaService $faturas)
    {
        return view('faturas.index', $faturas->build($request->user()->ownerId()));
    }

    /**
     * Cria a despesa: à vista (1 linha), parcelada (N linhas) ou recorrente
     * (1 ocorrência em aberto, datada no dia da compra). type=expense, família.
     */
    public function store(StoreFaturaLaunchRequest $request, FundingService $funding)
    {
        $data = $request->validated();
        $ownerId = $request->user()->ownerId();
        $madeBy = $data['made_by_user_id'] ?? $request->user()->id;

        $total = round((float) $data['amount'], 2);
        $base = CarbonImmutable::parse($data['date']);
        $mode = $data['mode'];
        $conta = Account::whereKey($data['account_id'])->firstOrFail();

        // IDEMPOTÊNCIA. Este era o único caminho de escrita de despesa sem
        // dedupe: um duplo clique lançava a compra duas vezes — e no parcelado,
        // as N parcelas duas vezes. Vem ANTES do guard de propósito: o reenvio de
        // algo já gravado não pode resgatar do investimento de novo.
        $clientUuid = $data['client_uuid'] ?? null;
        if ($clientUuid && Transaction::where('user_id', $ownerId)
            ->where('client_uuid', $clientUuid)
            ->exists()
        ) {
            return redirect()->route('faturas.index')
                ->with('status', 'Esta despesa já tinha sido lançada.');
        }

        $common = [
            'user_id' => $ownerId,
            'made_by_user_id' => $madeBy,
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'] ?? null,
            'type' => 'expense',
            'description' => $data['description'],
            // Só a PRIMEIRA linha carrega o uuid: o índice é único em
            // (user_id, client_uuid), então repetir nas 12 parcelas estouraria.
            'client_uuid' => $clientUuid,
        ];

        // Todo lançamento passa pelo guard: em conta de caixa ele checa o saldo
        // (e pergunta a fonte quando falta); em cartão, o limite de crédito.
        // No parcelado o que pesa é o TOTAL da compra — é ele que fica retido.
        $fonte = $data['funding_source'] ?? null;
        $investimentoId = isset($data['funding_investment_id']) ? (int) $data['funding_investment_id'] : null;
        // Teto do resgate aprovado no modal — instrução para o guard, nunca coluna.
        $maxFonte = isset($data['funding_max_amount']) ? (float) $data['funding_max_amount'] : null;

        try {
            $funding->spend(
                account: $conta,
                amount: $total,
                source: $fonte,
                investmentId: $investimentoId,
                maxFonte: $maxFonte,
                write: function (array $auditoria) use ($mode, $common, $total, $base, $data, $conta) {
                    if ($mode === 'parcelado') {
                        return $this->createInstallments($common + $auditoria, $total, $base, (int) $data['installments'], $conta);
                    }

                    if ($mode === 'recorrente') {
                        return $this->createRecurring($common + $auditoria, $total, $base, $conta);
                    }

                    return Transaction::create($common + $auditoria + [
                        'amount' => $total,
                        'date' => $base->toDateString(),
                    ]);
                },
                madeByUserId: $madeBy,
                date: $base->toDateString(),
            );
        } catch (UniqueConstraintViolationException) {
            // Duas requisições idênticas em paralelo passaram juntas pela checagem
            // acima; o índice único (user_id, client_uuid) barrou a segunda. A
            // transação inteira foi desfeita, então basta responder como duplicata.
            return redirect()->route('faturas.index')
                ->with('status', 'Esta despesa já tinha sido lançada.');
        }

        $status = match ($mode) {
            'parcelado' => 'Despesa parcelada lançada na fatura.',
            'recorrente' => 'Despesa recorrente lançada na fatura.',
            default => 'Despesa lançada com sucesso.',
        };

        return redirect()->route('faturas.index')->with('status', $status);
    }

    /**
     * Remove a COMPRA inteira: se a transação faz parte de um grupo
     * (parcelada/recorrente), apaga todas as linhas EM ABERTO do grupo; senão, só ela.
     * Escopo de família garantido pela TransactionPolicy.
     *
     * Numa série RECORRENTE, excluir também a ENCERRA (`EndedRecurrence`) — ver
     * `encerrarSerie()`.
     */
    public function destroy(Transaction $transaction, FundingService $funding)
    {
        $this->authorize('delete', $transaction);

        // TRANSFERÊNCIA entre contas não se apaga por aqui (R2-1 da auditoria
        // financeira, rodada 2). Esta rota apaga UMA linha — ou as parcelas de um
        // grupo —, e uma transferência são DUAS, ligadas por `transfer_group_id`:
        // apagar só a entrada sumia com o dinheiro do destino (e deixava de pé o
        // resgate que financiou a saída); apagar só a saída criava dinheiro na
        // origem. A tela nem lista as pontas: chegar aqui com uma delas é URL
        // montada à mão, com o id visto no Histórico.
        //
        // Recusar, e não apagar as duas pontas aqui também: o Histórico
        // (`TransactionController::destroy`) é o ÚNICO caminho que desfaz uma
        // transferência, com a trava e o estorno da fonte na mesma transação. Uma
        // segunda cópia dessa lógica seria mais um lugar que apaga dinheiro para
        // manter em sincronia — e foi justamente um `destroy` ficar para trás do
        // outro que abriu este buraco. Mesmo padrão das duas guardas abaixo, que
        // também apontam o caminho certo em vez de repetir a lógica dele.
        if ($transaction->isTransferencia()) {
            return back()->withErrors([
                'transaction' => 'Esta linha é uma das pontas de uma transferência entre contas e não pode ser excluída por aqui: apagar só um lado faria o dinheiro sumir de uma conta ou aparecer do nada na outra. Para desfazer a transferência, exclua pelo Histórico — as duas pontas saem juntas.',
            ]);
        }

        // Quitação de fatura NÃO se apaga isolada. Pagar a fatura escreve N+1
        // linhas (marca as compras do ciclo com paid_at + cria esta saída de
        // caixa). Apagar só esta devolveria o dinheiro à conta E deixaria as
        // compras quitadas — dinheiro criado em dobro (saldo + limite de volta).
        if ($transaction->settles_account_id) {
            return back()->withErrors([
                'transaction' => 'Esta linha é o pagamento de uma fatura de cartão e não pode ser excluída sozinha. Para desfazer, use "Estornar" no cartão — assim as compras voltam a ficar em aberto.',
            ]);
        }

        // Série RECORRENTE (grupo com `recurring`): excluir é ENCERRAR a série.
        $serieRecorrente = $transaction->group_id !== null && (bool) $transaction->recurring;

        // Compra de CARTÃO já quitada também não se apaga. O ramo do grupo já
        // protegia as parcelas pagas (`whereNull('paid_at')`), mas a compra
        // avulsa caía direto no `delete()`: a saída de caixa que pagou aquela
        // fatura continuava lá, sem a compra que a originou — dívida apagada,
        // dinheiro debitado, e o estorno do pagamento deixaria de reencontrar a
        // compra. A saída correta é estornar o pagamento e só então excluir.
        //
        // A série recorrente toda paga NÃO é recusada: nela o destroy não apaga
        // linha paga nenhuma (só as em aberto, que aqui são zero) — só a encerra.
        // Recusar mandava "estornar o pagamento primeiro", ou seja, desfazer uma
        // cobrança que aconteceu de verdade só para conseguir parar as próximas.
        if (! $serieRecorrente && $this->cartaoComTudoQuitado($transaction)) {
            return back()->withErrors([
                'transaction' => $this->quitadaSoPeloCredito($transaction)
                    ? CreditSettlement::recusaParaLinha($transaction, 'excluir')
                    : 'Esta compra já foi paga junto com a fatura do cartão e não pode ser excluída. Use "Estornar pagamento" no cartão primeiro — a compra volta a ficar em aberto e aí sim pode ser removida.',
            ]);
        }

        $status = DB::transaction(function () use ($transaction, $funding, $serieRecorrente) {
            if ($transaction->group_id) {
                // Recorrência de CARTÃO: trava a conta ANTES de ler o que apagar. É a
                // mesma trava que o `gerarProximaOcorrencia` pega (via FundingService)
                // antes de lançar a sucessora, então os dois se enfileiram: ou o
                // clique em "Lançar neste ciclo" termina antes (e a ocorrência que ele
                // criou entra na lista abaixo e é apagada junto), ou espera o
                // encerramento e desiste. Mesma ordem nos dois caminhos — conta →
                // linhas da série —, então não há deadlock entre eles.
                if ($serieRecorrente && $transaction->account?->type === 'credit_card') {
                    Account::whereKey($transaction->account_id)->lockForUpdate()->first();
                }

                // Parcelas JÁ PAGAS não são apagadas: o pagamento delas existe no extrato
                // (saiu dinheiro de verdade), e apagar a dívida deixaria a saída de caixa
                // órfã — histórico financeiro não se reescreve. Some só o que ainda é dívida.
                $emAberto = Transaction::where('user_id', $transaction->user_id)
                    ->where('group_id', $transaction->group_id)
                    ->whereNull('paid_at')
                    ->pluck('id');

                // Estorna a fonte ANTES de apagar: o delete em massa abaixo não
                // dispara evento nenhum, então este é o único momento em que os
                // resgates que financiaram as parcelas podem ser desfeitos.
                $funding->estornarFonte($emAberto);

                $apagadas = Transaction::whereIn('id', $emAberto)->delete();

                $restaram = Transaction::where('user_id', $transaction->user_id)
                    ->where('group_id', $transaction->group_id)
                    ->count();

                if ($serieRecorrente) {
                    return $this->encerrarSerie($transaction, $apagadas, $restaram);
                }

                return $restaram > 0
                    ? "Parcelas em aberto removidas ({$apagadas}). As já pagas foram mantidas no histórico."
                    : 'Compra removida (todas as parcelas).';
            }

            $funding->estornarFonte([$transaction->id]);
            $transaction->delete();

            return 'Despesa removida.';
        });

        return redirect()->route('faturas.index')->with('status', $status);
    }

    /**
     * A exclusão não tem mais nada a apagar porque está tudo quitado no cartão?
     *
     * Vale para a compra avulsa (ela mesma paga) e para o parcelado cujas
     * parcelas já foram todas pagas — neste último, o `whereNull('paid_at')` do
     * `destroy` apagaria zero linhas e ainda anunciaria "Parcelas em aberto
     * removidas (0)". Fora do cartão o caso é outro: em conta corrente a despesa
     * já descontou do saldo no lançamento, `paid_at` ali é só registro, e apagar
     * devolve o dinheiro corretamente.
     */
    private function cartaoComTudoQuitado(Transaction $transaction): bool
    {
        if ($transaction->account?->type !== 'credit_card') {
            return false;
        }

        if ($transaction->group_id) {
            return ! Transaction::where('user_id', $transaction->user_id)
                ->where('group_id', $transaction->group_id)
                ->whereNull('paid_at')
                ->exists();
        }

        return $transaction->paid_at !== null || $transaction->settled_by_id !== null;
    }

    /**
     * O que está quitado foi quitado SÓ pelo crédito de um estorno — nenhum pagamento
     * em caixa? Decide o caminho que a recusa aponta: "Estornar pagamento" desfaz a
     * quitação em caixa, e a quitação pelo crédito se desfaz em "Desfazer quitação"
     * (R2-4 da auditoria financeira, rodada 2). No parcelado, olha as parcelas pagas.
     */
    private function quitadaSoPeloCredito(Transaction $transaction): bool
    {
        $quitadas = $transaction->group_id
            ? Transaction::where('user_id', $transaction->user_id)
                ->where('group_id', $transaction->group_id)
                ->whereNotNull('paid_at')
                ->get(['credit_settlement_id'])
            : collect([$transaction]);

        return $quitadas->isNotEmpty()
            && $quitadas->every(fn (Transaction $linha) => $linha->credit_settlement_id !== null);
    }

    /**
     * Encerra a série recorrente cujas ocorrências em aberto o `destroy` acabou de
     * apagar (R2-2 da auditoria financeira, rodada 2). Roda na MESMA transação.
     *
     * Sem o marcador, a ocorrência paga mais recente continuava sendo "a última da
     * série": voltava em "Recorrente · Lançar neste ciclo" e um clique recriava a
     * sucessora — a assinatura excluída ressuscitava. O marcador fica numa tabela à
     * parte e não toca em NENHUMA linha da série: as pagas seguem idênticas (valor,
     * `paid_at`, `settled_by_id`, selo "Recorrente") e nenhum saldo, limite ou
     * fatura muda.
     *
     * Sem ocorrência que tenha sobrado não há o que ressuscitar — nem marcador a
     * guardar.
     */
    private function encerrarSerie(Transaction $ocorrencia, int $apagadas, int $restaram): string
    {
        if ($restaram === 0) {
            return 'Recorrência removida (todas as cobranças).';
        }

        // `createOrFirst`: dois cliques em "excluir" encerram uma vez só (índice
        // único em user_id + group_id).
        EndedRecurrence::createOrFirst(
            ['user_id' => $ocorrencia->user_id, 'group_id' => $ocorrencia->group_id],
            ['ended_by_user_id' => auth()->id()],
        );

        return $apagadas > 0
            ? 'Recorrência excluída: '.$apagadas.' '
                .($apagadas === 1 ? 'cobrança em aberto removida' : 'cobranças em aberto removidas')
                .'. As já pagas continuam no histórico, e nenhuma nova será lançada.'
            : 'Recorrência encerrada: nenhuma cobrança nova será lançada. As já pagas continuam no histórico.';
    }

    /**
     * Marca a fatura EM ABERTO de um cartão de crédito como paga: as despesas
     * não pagas do ciclo ganham `paid_at`, e uma despesa do valor total é criada
     * na conta de caixa escolhida (corrente/poupança) — é o que DESCONTA do saldo.
     *
     * Só cartão de crédito: débito/Pix/conta já descontam no ato da compra.
     */
    public function payInvoice(PayInvoiceRequest $request, Account $account, FundingService $funding)
    {
        $this->authorize('update', $account); // escopo de família (AccountPolicy)
        abort_unless($account->isCard(), 403, 'Só cartão de crédito tem fatura para marcar como paga.');

        $ownerId = $account->user_id;
        $data = $request->validated();
        // Data em que o pagamento realmente aconteceu (permite quitar em atraso
        // e registrar o dia certo). Default: hoje.
        $pagoEm = CarbonImmutable::parse($data['paid_on'] ?? now()->toDateString());

        // Qual fatura pagar: a do ciclo ABERTO (padrão) ou TUDO que já fechou.
        //
        // A fatura fechada e vencida não tinha caminho de pagamento nenhum: `payInvoice`
        // só conhecia `billingCycle()`, então respondia "já estava quitada" e a dívida
        // ficava impagável — comendo o limite do cartão para sempre.
        //
        // `closedCycle()` hoje devolve a janela do que já fechou INTEIRA (ver o
        // model): com dois ou três ciclos sem pagar, um pagamento quita a dívida
        // acumulada, como a fatura de verdade — em que o saldo rola de mês a mês.
        $ciclo = ($data['ciclo'] ?? 'aberto') === 'fechado' ? 'fechado' : 'aberto';

        if (! $account->billingCycle()) {
            return redirect()->route('faturas.index');
        }

        // Linhas EM ABERTO da janela (pré-checagem, fora do lock). Soma COM SINAL:
        // estorno (income) lançado no cartão abate a fatura, como no mundo real.
        // Somando tudo como despesa, uma compra de 1.000 com estorno de 300 cobrava
        // 1.300 do caixa — e o `committed` do cartão, que já considera o sinal,
        // discordaria do valor cobrado.
        $abertas = $account->linhasAQuitar($ciclo);
        $total = Account::liquidoComSinal($abertas);

        // Líquido ≤ 0: não há o que cobrar do caixa. Ver o desenho do crédito de
        // estorno em `Account::getOpenInvoiceDueAttribute`.
        if ($total <= 0) {
            return $this->quitarPeloCredito($request, $account, $ciclo, $pagoEm);
        }

        $caixa = Account::whereKey($data['pay_account_id'])->firstOrFail();

        // Pagar fatura é uma saída de caixa como outra qualquer: respeita o
        // saldo e, se faltar, pergunta a fonte (cheque especial ou resgate).
        $funding->spend(
            account: $caixa,
            amount: $total,
            source: $data['funding_source'] ?? null,
            investmentId: isset($data['funding_investment_id']) ? (int) $data['funding_investment_id'] : null,
            // Teto do resgate aprovado no modal: estourou, o guard devolve 409
            // com as opções recalculadas em vez de sacar mais do investimento.
            maxFonte: isset($data['funding_max_amount']) ? (float) $data['funding_max_amount'] : null,
            write: function (array $auditoria) use ($ownerId, $request, $data, $account, $pagoEm, $ciclo) {
                // A LEITURA AUTORITATIVA é esta, sob lock e dentro da transação.
                //
                // Antes, a lista e o total vinham de fora e a saída de caixa era criada
                // incondicionalmente: com 4 POSTs paralelos, uma fatura de R$ 300 gerava
                // 4 pagamentos de R$ 300 (a conta ia a −R$ 1.100). O relock existia, mas
                // só decidia o que MARCAR como pago — nunca se havia o que pagar.
                $abertasAgora = $account->linhasAQuitar($ciclo, travar: true);

                // Mesma soma com sinal da pré-checagem: o estorno abate.
                $totalAgora = Account::liquidoComSinal($abertasAgora);

                // Outra requisição pagou primeiro: nada a fazer, e nada a debitar.
                if ($totalAgora <= 0) {
                    return null;
                }

                // A QUITAÇÃO é criada ANTES de marcar as compras, para que cada
                // compra possa apontar para ela (settled_by_id). É esse vínculo
                // que torna o estorno exato — sem ele o estorno teria de adivinhar
                // quais compras pertenciam a este pagamento.
                $quitacao = Transaction::create($auditoria + [
                    'user_id' => $ownerId,
                    'made_by_user_id' => $request->user()->id,
                    'account_id' => $data['pay_account_id'],
                    'type' => 'expense',
                    // Valor do que foi REALMENTE marcado agora, não o lido antes do lock.
                    'amount' => $totalAgora,
                    'date' => $pagoEm->toDateString(),
                    'paid_at' => $pagoEm,
                    // O nome do cartão aceita 255 caracteres e a coluna também: com o
                    // prefixo, um nome longo estourava o `varchar(255)` e o MySQL
                    // respondia 1406 — HTTP 500 no pagamento (M-1). Encurta só o nome.
                    'description' => Texto::paraColuna($account->name, antes: 'Pagamento da fatura — '),
                    // Marca a linha como QUITAÇÃO, não gasto novo: o dinheiro sai (entra
                    // no saldo e no extrato), mas o gasto já foi contado quando a compra
                    // entrou no cartão. Sem isto o dashboard somava os dois e dobrava a
                    // despesa do período.
                    'settles_account_id' => $account->id,
                ]);

                Transaction::whereIn('id', $abertasAgora->pluck('id'))
                    ->update(['paid_at' => $pagoEm, 'settled_by_id' => $quitacao->id]);

                return $quitacao;
            },
            madeByUserId: $request->user()->id,
            date: $pagoEm->toDateString(),
            // Fatura é dívida já contraída: se não houver cheque especial nem
            // investimento, o pagamento passa e a conta fica negativa. O app
            // não recusa um boleto — mas também nunca usa o cheque especial
            // sozinho: quando há escolha, ela é feita pelo usuário (409).
            obrigacao: true,
        );

        $saldo = $caixa->fresh()->available;
        $aviso = $saldo < 0
            ? 'Fatura paga. Atenção: a conta '.$caixa->name.' ficou em '.Brl::format($saldo).'.'
            : 'Fatura marcada como paga.';

        return redirect()->route('faturas.index')->with('status', $aviso);
    }

    /**
     * "Quitar pelo crédito": encerra a fatura que um estorno cobriu EXATAMENTE
     * (R2-3 e R2-5 da auditoria financeira, rodada 2).
     *
     * Com líquido zero não há o que pagar, então "Marcar como paga" não aparece — e
     * nada mais encerrava a fatura: as linhas ficavam em aberto para sempre (o piso da
     * "data do pagamento" preso na compra mais antiga) e o card chegava a dizer "Fatura
     * paga" sem ninguém ter pago nada. É o mesmo desfecho do `payInvoice` numa fatura
     * de líquido zero, sem pedir uma conta de caixa que não vai ser usada.
     */
    public function quitarFaturaPeloCredito(Request $request, string $cartao)
    {
        return $this->quitarPeloCredito($request, $this->cartaoDaFamilia($request, $cartao), 'aberto', CarbonImmutable::today());
    }

    /**
     * Fatura cujo líquido é ≤ 0: nada sai do caixa. Vale para o `payInvoice` (a
     * pré-checagem deu ≤ 0) e para o "Quitar pelo crédito".
     *
     *  - Líquido = 0: o estorno cobriu as compras exatamente. As linhas ganham
     *    `paid_at` sem quitação em caixa (não houve saída) e apontam para uma
     *    `CreditSettlement` — o registro que o "Desfazer quitação" usa. Sem ele a
     *    quitação era irreversível: a compra quitada assim não podia mais ser
     *    excluída, porque a trava mandava estornar um pagamento que não existe (R2-4).
     *  - Líquido < 0: ainda sobra crédito. As linhas FICAM em aberto de
     *    propósito — são elas que carregam o crédito para a fatura seguinte
     *    (`Account::closedInvoiceNet`), onde serão quitadas junto com as compras
     *    que ele abater. Marcar só as despesas como pagas deixaria o estorno
     *    inteiro em aberto e o crédito contaria em dobro; marcar tudo apagaria o
     *    crédito. Nenhuma linha nova é criada: uma "linha de crédito" sintética
     *    entraria no dashboard como um segundo estorno.
     *  - Líquido > 0: há o que pagar — só por "Marcar como paga", com uma conta.
     *
     * Tudo é relido SOB A TRAVA do cartão: é a mesma que o lançamento de uma compra
     * nova pega (`FundingService`), então uma compra não entra no meio da quitação, e
     * dois cliques seguidos quitam uma vez só — o segundo já não acha nada em aberto.
     *
     * Deadlock vira nova tentativa, não HTTP 500. Repetir o fechamento inteiro é seguro:
     * ele relê tudo sob a trava, cria a quitação do zero e grava pelo query builder —
     * não reusa model que uma tentativa desfeita tenha dado por gravado (a armadilha do
     * `FundingService::repetirNoDeadlock`). E não roda dentro de outra transação.
     */
    private function quitarPeloCredito(Request $request, Account $cartao, string $ciclo, CarbonImmutable $pagoEm)
    {
        [$desfecho, $liquido] = DB::transaction(function () use ($request, $cartao, $ciclo, $pagoEm) {
            Account::whereKey($cartao->id)->lockForUpdate()->first();

            $linhas = $cartao->linhasAQuitar($ciclo, travar: true);
            $liquido = Account::liquidoComSinal($linhas);

            if ($linhas->isEmpty()) {
                return ['vazia', 0.0];
            }
            if ($liquido != 0) {
                return [$liquido > 0 ? 'a_pagar' : 'sobra_credito', $liquido];
            }

            $quitacao = CreditSettlement::create([
                'user_id' => $cartao->user_id,
                'account_id' => $cartao->id,
                'made_by_user_id' => $request->user()->id,
                'paid_at' => $pagoEm,
            ]);

            Transaction::whereIn('id', $linhas->pluck('id'))
                ->whereNull('paid_at')
                ->update(['paid_at' => $pagoEm, 'credit_settlement_id' => $quitacao->id]);

            return ['quitada', 0.0];
        }, attempts: FundingService::TENTATIVAS);

        $voltar = redirect()->route('faturas.index');

        return match ($desfecho) {
            'quitada' => $voltar->with('status', 'Fatura quitada pelo crédito do estorno — nada saiu da conta. '
                .'Se precisar corrigir uma dessas compras, use "Desfazer quitação" no cartão '.$cartao->name.'.'),
            'sobra_credito' => $voltar->with('status', 'Nada a pagar: o crédito do estorno cobre esta fatura e ainda sobram '
                .Brl::format(-$liquido).', que abatem a próxima fatura do '.$cartao->name.'. Nenhum dinheiro saiu da conta.'),
            'a_pagar' => $voltar->withErrors(['transaction' => 'Esta fatura não está coberta pelo estorno: ainda faltam '
                .Brl::format($liquido).'. Use "Marcar como paga" para pagar com uma conta.']),
            default => $voltar->with('status', 'Esta fatura já estava quitada.'),
        };
    }

    /**
     * DESFAZ uma quitação pelo crédito — o "Estornar pagamento" dela (R2-4 da auditoria
     * financeira, rodada 2).
     *
     * As linhas daquela quitação voltam a ficar EM ABERTO (`paid_at` e o vínculo em
     * null) e o registro some. Nenhum dinheiro se move nos dois sentidos: não saiu nada
     * de conta nenhuma na quitação, e as linhas se anulam, então nem o limite do cartão
     * muda. É o que devolve à compra a chance de ser corrigida ou excluída.
     *
     * Sob a mesma trava do cartão que a quitação pegou. Dois cliques desfazem uma vez:
     * o segundo não acha mais linha nenhuma apontando para ela. Deadlock repete, pelo
     * mesmo motivo do `quitarPeloCredito`: só query builder dentro, nada reusado.
     */
    public function desfazerQuitacaoPeloCredito(Request $request, string $quitacao)
    {
        // Quitação de outra família recebe o MESMO 404 de um id que não existe: uma
        // diferença entre as duas respostas diria a quem sonda que aquele id existe.
        $registro = CreditSettlement::where('user_id', $request->user()->ownerId())->findOrFail($quitacao);

        $voltaram = DB::transaction(function () use ($registro) {
            Account::whereKey($registro->account_id)->lockForUpdate()->first();

            $voltaram = Transaction::where('credit_settlement_id', $registro->id)
                ->update(['paid_at' => null, 'credit_settlement_id' => null]);

            CreditSettlement::whereKey($registro->id)->delete();

            return $voltaram;
        }, attempts: FundingService::TENTATIVAS);

        return redirect()->route('faturas.index')->with(
            'status',
            $voltaram === 0
                ? 'Esta quitação já tinha sido desfeita.'
                : 'Quitação pelo crédito desfeita: '.$voltaram.' '.($voltaram === 1 ? 'linha voltou' : 'linhas voltaram')
                    .' para a fatura em aberto. Nenhum dinheiro entrou nem saiu da conta.',
        );
    }

    /**
     * O cartão de crédito da FAMÍLIA com este id — ou o 404 de um id que não existe.
     * Cartão de outra família, conta que não é cartão e id inexistente recebem a
     * MESMA resposta: uma diferença entre elas diria a quem sonda que aquele id existe.
     */
    private function cartaoDaFamilia(Request $request, string $id): Account
    {
        return Account::where('user_id', $request->user()->ownerId())
            ->where('type', 'credit_card')
            ->findOrFail($id);
    }

    /**
     * ESTORNA o pagamento de uma fatura — desfaz exatamente o que `payInvoice` fez.
     *
     * Marcar como paga era irreversível: quem clicava no cartão errado (ou na
     * conta errada) ficava com uma saída de caixa que não dá para apagar (a
     * trava do `destroy` existe justamente para não criar dinheiro em dobro) e
     * com a fatura quitada sem ter pago nada. A única saída era mexer no banco.
     *
     * O estorno faz as três coisas juntas, numa transação só:
     *  1. as compras daquele pagamento voltam a ficar EM ABERTO (`paid_at` null),
     *     então voltam para a fatura e voltam a consumir limite;
     *  2. o resgate de investimento que financiou o pagamento, se houve, é desfeito;
     *  3. a saída de caixa é apagada — o dinheiro volta para a conta.
     */
    public function estornarFatura(Transaction $transaction, FundingService $funding)
    {
        $this->authorize('delete', $transaction);

        if (! $transaction->settles_account_id) {
            return back()->withErrors([
                'transaction' => 'Esta linha não é o pagamento de uma fatura.',
            ]);
        }

        $compras = Transaction::where('settled_by_id', $transaction->id)->pluck('id');

        // Pagamentos feitos ANTES de existir o vínculo `settled_by_id` não têm
        // como ser rastreados linha a linha; o par (cartão, instante do
        // pagamento) é o que resta, e é exato — `payInvoice` grava o MESMO
        // `paid_at` em todas as compras do lote. Linha quitada pelo CRÉDITO no
        // mesmo dia tem o mesmo `paid_at`, mas não é deste pagamento: ela se
        // desfaz pela quitação dela (`desfazerQuitacaoPeloCredito`).
        if ($compras->isEmpty() && $transaction->paid_at) {
            $compras = Transaction::where('account_id', $transaction->settles_account_id)
                ->whereNull('settled_by_id')
                ->whereNull('credit_settlement_id')
                ->where('paid_at', $transaction->paid_at)
                ->pluck('id');
        }

        DB::transaction(function () use ($transaction, $compras, $funding) {
            Transaction::whereIn('id', $compras)
                ->update(['paid_at' => null, 'settled_by_id' => null]);

            // Se o pagamento saiu de um resgate de investimento, o resgate volta
            // atrás junto — senão o aplicado ficaria menor sem despesa nenhuma.
            $funding->estornarFonte([$transaction->id]);

            $transaction->delete();
        });

        return redirect()->route('faturas.index')->with(
            'status',
            $compras->isEmpty()
                ? 'Pagamento estornado. O valor voltou para a conta.'
                : 'Pagamento estornado: '.$compras->count().' '.($compras->count() === 1 ? 'compra voltou' : 'compras voltaram').' para a fatura em aberto.',
        );
    }

    /**
     * Parcelado em N: uma transação por CICLO de fatura (não por mês-calendário).
     *
     * A parcela N cai no N-ésimo ciclo a partir do ciclo da compra. A 1ª mantém
     * a data da compra; as demais partem do candidato "mesmo dia, N−1 meses
     * depois" e, se ele ainda cair no ciclo da parcela anterior, são empurradas
     * para o primeiro dia do ciclo seguinte (fechamento + 1).
     *
     * Antes era só `addMonthsNoOverflow`: cartão que fecha dia 28 com compra em
     * 30/01 datava a 2ª parcela em 28/02 — dentro do MESMO ciclo (28/01..28/02]
     * da 1ª. Fevereiro cobrava duas parcelas e março nenhuma (F-1, auditoria
     * de 02/09/2026). Ocorria com compra em 29, 30 e 31/01.
     */
    private function createInstallments(array $common, float $total, CarbonImmutable $base, int $n, Account $card): Transaction
    {
        $groupId = (string) Str::uuid();
        $primeira = null;

        // Rateio em CENTAVOS INTEIROS. O jeito anterior — dividir, arredondar e jogar a
        // sobra na última parcela — produzia parcela NEGATIVA quando o arredondamento
        // subia: R$ 0,36 em 24x dava 0,02 por parcela (0,46 no total) e a última virava
        // −R$ 0,10. Linha negativa devolve limite do cartão e viola "dinheiro nunca é
        // negativo, o sinal vem do type".
        //
        // Aqui o resto é distribuído um centavo por vez nas PRIMEIRAS parcelas: a soma
        // fecha exata, nenhuma parcela fica negativa, e a diferença entre a maior e a
        // menor nunca passa de um centavo.
        $centavos = (int) round($total * 100);
        $porParcela = intdiv($centavos, $n);
        $sobra = $centavos - ($porParcela * $n);   // 0 .. n-1

        // Atômico: as N parcelas entram juntas ou nenhuma — sem fatura "pela metade".
        // (Já roda dentro da transação do FundingService; aninhar é seguro.)
        // Da 2ª parcela em diante o uuid sai: ele identifica a COMPRA, e o índice
        // único (user_id, client_uuid) só admite uma linha por uuid.
        $semUuid = Arr::except($common, 'client_uuid');

        $datas = $this->datasDasParcelas($base, $n, $card);

        DB::transaction(function () use ($common, $semUuid, $datas, $n, $groupId, $porParcela, $sobra, &$primeira) {
            for ($i = 1; $i <= $n; $i++) {
                // As `$sobra` primeiras parcelas levam 1 centavo a mais.
                $amount = ($porParcela + ($i <= $sobra ? 1 : 0)) / 100;

                $linha = Transaction::create(($i === 1 ? $common : $semUuid) + [
                    'amount' => $amount,
                    'date' => $datas[$i - 1]->toDateString(),
                    'group_id' => $groupId,
                    'installment_no' => $i,
                    'installments' => $n,
                ]);

                $primeira ??= $linha;
            }
        });

        return $primeira;
    }

    /**
     * Datas das N parcelas, uma por ciclo de fatura (ver `createInstallments`).
     * Sem dia de fechamento (não deveria acontecer: parcelado é só cartão),
     * cai no mês-calendário de antes.
     *
     * @return list<CarbonImmutable>
     */
    private function datasDasParcelas(CarbonImmutable $base, int $n, Account $card): array
    {
        $datas = [$base];
        // Fim do ciclo em que a parcela anterior caiu.
        $fechamentoAnterior = $card->billingCycle($base)[1] ?? null;

        for ($i = 2; $i <= $n; $i++) {
            $datas[] = $data = $this->dataNoCicloSeguinte($base, $i - 1, $fechamentoAnterior, $card);
            $fechamentoAnterior = $card->billingCycle($data)[1] ?? null;
        }

        return $datas;
    }

    /**
     * A data da ocorrência que vem `$meses` meses depois da compra original,
     * garantindo que ela caia num ciclo DEPOIS do ciclo cujo fechamento é
     * `$fechamentoAnterior` (a regra "uma por ciclo" das parcelas, reaproveitada
     * pela recorrência de cartão).
     *
     * NoOverflow: compra em 31/01 gera 28/02, não 03/03. Com o addMonths puro do
     * Carbon, fevereiro ficava sem parcela e março levava duas. E se o candidato
     * ainda cair no ciclo anterior (cartão que fecha dia 28, compra em 30/01 →
     * 28/02 está no mesmo ciclo), ele é empurrado para o primeiro dia do ciclo
     * seguinte (fechamento + 1).
     */
    private function dataNoCicloSeguinte(CarbonImmutable $base, int $meses, ?CarbonImmutable $fechamentoAnterior, Account $card): CarbonImmutable
    {
        $candidata = $base->addMonthsNoOverflow($meses);

        if ($fechamentoAnterior === null) {
            return $candidata;
        }

        $fechamento = $card->billingCycle($candidata)[1];
        if ($fechamento->lessThanOrEqualTo($fechamentoAnterior)) {
            return $fechamentoAnterior->addDay();
        }

        return $candidata;
    }

    /**
     * Recorrente "infinita": cria UMA ocorrência em aberto, datada no DIA DA
     * COMPRA. As seguintes nascem pelo botão da recorrência, uma por ciclo de
     * fatura (`gerarProximaOcorrencia`).
     *
     * Antes ela nascia datada no VENCIMENTO do cartão — que é sempre depois do
     * fechamento, portanto FORA do ciclo aberto: a cobrança não aparecia na
     * lista do cartão até o ciclo virar, e o "Pagar" na ocorrência mais nova
     * gerava ocorrências mês após mês no futuro (20/08, 20/09, 20/10…), todas
     * comendo limite de uma vez. A assinatura é cobrada no dia em que foi
     * contratada, como uma compra qualquer; o vencimento é da FATURA, não dela.
     */
    private function createRecurring(array $common, float $total, CarbonImmutable $base, Account $card): Transaction
    {
        return Transaction::create($common + [
            'amount' => $total,
            'date' => $base->toDateString(),
            'group_id' => (string) Str::uuid(),
            'recurring' => true,
        ]);
    }

    /**
     * Paga a ocorrência recorrente em aberto: marca como paga e gera a PRÓXIMA
     * (+1 mês, mantém o dia de vencimento, mesmo grupo, em aberto) — a
     * recorrência só termina quando é excluída (`destroy` → `EndedRecurrence`).
     * Idempotente: pagar de novo uma ocorrência já paga (ou uma não-recorrente)
     * não gera nada.
     */
    public function pay(Transaction $transaction, FundingService $funding)
    {
        $this->authorize('update', $transaction);

        // Não-recorrente: nada a fazer.
        if (! $transaction->recurring) {
            return redirect()->route('faturas.index');
        }

        // Série ENCERRADA (excluída em /faturas): não gera mais nada (R2-2). Sem esta
        // guarda, o POST na ocorrência paga que sobrou recriava a sucessora. A tela já
        // não oferece "Lançar próxima" numa série encerrada (`FaturaService`,
        // `lancarProxima`), mas quem decide é esta linha: um POST montado à mão, ou
        // vindo de uma aba aberta antes da exclusão, chega aqui do mesmo jeito.
        if (EndedRecurrence::daSerie($transaction)) {
            return redirect()->route('faturas.index')->with('status', self::AVISO_SERIE_ENCERRADA);
        }

        // Recorrência NO CARTÃO não é quitada aqui: quem quita é a fatura.
        //
        // Marcar `paid_at` é justamente o que tira a despesa de `openInvoiceDue` e
        // devolve o limite — sem nenhum dinheiro sair do caixa. Antes desta guarda, três
        // cliques "quitavam" R$ 149,70 de dívida com R$ 0,00 de saída, e o limite do
        // cartão voltava de graça. Em conta corrente/poupança o caso é outro: a despesa
        // já descontou do saldo no lançamento, então marcar como paga é só registro.
        $conta = $transaction->account;

        if ($conta !== null && $conta->type === 'credit_card') {
            // A próxima ocorrência só nasce quando a atual JÁ FECHOU (pertence a um
            // ciclo que já virou) ou foi quitada com a fatura do ciclo dela. Clicar
            // numa ocorrência cujo ciclo ainda está aberto NÃO gera nada: era assim
            // que um clique atrás do outro empilhava cobranças meses adiante, todas
            // consumindo limite antes de a assinatura sequer ser cobrada.
            $fechamento = $conta->billingCycle(CarbonImmutable::parse($transaction->date))[1] ?? null;
            $cicloAindaAberto = $fechamento !== null
                && $fechamento->greaterThanOrEqualTo(CarbonImmutable::today())
                && $transaction->paid_at === null;

            if ($cicloAindaAberto) {
                return redirect()->route('faturas.index')->with(
                    'status',
                    'Esta cobrança ainda está na fatura aberta do cartão (fecha em '
                    .$fechamento->translatedFormat('d/m/Y').'). A próxima ocorrência é lançada '
                    .'depois que esta fatura fechar — assim ela não consome limite antes da hora.',
                );
            }

            // A recorrência precisa continuar andando, então a próxima ocorrência é
            // lançada; a atual permanece EM ABERTO, dentro da fatura.
            $proxima = $this->gerarProximaOcorrencia($transaction, $funding);

            return redirect()->route('faturas.index')->with(
                'status',
                match (true) {
                    $proxima => 'Próxima ocorrência lançada na fatura aberta. Esta despesa é quitada junto com a fatura do cartão.',
                    // Encerrada enquanto este clique esperava a trava (ver `destroy`).
                    EndedRecurrence::daSerie($transaction) => self::AVISO_SERIE_ENCERRADA,
                    default => 'A próxima ocorrência já estava lançada. No cartão, a cobrança é quitada com a fatura.',
                },
            );
        }

        // Recorrência FORA do cartão (legada, em conta): a transação de fora é quem
        // repete num deadlock. O `spend()` do `gerarProximaOcorrencia` roda
        // ANINHADO nela, e aninhado ele não repete sozinho — no deadlock o MySQL
        // desfaz a transação de FORA inteira (ver `FundingService::repetirNoDeadlock`,
        // caso 2). Sem `attempts` aqui, o deadlock virava HTTP 500.
        //
        // Repetir o fechamento inteiro é seguro porque ele não reusa model que uma
        // tentativa anterior tenha salvado — a armadilha que a rodada 1 achou no
        // `TransactionController`, onde o `update()` da repetição num model que o
        // Eloquent já dava por gravado não gravava nada:
        //  - o `paid_at` sai de um UPDATE do query builder (nenhum model em memória
        //    muda); o rollback o desfaz e o `whereNull('paid_at')` volta a casar;
        //  - `$transaction` só é LIDO (data, valor, grupo, conta) — nunca salvo;
        //  - a sucessora nasce de um `Transaction::create` novo a cada tentativa; e
        //  - a conta é relida e travada do banco dentro do `spend()`.
        DB::transaction(function () use ($transaction, $funding) {
            // Update condicional ATÔMICO: marca como paga só se ainda estava em
            // aberto. Duas requisições simultâneas: só uma afeta a linha; a outra
            // recebe 0 e sai sem gerar uma 2ª próxima ocorrência (idempotente).
            $affected = Transaction::whereKey($transaction->id)
                ->whereNull('paid_at')
                ->where('recurring', true)
                ->update(['paid_at' => now()]);

            if ($affected === 0) {
                return; // já estava paga (ou outra requisição venceu a corrida).
            }

            $this->gerarProximaOcorrencia($transaction, $funding);
        }, attempts: FundingService::TENTATIVAS);

        return redirect()->route('faturas.index')
            ->with('status', 'Recorrência paga — a próxima já foi lançada.');
    }

    /**
     * Lança a próxima ocorrência de uma recorrência (mesmo grupo, em aberto).
     *
     * No CARTÃO a data é o dia da compra original avançado N meses, uma
     * ocorrência por ciclo de fatura (a mesma régua das parcelas — ver
     * `dataNoCicloSeguinte`). Fora do cartão (recorrência legada em conta), é
     * +1 mês da ocorrência atual, como sempre foi.
     *
     * Idempotente: se a ocorrência clicada já tem SUCESSORA no grupo, não cria
     * outra — é o que permite chamar isto no caminho do cartão, onde não há
     * `paid_at` para servir de trava contra o clique repetido. Comparar pela
     * existência de uma sucessora (e não por "a data X já existe") é o que
     * impede um clique na ocorrência antiga de gerar uma terceira depois da
     * segunda.
     *
     * @return bool true se criou, false se já existia
     */
    private function gerarProximaOcorrencia(Transaction $transaction, FundingService $funding): bool
    {
        $conta = $transaction->account;
        $atual = CarbonImmutable::parse($transaction->date);

        if ($conta !== null && $conta->type === 'credit_card' && $transaction->group_id) {
            $primeira = Transaction::where('group_id', $transaction->group_id)
                ->orderBy('date')->orderBy('id')
                ->first();
            $posicao = Transaction::where('group_id', $transaction->group_id)
                ->where('date', '<=', $atual->toDateString())
                ->count();
            $proxima = $this->dataNoCicloSeguinte(
                CarbonImmutable::parse($primeira?->date ?? $atual),
                max(1, $posicao),
                $conta->billingCycle($atual)[1] ?? null,
                $conta,
            );
        } else {
            $proxima = $atual->addMonthNoOverflow();
        }

        $temSucessora = fn (bool $lock) => Transaction::where('group_id', $transaction->group_id)
            ->whereNotNull('group_id')
            ->where('date', '>', $atual->toDateString())
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->exists();

        // Caminho rápido (time-of-check): a sucessora já está lá, então nem
        // abrimos transação nem incomodamos o guard de limite. A checagem que
        // VALE é a de dentro do write, sob lock — esta só evita erro de limite
        // num clique repetido em cartão sem folga.
        if ($temSucessora(false)) {
            return false;
        }

        // A ocorrência nova é uma DESPESA de verdade — e no modelo deste app despesa com
        // data futura já entra no saldo. Então ela passa pela trava como qualquer gasto:
        // era o único caminho de escrita que gravava com `Transaction::create` cru, e por
        // ele dava para derrubar a conta sem limite (disponível 0 → −100 → −200 → −300).
        //
        // `obrigacao: false` de propósito: lançar a parcela do mês que vem é gasto novo,
        // não boleto vencido. Sem saldo, o usuário é avisado (ou escolhe a fonte) em vez
        // de a conta afundar em silêncio.
        $criada = $funding->spend(
            account: $conta,
            amount: (float) $transaction->amount,
            source: null,
            investmentId: null,
            // A checagem de "já existe" mora DENTRO do write, que roda sob o lock
            // da conta (FundingService trava a linha antes de chamar). Fora dele,
            // dois POSTs simultâneos liam "não existe" ao mesmo tempo e criavam
            // duas ocorrências do mesmo mês — dívida em dobro no cartão, com a
            // recorrência andando dois meses de uma vez.
            write: function (array $auditoria) use ($transaction, $proxima, $temSucessora) {
                // A sucessora já existe, ou a série foi encerrada (excluída) enquanto
                // este clique esperava a trava da conta: nada a lançar. As duas
                // leituras são travadas — enxergam o que outra transação acabou de
                // gravar, mesmo com o snapshot desta já aberto.
                if ($temSucessora(true) || EndedRecurrence::daSerie($transaction, travar: true)) {
                    return null;
                }

                return Transaction::create($auditoria + [
                    'user_id' => $transaction->user_id,
                    'made_by_user_id' => $transaction->made_by_user_id,
                    'account_id' => $transaction->account_id,
                    'category_id' => $transaction->category_id,
                    'type' => 'expense',
                    'description' => $transaction->description,
                    'amount' => $transaction->amount,
                    'date' => $proxima->toDateString(),
                    'group_id' => $transaction->group_id,
                    'recurring' => true,
                ]);
            },
            madeByUserId: $transaction->made_by_user_id,
            date: $proxima->toDateString(),
        );

        return $criada !== null;
    }
}
