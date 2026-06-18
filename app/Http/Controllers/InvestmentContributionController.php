<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvestmentContributionRequest;
use App\Http\Requests\WithdrawInvestmentContributionRequest;
use App\Models\Investment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class InvestmentContributionController extends Controller
{
    use AuthorizesRequests;

    /**
     * Aporte (modelo "cofrinho"): reserva dinheiro de uma conta no investimento.
     * Não cria transação — o saldo cru das contas não muda, só o disponível.
     */
    public function store(StoreInvestmentContributionRequest $request, Investment $investimento)
    {
        $this->authorize('update', $investimento);

        $this->record($request, $investimento, 'aporte');

        return redirect()->route('investimentos.index')
            ->with('status', 'Aporte realizado com sucesso.');
    }

    /**
     * Resgate: devolve dinheiro aplicado no investimento para uma conta de destino.
     */
    public function withdraw(WithdrawInvestmentContributionRequest $request, Investment $investimento)
    {
        $this->authorize('update', $investimento);

        $this->record($request, $investimento, 'resgate');

        return redirect()->route('investimentos.index')
            ->with('status', 'Resgate realizado com sucesso.');
    }

    /** Cria a movimentação do investimento, com autor/data padrão e o tipo informado. */
    private function record($request, Investment $investimento, string $type): void
    {
        $data = $request->validated();

        $investimento->contributions()->create([
            'account_id' => $data['account_id'],
            // Autor: o informado no form, ou o usuário atual por padrão.
            'made_by_user_id' => $data['made_by_user_id'] ?? $request->user()->id,
            'type' => $type,
            'amount' => $data['amount'],
            'date' => $data['date'] ?? now()->toDateString(),
        ]);
    }
}
