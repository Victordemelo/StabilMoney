<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesContributions;
use App\Http\Requests\StoreInvestmentRequest;
use App\Http\Requests\UpdateInvestmentRequest;
use App\Models\Account;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\User;
use App\Support\Brl;
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

        // Os selects de aporte, resgate e do novo investimento mostram o DISPONÍVEL de
        // cada conta: sem isto eram 6 queries POR conta, cada SUM varrendo o histórico
        // dela (V-5 da auditoria de volume). Com o pré-carregamento, 4 no total.
        Account::preloadMoney($accounts);

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
        $clientUuid = $data['client_uuid'] ?? null;

        // IDEMPOTÊNCIA do aporte inicial. O uuid vive na contribuição (é ela a
        // escrita de dinheiro), e aqui o pai ainda não existe — então a checagem
        // é pela família: um uuid que já tem contribuição em qualquer investimento
        // desta família é reenvio, e a resposta é a mesma do sucesso. Sem valor
        // inicial não há contribuição, e criar o cadastro duas vezes não move
        // dinheiro — o uuid não tem o que proteger.
        if ($clientUuid && $valorInicial > 0 && InvestmentContribution::where('client_uuid', $clientUuid)
            ->whereHas('investment', fn ($q) => $q->where('user_id', $ownerId))
            ->exists()
        ) {
            return redirect()->route('investimentos.index')
                ->with('status', 'Investimento criado com sucesso.');
        }

        // Atômico: ou cria o investimento E o aporte inicial, ou nenhum dos dois.
        // O aporte inicial passa pelo MESMO recheque sob lock dos aportes
        // normais (HandlesContributions) — sem isso, dois submits validados na
        // mesma janela reservavam duas vezes o mesmo dinheiro.
        DB::transaction(function () use ($request, $data, $ownerId, $valorInicial, $clientUuid) {
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
                    'client_uuid' => $clientUuid,
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

        // Investimento com dinheiro aplicado não sai (out/2026 — decisão do Victor, a mesma
        // regra das metas): primeiro resgata tudo, pelo caminho que deixa registro (e aparece em
        // Movimentações), depois exclui. Antes só barrava com a conta de origem no vermelho; no
        // azul, excluir devolvia o dinheiro em silêncio e o histórico de aportes sumia junto
        // (cascade) — `ExclusaoDeInvestimentoSoZeradoTest`.
        //
        // A conferência e a exclusão acontecem SOB A TRAVA do investimento (auditoria de
        // concorrência, pendência 5): um aporte simultâneo ou já entrou (e o investimento não
        // sai) ou espera, e ao travar descobre que ele sumiu (`HandlesContributions::lockParent`).
        $erro = DB::transaction(function () use ($investimento) {
            $travado = Investment::whereKey($investimento->id)->lockForUpdate()->first();
            if ($travado === null) {
                return null; // outra pessoa da família já excluiu
            }

            $aplicado = (float) $travado->aplicado;
            if ($aplicado > 0.001) {
                return 'O investimento “'.$travado->name.'” ainda tem '.Brl::format($aplicado)
                    .' aplicados. Resgate todo o dinheiro dele (botão Resgatar) antes de excluí-lo.';
            }

            // Zerado: os aportes e resgates (que se anulam) caem junto (cascadeOnDelete).
            $travado->delete();

            return null;
        }, attempts: 3);

        if ($erro !== null) {
            return back()->withErrors(['investimento' => $erro]);
        }

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
