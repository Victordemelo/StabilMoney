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
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
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

        $common = [
            'user_id' => $ownerId,
            'made_by_user_id' => $madeBy,
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'] ?? null,
            'type' => 'expense',
            'description' => $data['description'],
        ];

        // Todo lançamento passa pelo guard: em conta de caixa ele checa o saldo
        // (e pergunta a fonte quando falta); em cartão, o limite de crédito.
        // No parcelado o que pesa é o TOTAL da compra — é ele que fica retido.
        $fonte = $data['funding_source'] ?? null;
        $investimentoId = isset($data['funding_investment_id']) ? (int) $data['funding_investment_id'] : null;

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
    public function destroy(Transaction $transaction)
    {
        $this->authorize('delete', $transaction);

        if ($transaction->group_id) {
            Transaction::where('user_id', $transaction->user_id)
                ->where('group_id', $transaction->group_id)
                ->delete();
            $status = 'Compra removida (todas as parcelas).';
        } else {
            $transaction->delete();
            $status = 'Despesa removida.';
        }

        return redirect()->route('faturas.index')->with('status', $status);
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

        $cycle = $account->billingCycle();
        if (! $cycle) {
            return redirect()->route('faturas.index');
        }
        [$start, $end] = $cycle;

        // Despesas EM ABERTO do ciclo (trava para evitar corrida/duplo pagamento).
        $abertas = Transaction::where('account_id', $account->id)
            ->where('type', 'expense')
            ->whereNull('paid_at')
            ->whereDate('date', '>', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get();

        $total = round((float) $abertas->sum('amount'), 2);
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
            write: function (array $auditoria) use ($abertas, $ownerId, $request, $data, $account, $total, $pagoEm) {
                // Relock e recheque: só marca o que continua em aberto.
                $ids = Transaction::whereIn('id', $abertas->pluck('id'))
                    ->whereNull('paid_at')
                    ->lockForUpdate()
                    ->pluck('id');

                Transaction::whereIn('id', $ids)->update(['paid_at' => $pagoEm]);

                return Transaction::create($auditoria + [
                    'user_id' => $ownerId,
                    'made_by_user_id' => $request->user()->id,
                    'account_id' => $data['pay_account_id'],
                    'type' => 'expense',
                    'amount' => $total,
                    'date' => $pagoEm->toDateString(),
                    'paid_at' => $pagoEm,
                    'description' => 'Pagamento da fatura — ' . $account->name,
                ]);
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
            ? 'Fatura paga. Atenção: a conta ' . $caixa->name . ' ficou em ' . Brl::format($saldo) . '.'
            : 'Fatura marcada como paga.';

        return redirect()->route('faturas.index')->with('status', $aviso);
    }

    /**
     * Parcelado em N: uma transação por mês. A última parcela absorve o
     * arredondamento para a soma bater o total exato.
     */
    private function createInstallments(array $common, float $total, CarbonImmutable $base, int $n): Transaction
    {
        $groupId = (string) Str::uuid();
        $parcela = round($total / $n, 2);
        $primeira = null;

        // Atômico: as N parcelas entram juntas ou nenhuma — sem fatura "pela metade".
        // (Já roda dentro da transação do FundingService; aninhar é seguro.)
        DB::transaction(function () use ($common, $total, $base, $n, $groupId, $parcela, &$primeira) {
            for ($i = 1; $i <= $n; $i++) {
                $amount = $i < $n
                    ? $parcela
                    : round($total - $parcela * ($n - 1), 2); // última absorve o resto

                $linha = Transaction::create($common + [
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
    public function pay(Transaction $transaction)
    {
        $this->authorize('update', $transaction);

        // Não-recorrente: nada a fazer.
        if (! $transaction->recurring) {
            return redirect()->route('faturas.index');
        }

        DB::transaction(function () use ($transaction) {
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

            // Gera a PRÓXIMA ocorrência (+1 mês, mesmo grupo, em aberto).
            Transaction::create([
                'user_id' => $transaction->user_id,
                'made_by_user_id' => $transaction->made_by_user_id,
                'account_id' => $transaction->account_id,
                'category_id' => $transaction->category_id,
                'type' => 'expense',
                'description' => $transaction->description,
                'amount' => $transaction->amount,
                'date' => CarbonImmutable::parse($transaction->date)->addMonthNoOverflow()->toDateString(),
                'group_id' => $transaction->group_id,
                'recurring' => true,
            ]);
        });

        return redirect()->route('faturas.index')
            ->with('status', 'Recorrência paga — a próxima já foi lançada.');
    }
}
