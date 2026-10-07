<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGoalRequest;
use App\Http\Requests\UpdateGoalRequest;
use App\Models\Account;
use App\Models\Goal;
use App\Models\User;
use App\Support\Brl;
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

        // Os selects de aporte e resgate mostram o DISPONÍVEL de cada conta: sem isto
        // eram 6 queries POR conta, cada SUM varrendo o histórico dela (V-5 da auditoria
        // de volume). Com o pré-carregamento, 4 no total.
        Account::preloadMoney($accounts);

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

        // Meta com dinheiro guardado não sai (out/2026 — decisão do Victor): primeiro resgata
        // tudo, pelo caminho que deixa registro (e aparece em Movimentações), depois exclui.
        // Antes, excluir devolvia o dinheiro à conta em silêncio e o histórico de aportes
        // sumia junto (cascade). Substitui a trava antiga, que só barrava com a conta no
        // vermelho — `ExclusaoDeMetaSoZeradaTest`.
        $guardado = (float) $meta->saved;
        if ($guardado > 0.001) {
            return back()->withErrors(['meta' => 'A meta “'.$meta->name.'” ainda tem '.Brl::format($guardado)
                .' guardados. Retire todo o dinheiro dela (botão Retirar) antes de excluí-la.']);
        }

        // Zerada: os aportes e resgates (que se anulam) caem junto (cascadeOnDelete).
        $meta->delete();

        return redirect()->route('metas.index')
            ->with('status', 'Meta removida.');
    }
}
