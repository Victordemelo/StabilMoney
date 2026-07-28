<?php

namespace App\Http\Controllers;

use App\Http\Requests\PayFixedBillRequest;
use App\Http\Requests\StoreFixedBillRequest;
use App\Http\Requests\UpdateFixedBillRequest;
use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Services\FundingService;
use App\Support\Brl;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Contas fixas mensais. A LISTAGEM não tem rota própria: as ocorrências
 * aparecem como um bloco da tela "Pagar despesas" (/faturas), que já é onde se
 * paga tudo. Aqui ficam só as escritas.
 */
class FixedBillController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreFixedBillRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->ownerId();
        $data['made_by_user_id'] = $request->user()->id;
        $data['active'] = $data['active'] ?? true;

        FixedBill::create($data);

        return redirect()->route('faturas.index')
            ->with('status', 'Conta fixa cadastrada. Ela aparece todo mês até você desativar.');
    }

    public function update(UpdateFixedBillRequest $request, FixedBill $conta)
    {
        $this->authorize('update', $conta);

        $conta->update($request->validated());

        return redirect()->route('faturas.index')->with('status', 'Conta fixa atualizada.');
    }

    public function destroy(FixedBill $conta)
    {
        $this->authorize('delete', $conta);

        // Os pagamentos já feitos são transações de verdade e ficam no
        // histórico; só o vínculo se perde (fixed_bill_id vira null não é
        // possível sem FK, então desligamos a conta em vez de apagar quando há
        // pagamento — assim o histórico continua explicável).
        if ($conta->payments()->exists()) {
            $conta->update(['active' => false]);

            return redirect()->route('faturas.index')
                ->with('status', 'Conta fixa desativada (os pagamentos já feitos continuam no histórico).');
        }

        $conta->delete();

        return redirect()->route('faturas.index')->with('status', 'Conta fixa removida.');
    }

    /**
     * Paga UMA competência (mês) da conta fixa.
     *
     * Passa pelo FundingService, então herda tudo: bloqueio por saldo, pergunta
     * de fonte (cheque especial / resgate) e o limite do cartão. A idempotência
     * é o UNIQUE (fixed_bill_id, competence): duplo clique não paga duas vezes.
     */
    public function pay(PayFixedBillRequest $request, FixedBill $conta, string $competencia, FundingService $funding)
    {
        $this->authorize('update', $conta);

        $ownerId = $request->user()->ownerId();
        $data = $request->validated();

        // "2026-07" → 01/07/2026. Competência é sempre o dia 1 do mês.
        $competence = CarbonImmutable::createFromFormat('Y-m-d', $competencia . '-01')->startOfMonth();
        $pagoEm = CarbonImmutable::parse($data['paid_on'] ?? now()->toDateString());
        $valor = round((float) $data['amount'], 2);

        $caixa = Account::whereKey($data['account_id'])->firstOrFail();

        try {
            $funding->spend(
                account: $caixa,
                amount: $valor,
                source: $data['funding_source'] ?? null,
                investmentId: isset($data['funding_investment_id']) ? (int) $data['funding_investment_id'] : null,
                write: fn (array $auditoria) => Transaction::create($auditoria + [
                    'user_id' => $ownerId,
                    'made_by_user_id' => $request->user()->id,
                    'account_id' => $caixa->id,
                    'category_id' => $conta->category_id,
                    'type' => 'expense',
                    'amount' => $valor,
                    'date' => $pagoEm->toDateString(),
                    'paid_at' => $pagoEm,
                    'description' => $conta->name . ' — ' . $competence->translatedFormat('F/Y'),
                    'fixed_bill_id' => $conta->id,
                    'competence' => $competence->toDateString(),
                ]),
                madeByUserId: $request->user()->id,
                date: $pagoEm->toDateString(),
                // Conta fixa vencida é obrigação: sem fonte, negativa em vez de recusar.
                obrigacao: true,
            );
        } catch (QueryException $e) {
            // Violação do UNIQUE = alguém já pagou esta competência (duplo
            // clique / replay). Idempotente: segue como sucesso.
            if (! str_contains($e->getMessage(), 'transactions_fixed_bill_competence_unique')) {
                throw $e;
            }

            return redirect()->route('faturas.index')
                ->with('status', 'Esta competência já estava paga.');
        }

        $saldo = $caixa->fresh()->available;
        $aviso = $saldo < 0
            ? $conta->name . ' pago. Atenção: a conta ' . $caixa->name . ' ficou em ' . Brl::format($saldo) . '.'
            : $conta->name . ' pago. A próxima competência já aparece aqui.';

        return redirect()->route('faturas.index')->with('status', $aviso);
    }
}
