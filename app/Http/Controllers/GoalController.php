<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGoalRequest;
use App\Http\Requests\UpdateGoalRequest;
use App\Models\Account;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $userId = $request->user()->ownerId();

        // Metas da família, com quem criou, ordenadas por criação.
        $goals = Goal::with('madeBy')
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get();

        // Contas elegíveis como origem de aporte: tudo menos cartão de crédito.
        $accounts = Account::where('user_id', $userId)
            ->whereIn('type', ['checking', 'savings'])
            ->orderBy('name')
            ->get();

        // Stats agregados dos cards do topo.
        $totalGuardado = round($goals->sum(fn (Goal $g) => $g->saved), 2);
        $totalAlvo = round((float) $goals->sum('target_amount'), 2);

        $stats = [
            'ativas' => $goals->count(),
            'guardado' => $totalGuardado,
            'progresso' => $totalAlvo > 0 ? (int) min(100, round($totalGuardado / $totalAlvo * 100)) : 0,
        ];

        return view('metas.index', [
            'goals' => $goals,
            'accounts' => $accounts,
            // Membros da família (titular + dependentes) para o seletor "quem aportou".
            'familyMembers' => User::familyOf($userId)->get(),
            'stats' => $stats,
        ]);
    }

    public function store(StoreGoalRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->ownerId();
        // Autor da meta: o usuário atual (titular ou dependente que criou).
        $data['made_by_user_id'] = $request->user()->id;

        Goal::create($data);

        return redirect()->route('metas.index')
            ->with('status', 'Meta criada com sucesso.');
    }

    public function update(UpdateGoalRequest $request, Goal $meta)
    {
        $this->authorize('update', $meta);

        $meta->update($request->validated());

        return redirect()->route('metas.index')
            ->with('status', 'Meta atualizada.');
    }

    public function destroy(Goal $meta)
    {
        $this->authorize('delete', $meta);

        // As contributions caem junto (cascadeOnDelete) — o dinheiro volta a ficar
        // disponível nas contas, já que deixa de estar reservado.
        $meta->delete();

        return redirect()->route('metas.index')
            ->with('status', 'Meta removida.');
    }
}
