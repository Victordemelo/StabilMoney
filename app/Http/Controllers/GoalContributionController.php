<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesContributions;
use App\Http\Requests\StoreGoalContributionRequest;
use App\Http\Requests\WithdrawGoalContributionRequest;
use App\Models\Goal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class GoalContributionController extends Controller
{
    use AuthorizesRequests;
    use HandlesContributions;

    /**
     * Aporte (modelo "cofrinho"): reserva dinheiro de uma conta na meta.
     * Não cria transação — o saldo cru das contas não muda, só o disponível.
     */
    public function store(StoreGoalContributionRequest $request, Goal $meta)
    {
        $this->authorize('update', $meta);

        $this->record($request, $meta, 'aporte');

        return redirect()->route('metas.index')
            ->with('status', 'Aporte realizado com sucesso.');
    }

    /**
     * Resgate: devolve dinheiro guardado na meta para uma conta de destino.
     */
    public function withdraw(WithdrawGoalContributionRequest $request, Goal $meta)
    {
        $this->authorize('update', $meta);

        $this->record($request, $meta, 'resgate');

        return redirect()->route('metas.index')
            ->with('status', 'Resgate realizado com sucesso.');
    }

    /** Valor guardado na meta (limite de resgate). */
    protected function parentBalance(Model $parent): float
    {
        /** @var Goal $parent */
        return $parent->saved;
    }

    protected function withdrawOverflowMessage(float $available): string
    {
        return 'O valor do resgate é maior que o valor guardado na meta (R$ '
            . number_format($available, 2, ',', '.') . ').';
    }
}
