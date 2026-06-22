<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFaturaLaunchRequest;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\FaturaService;
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
    public function store(StoreFaturaLaunchRequest $request)
    {
        $data = $request->validated();
        $ownerId = $request->user()->ownerId();
        $madeBy = $data['made_by_user_id'] ?? $request->user()->id;

        $total = round((float) $data['amount'], 2);
        $base = CarbonImmutable::parse($data['date']);
        $mode = $data['mode'];

        $common = [
            'user_id' => $ownerId,
            'made_by_user_id' => $madeBy,
            'account_id' => $data['account_id'],
            'category_id' => $data['category_id'] ?? null,
            'type' => 'expense',
            'description' => $data['description'],
        ];

        if ($mode === 'parcelado') {
            $this->createInstallments($common, $total, $base, (int) $data['installments']);
            $status = 'Despesa parcelada lançada na fatura.';
        } elseif ($mode === 'recorrente') {
            $card = Account::find($data['account_id']);
            $this->createRecurring($common, $total, $base, $card);
            $status = 'Despesa recorrente lançada na fatura.';
        } else {
            Transaction::create($common + [
                'amount' => $total,
                'date' => $base->toDateString(),
            ]);
            $status = 'Despesa lançada com sucesso.';
        }

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
     * Parcelado em N: uma transação por mês. A última parcela absorve o
     * arredondamento para a soma bater o total exato.
     */
    private function createInstallments(array $common, float $total, CarbonImmutable $base, int $n): void
    {
        $groupId = (string) Str::uuid();
        $parcela = round($total / $n, 2);

        // Atômico: as N parcelas entram juntas ou nenhuma — sem fatura "pela metade".
        DB::transaction(function () use ($common, $total, $base, $n, $groupId, $parcela) {
            for ($i = 1; $i <= $n; $i++) {
                $amount = $i < $n
                    ? $parcela
                    : round($total - $parcela * ($n - 1), 2); // última absorve o resto

                Transaction::create($common + [
                    'amount' => $amount,
                    'date' => $base->addMonths($i - 1)->toDateString(),
                    'group_id' => $groupId,
                    'installment_no' => $i,
                    'installments' => $n,
                ]);
            }
        });
    }

    /**
     * Recorrente "infinita": cria UMA ocorrência em aberto, datada no próximo
     * vencimento do cartão. Pagá-la (pay) gera a próxima (+1 mês). Se o cartão
     * não tiver dia de vencimento, usa a data informada.
     */
    private function createRecurring(array $common, float $total, CarbonImmutable $base, Account $card): void
    {
        $vencimento = $card->dueDate ?? $base;

        Transaction::create($common + [
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
                'date' => CarbonImmutable::parse($transaction->date)->addMonth()->toDateString(),
                'group_id' => $transaction->group_id,
                'recurring' => true,
            ]);
        });

        return redirect()->route('faturas.index')
            ->with('status', 'Recorrência paga — a próxima já foi lançada.');
    }
}
