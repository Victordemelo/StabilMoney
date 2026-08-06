<?php

namespace App\Http\Controllers;

use App\Http\Requests\PayInvoiceRequest;
use App\Http\Requests\StoreFaturaLaunchRequest;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\FaturaService;
use App\Services\FundingService;
use App\Support\Brl;
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

    public function index(Request $request, FaturaService $faturas)
    {
        return view('faturas.index', $faturas->build($request->user()->ownerId()));
    }

    /**
     * Cria a despesa: à vista (1 linha), parcelada (N linhas) ou recorrente
     * (1 ocorrência em aberto, datada no vencimento do cartão). type=expense, família.
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

        try {
            $funding->spend(
                account: $conta,
                amount: $total,
                source: $fonte,
                investmentId: $investimentoId,
                write: function (array $auditoria) use ($mode, $common, $total, $base, $data, $conta) {
                    if ($mode === 'parcelado') {
                        return $this->createInstallments($common + $auditoria, $total, $base, (int) $data['installments']);
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
     * (parcelada/recorrente), apaga todas as linhas do grupo; senão, só ela.
     * Escopo de família garantido pela TransactionPolicy.
     */
    public function destroy(Transaction $transaction, FundingService $funding)
    {
        $this->authorize('delete', $transaction);

        // Quitação de fatura NÃO se apaga isolada. Pagar a fatura escreve N+1
        // linhas (marca as compras do ciclo com paid_at + cria esta saída de
        // caixa). Apagar só esta devolveria o dinheiro à conta E deixaria as
        // compras quitadas — dinheiro criado em dobro (saldo + limite de volta).
        if ($transaction->settles_account_id) {
            return back()->withErrors([
                'transaction' => 'Esta linha é o pagamento de uma fatura de cartão e não pode ser excluída sozinha. Para desfazer, use "Estornar" no cartão — assim as compras voltam a ficar em aberto.',
            ]);
        }

        // Compra de CARTÃO já quitada também não se apaga. O ramo do grupo já
        // protegia as parcelas pagas (`whereNull('paid_at')`), mas a compra
        // avulsa caía direto no `delete()`: a saída de caixa que pagou aquela
        // fatura continuava lá, sem a compra que a originou — dívida apagada,
        // dinheiro debitado, e o estorno do pagamento deixaria de reencontrar a
        // compra. A saída correta é estornar o pagamento e só então excluir.
        if ($this->cartaoComTudoQuitado($transaction)) {
            return back()->withErrors([
                'transaction' => 'Esta compra já foi paga junto com a fatura do cartão e não pode ser excluída. Use "Estornar pagamento" no cartão primeiro — a compra volta a ficar em aberto e aí sim pode ser removida.',
            ]);
        }

        $status = DB::transaction(function () use ($transaction, $funding) {
            if ($transaction->group_id) {
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
        $cycle = ($data['ciclo'] ?? 'aberto') === 'fechado'
            ? $account->closedCycle()
            : $account->billingCycle();

        if (! $cycle) {
            return redirect()->route('faturas.index');
        }
        [$start, $end] = $cycle;

        // Despesas EM ABERTO do ciclo (trava para evitar corrida/duplo pagamento).
        // Soma COM SINAL: estorno (income) lançado no cartão abate a fatura, como no
        // mundo real. Somando tudo como despesa, uma compra de 1.000 com estorno de 300
        // cobrava 1.300 do caixa — e o `committed` do cartão, que já considera o sinal,
        // discordaria do valor cobrado.
        $abertas = Transaction::where('account_id', $account->id)
            ->whereNull('paid_at')
            ->where('date', '>', $start->toDateString())
            ->where('date', '<=', $end->toDateString())
            ->get();

        $total = round(
            (float) $abertas->sum(fn (Transaction $t) => $t->type === 'expense'
                ? (float) $t->amount
                : -(float) $t->amount),
            2,
        );
        if ($total <= 0) {
            return redirect()->route('faturas.index')
                ->with('status', 'Esta fatura já estava quitada.');
        }

        $caixa = Account::whereKey($data['pay_account_id'])->firstOrFail();

        // Pagar fatura é uma saída de caixa como outra qualquer: respeita o
        // saldo e, se faltar, pergunta a fonte (cheque especial ou resgate).
        $funding->spend(
            account: $caixa,
            amount: $total,
            source: $data['funding_source'] ?? null,
            investmentId: isset($data['funding_investment_id']) ? (int) $data['funding_investment_id'] : null,
            write: function (array $auditoria) use ($ownerId, $request, $data, $account, $pagoEm, $start, $end) {
                // A LEITURA AUTORITATIVA é esta, sob lock e dentro da transação.
                //
                // Antes, a lista e o total vinham de fora e a saída de caixa era criada
                // incondicionalmente: com 4 POSTs paralelos, uma fatura de R$ 300 gerava
                // 4 pagamentos de R$ 300 (a conta ia a −R$ 1.100). O relock existia, mas
                // só decidia o que MARCAR como pago — nunca se havia o que pagar.
                $abertasAgora = Transaction::where('account_id', $account->id)
                    ->whereNull('paid_at')
                    ->where('date', '>', $start->toDateString())
                    ->where('date', '<=', $end->toDateString())
                    ->lockForUpdate()
                    ->get();

                // Mesma soma com sinal da pré-checagem: o estorno abate.
                $totalAgora = round(
                    (float) $abertasAgora->sum(fn (Transaction $t) => $t->type === 'expense'
                        ? (float) $t->amount
                        : -(float) $t->amount),
                    2,
                );

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
                    'description' => 'Pagamento da fatura — '.$account->name,
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
        // `paid_at` em todas as compras do lote.
        if ($compras->isEmpty() && $transaction->paid_at) {
            $compras = Transaction::where('account_id', $transaction->settles_account_id)
                ->whereNull('settled_by_id')
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
     * Parcelado em N: uma transação por mês. A última parcela absorve o
     * arredondamento para a soma bater o total exato.
     */
    private function createInstallments(array $common, float $total, CarbonImmutable $base, int $n): Transaction
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

        DB::transaction(function () use ($common, $semUuid, $base, $n, $groupId, $porParcela, $sobra, &$primeira) {
            for ($i = 1; $i <= $n; $i++) {
                // As `$sobra` primeiras parcelas levam 1 centavo a mais.
                $amount = ($porParcela + ($i <= $sobra ? 1 : 0)) / 100;

                $linha = Transaction::create(($i === 1 ? $common : $semUuid) + [
                    'amount' => $amount,
                    // NoOverflow: compra em 31/01 gera 28/02, não 03/03. Com o
                    // addMonths puro do Carbon, fevereiro ficava sem parcela e
                    // março levava duas.
                    'date' => $base->addMonthsNoOverflow($i - 1)->toDateString(),
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
     * Recorrente "infinita": cria UMA ocorrência em aberto, datada no próximo
     * vencimento do cartão. Pagá-la (pay) gera a próxima (+1 mês). Se o cartão
     * não tiver dia de vencimento, usa a data informada.
     */
    private function createRecurring(array $common, float $total, CarbonImmutable $base, Account $card): Transaction
    {
        $vencimento = $card->dueDate ?? $base;

        return Transaction::create($common + [
            'amount' => $total,
            'date' => $vencimento->toDateString(),
            'group_id' => (string) Str::uuid(),
            'recurring' => true,
        ]);
    }

    /**
     * Paga a ocorrência recorrente em aberto: marca como paga e gera a PRÓXIMA
     * (+1 mês, mantém o dia de vencimento, mesmo grupo, em aberto) — a
     * recorrência nunca termina. Idempotente: pagar de novo uma ocorrência já
     * paga (ou uma não-recorrente) não gera nada.
     */
    public function pay(Transaction $transaction, FundingService $funding)
    {
        $this->authorize('update', $transaction);

        // Não-recorrente: nada a fazer.
        if (! $transaction->recurring) {
            return redirect()->route('faturas.index');
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
            // A recorrência precisa continuar andando, então a próxima ocorrência é
            // lançada; a atual permanece EM ABERTO, dentro da fatura.
            $proxima = $this->gerarProximaOcorrencia($transaction, $funding);

            return redirect()->route('faturas.index')->with(
                'status',
                $proxima
                    ? 'Próxima ocorrência lançada. Esta despesa é quitada junto com a fatura do cartão.'
                    : 'A próxima ocorrência já estava lançada. No cartão, a cobrança é quitada com a fatura.',
            );
        }

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
        });

        return redirect()->route('faturas.index')
            ->with('status', 'Recorrência paga — a próxima já foi lançada.');
    }

    /**
     * Lança a próxima ocorrência de uma recorrência (+1 mês, mesmo grupo, em aberto).
     *
     * Idempotente: se a ocorrência daquele mês já existe no grupo, não cria outra — é o
     * que permite chamar isto no caminho do cartão, onde não há `paid_at` para servir de
     * trava contra o clique repetido.
     *
     * @return bool true se criou, false se já existia
     */
    private function gerarProximaOcorrencia(Transaction $transaction, FundingService $funding): bool
    {
        $proxima = CarbonImmutable::parse($transaction->date)->addMonthNoOverflow();
        $conta = $transaction->account;

        // Caminho rápido (time-of-check): a ocorrência já está lá, então nem
        // abrimos transação nem incomodamos o guard de limite. A checagem que
        // VALE é a de dentro do write, sob lock — esta só evita erro de limite
        // num clique repetido em cartão sem folga.
        $jaExiste = Transaction::where('group_id', $transaction->group_id)
            ->whereNotNull('group_id')
            ->where('date', $proxima->toDateString())
            ->exists();

        if ($jaExiste) {
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
            write: function (array $auditoria) use ($transaction, $proxima) {
                $jaExiste = Transaction::where('group_id', $transaction->group_id)
                    ->whereNotNull('group_id')
                    ->where('date', $proxima->toDateString())
                    ->lockForUpdate()
                    ->exists();

                if ($jaExiste) {
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
