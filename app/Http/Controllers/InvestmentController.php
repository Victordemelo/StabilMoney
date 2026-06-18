<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvestmentRequest;
use App\Http\Requests\UpdateInvestmentRequest;
use App\Models\Account;
use App\Models\Investment;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class InvestmentController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $userId = $request->user()->ownerId();

        // Investimentos da família, com quem criou, ordenados por criação.
        $investments = Investment::with('madeBy')
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get();

        // Contas elegíveis como origem de aporte: tudo menos cartão de crédito.
        $accounts = Account::where('user_id', $userId)
            ->where('type', '!=', 'credit_card')
            ->orderBy('name')
            ->get();

        // Stats agregados dos cards do topo.
        $totalInvestido = round($investments->sum(fn (Investment $i) => $i->aplicado), 2);
        // Rentabilidade média = média do grossRate ponderada pelo aplicado.
        $rentabMedia = $totalInvestido > 0
            ? round($investments->sum(fn (Investment $i) => $i->grossRate * $i->aplicado) / $totalInvestido, 2)
            : 0.0;

        $stats = [
            'investido' => $totalInvestido,
            'ativos' => $investments->count(),
            'rentabMedia' => $rentabMedia,
        ];

        return view('investimentos.index', [
            'investments' => $investments,
            'accounts' => $accounts,
            'familyMembers' => $this->familyMembers($userId),
            'stats' => $stats,
            'allocation' => $this->allocation($investments, $totalInvestido),
        ]);
    }

    public function store(StoreInvestmentRequest $request)
    {
        $data = $request->validated();

        $investment = Investment::create([
            'user_id' => $request->user()->ownerId(),
            // Autor do investimento: o usuário atual (titular ou dependente que criou).
            'made_by_user_id' => $request->user()->id,
            'name' => $data['name'],
            'classe' => $data['classe'],
            'indexador' => $data['indexador'] ?? null,
            'taxa' => $data['taxa'] ?? null,
        ]);

        // Aporte inicial opcional: se informado (> 0), reserva já o principal
        // da conta escolhida (modelo "cofrinho"; não cria transação).
        if (! empty($data['valor_inicial']) && (float) $data['valor_inicial'] > 0) {
            $investment->contributions()->create([
                'account_id' => $data['account_id'],
                'made_by_user_id' => $data['made_by_user_id'] ?? $request->user()->id,
                'type' => 'aporte',
                'amount' => $data['valor_inicial'],
                'date' => $data['date'] ?? now()->toDateString(),
            ]);
        }

        return redirect()->route('investimentos.index')
            ->with('status', 'Investimento criado com sucesso.');
    }

    public function update(UpdateInvestmentRequest $request, Investment $investimento)
    {
        $this->authorize('update', $investimento);

        $investimento->update($request->validated());

        return redirect()->route('investimentos.index')
            ->with('status', 'Investimento atualizado.');
    }

    public function destroy(Investment $investimento)
    {
        $this->authorize('delete', $investimento);

        // As contributions caem junto (cascadeOnDelete) — o dinheiro volta a ficar
        // disponível nas contas, já que deixa de estar reservado.
        $investimento->delete();

        return redirect()->route('investimentos.index')
            ->with('status', 'Investimento removido.');
    }

    /** Membros da família (titular + dependentes) para o seletor "quem aportou". */
    private function familyMembers(int $ownerId)
    {
        return User::where('id', $ownerId)
            ->orWhere('account_owner_id', $ownerId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Alocação por classe (para o donut): rótulo, cor, valor aplicado e
     * percentual inteiro do total. Só classes com algum aplicado entram.
     */
    private function allocation($investments, float $total): array
    {
        return $investments
            ->groupBy('classe')
            ->map(function ($grupo, $classe) use ($total) {
                $valor = round($grupo->sum(fn (Investment $i) => $i->aplicado), 2);

                return [
                    'classe' => Investment::CLASSES[$classe] ?? $classe,
                    'color' => Investment::CLASS_COLORS[$classe] ?? '#9078D8',
                    'value' => $valor,
                    'pct' => $total > 0 ? (int) round($valor / $total * 100) : 0,
                ];
            })
            ->filter(fn ($item) => $item['value'] > 0)
            ->values()
            ->all();
    }
}
