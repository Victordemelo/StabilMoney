<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesContributions;
use App\Http\Requests\StoreInvestmentRequest;
use App\Http\Requests\UpdateInvestmentRequest;
use App\Models\Account;
use App\Models\Investment;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvestmentController extends Controller
{
    use AuthorizesRequests;
    // Só pelos helpers de escrita (lockAccount / assertCabeNoDisponivel): o
    // aporte inicial usa exatamente a mesma rede dos aportes normais.
    use HandlesContributions;

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
            ->whereIn('type', ['checking', 'savings'])
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
            // Membros da família (titular + dependentes) para o seletor "quem aportou".
            'familyMembers' => User::familyOf($userId)->get(),
            'stats' => $stats,
            'allocation' => $this->allocation($investments, $totalInvestido),
        ]);
    }

    public function store(StoreInvestmentRequest $request)
    {
        $data = $request->validated();
        $ownerId = $request->user()->ownerId();
        $valorInicial = (float) ($data['valor_inicial'] ?? 0);

        // Atômico: ou cria o investimento E o aporte inicial, ou nenhum dos dois.
        // O aporte inicial passa pelo MESMO recheque sob lock dos aportes
        // normais (HandlesContributions) — sem isso, dois submits validados na
        // mesma janela reservavam duas vezes o mesmo dinheiro.
        DB::transaction(function () use ($request, $data, $ownerId, $valorInicial) {
            $temAporte = $valorInicial > 0 && ! empty($data['account_id']);

            // ORDEM DE LOCK: conta → pai. A conta é travada ANTES de criar o
            // investimento, para manter a mesma ordem do FundingService.
            $conta = $temAporte ? $this->lockAccount($ownerId, (int) $data['account_id']) : null;

            $investment = Investment::create([
                'user_id' => $ownerId,
                // Autor do investimento: o usuário atual (titular ou dependente que criou).
                'made_by_user_id' => $request->user()->id,
                'name' => $data['name'],
                'classe' => $data['classe'],
                'indexador' => $data['indexador'] ?? null,
                'taxa' => $data['taxa'] ?? null,
            ]);

            // Aporte inicial opcional: se informado (> 0), reserva já o principal
            // da conta escolhida (modelo "cofrinho"; não cria transação).
            if ($conta) {
                // Time-of-use: o disponível pode ter mudado desde a validação.
                $this->assertCabeNoDisponivel($conta, $valorInicial);

                $investment->contributions()->create([
                    'account_id' => $conta->id,
                    'made_by_user_id' => $data['made_by_user_id'] ?? $request->user()->id,
                    'type' => 'aporte',
                    'amount' => $data['valor_inicial'],
                    'date' => $data['date'] ?? now()->toDateString(),
                ]);
            }
        }, attempts: 3);

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
