<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesContributions;
use App\Http\Requests\StoreInvestmentContributionRequest;
use App\Http\Requests\WithdrawInvestmentContributionRequest;
use App\Models\Investment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class InvestmentContributionController extends Controller
{
    use AuthorizesRequests;
    use HandlesContributions;

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

    /** Valor aplicado no investimento (limite de resgate). */
    protected function parentBalance(Model $parent): float
    {
        /** @var Investment $parent */
        return $parent->aplicado;
    }

    protected function withdrawOverflowMessage(float $available): string
    {
        return 'O valor do resgate é maior que o valor aplicado no investimento (R$ '
            . number_format($available, 2, ',', '.') . ').';
    }
}
