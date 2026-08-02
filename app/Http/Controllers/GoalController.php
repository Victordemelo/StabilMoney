<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGoalRequest;
use App\Http\Requests\UpdateGoalRequest;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalContribution;
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

        if ($erro = $this->travaDeExclusaoComContaNoVermelho($meta)) {
            return back()->withErrors(['meta' => $erro]);
        }

        // As contributions caem junto (cascadeOnDelete) — o dinheiro volta a ficar
        // disponível nas contas, já que deixa de estar reservado.
        $meta->delete();

        return redirect()->route('metas.index')
            ->with('status', 'Meta removida.');
    }

    /**
     * Trava de INTENÇÃO E AUDITORIA — não de criação de dinheiro.
     *
     * Excluir a meta e resgatá-la por inteiro têm efeito IDÊNTICO sobre o
     * disponível da conta: os dois derrubam o `reserved` dela no mesmo valor.
     * Nenhum dos dois inventa dinheiro, e não é isso que estamos evitando.
     *
     * O que a exclusão destrói é o REGISTRO. Sai de cena a linha de resgate que
     * diria "foi o cofrinho da viagem que cobriu o cheque especial", e some com
     * ela a única pista de como aquele saldo negativo virou positivo. Somado a
     * isso, "excluir" é um caminho ACIDENTAL: quem clica ali está limpando uma
     * lista, não decidindo quitar dívida com a poupança. Então, quando há
     * dinheiro guardado E a conta de origem está no vermelho, exigimos o
     * caminho explícito (resgatar), que deixa rastro.
     *
     * Meta vazia, ou contas todas no azul: nada a proteger — libera.
     *
     * (Gêmeo do método de mesmo nome no InvestmentController. A duplicação é
     * deliberada nesta rodada; extrair para um Concern exige mexer em arquivo
     * de outro agente.)
     *
     * Devolve a mensagem PT-BR do bloqueio, ou null quando pode excluir.
     */
    private function travaDeExclusaoComContaNoVermelho(Goal $meta): ?string
    {
        // Nada guardado: excluir não mexe no disponível de conta nenhuma.
        if ($meta->saved <= 0.001) {
            return null;
        }

        // Quanto CADA conta ainda tem guardado aqui (aportes − resgates), numa
        // query só. Conta que já resgatou tudo não é afetada pela exclusão.
        $reservadoPorConta = GoalContribution::where('goal_id', $meta->id)
            ->groupBy('account_id')
            ->selectRaw('account_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'aporte' THEN amount ELSE -amount END), 0) AS total")
            ->pluck('total', 'account_id')
            ->filter(fn ($total) => (float) $total > 0.001);

        if ($reservadoPorConta->isEmpty()) {
            return null;
        }

        // Escopado na família da própria meta: um dado cruzado jamais pode
        // fazer a mensagem de erro citar o nome da conta de outra pessoa.
        $contas = Account::where('user_id', $meta->user_id)
            ->whereIn('id', $reservadoPorConta->keys())
            ->orderBy('name')
            ->get();

        // Sem isto seriam ~6 queries POR conta dentro do laço abaixo
        // (regra do CLAUDE.md: nunca ler available/reserved iterando).
        Account::preloadMoney($contas);

        $negativa = $contas->first(fn (Account $conta) => $conta->available < -0.001);

        if (! $negativa) {
            return null;
        }

        $guardadoDela = (float) $reservadoPorConta->get($negativa->id, 0);

        return 'A conta “' . $negativa->name . '” está em ' . Brl::format($negativa->available)
            . ' e esta meta tem ' . Brl::format($guardadoDela) . ' guardados a partir dela. '
            . 'Excluir aqui zeraria esse saldo negativo em silêncio, sem deixar registrado que foi '
            . 'o dinheiro da meta que o cobriu. Faça um resgate para a conta “' . $negativa->name . '” '
            . '(aí fica gravado de onde saiu o dinheiro) ou deixe o saldo dela positivo antes de excluir.';
    }
}
