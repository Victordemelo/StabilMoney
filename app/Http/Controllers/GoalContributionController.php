<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesContributions;
use App\Http\Requests\StoreGoalContributionRequest;
use App\Http\Requests\WithdrawGoalContributionRequest;
use App\Models\Goal;
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
}
