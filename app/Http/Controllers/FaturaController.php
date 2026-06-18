<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFaturaLaunchRequest;
use App\Models\Transaction;
use App\Services\FaturaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
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
     * (12 linhas). Tudo type=expense, escopado na família.
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
            $this->createRecurring($common, $total, $base);
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
    }

    /** Recorrente: 12 lançamentos mensais do valor cheio. */
    private function createRecurring(array $common, float $total, CarbonImmutable $base): void
    {
        $groupId = (string) Str::uuid();

        for ($i = 0; $i < 12; $i++) {
            Transaction::create($common + [
                'amount' => $total,
                'date' => $base->addMonths($i)->toDateString(),
                'group_id' => $groupId,
                'recurring' => true,
            ]);
        }
    }
}
