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

        if ($erro = $this->travaDeExclusaoComContaNoVermelho($investimento)) {
            return back()->withErrors(['investimento' => $erro]);
        }

        // As contributions caem junto (cascadeOnDelete) — o dinheiro volta a ficar
        // disponível nas contas, já que deixa de estar reservado.
        $investimento->delete();

        return redirect()->route('investimentos.index')
            ->with('status', 'Investimento removido.');
    }

    /**
     * Trava de INTENÇÃO E AUDITORIA — não de criação de dinheiro.
     *
     * Excluir o investimento e resgatá-lo por inteiro têm efeito IDÊNTICO sobre
     * o disponível da conta: os dois derrubam o `reserved` dela no mesmo valor.
     * Nenhum dos dois inventa dinheiro, e não é isso que estamos evitando.
     *
     * O que a exclusão destrói é o REGISTRO. Sai de cena a linha de resgate que
     * diria "foi o CDB que cobriu o cheque especial", e some com ela a única
     * pista de como aquele saldo negativo virou positivo. Somado a isso,
     * "excluir" é um caminho ACIDENTAL: quem clica ali está limpando uma lista,
     * não decidindo quitar dívida com a poupança. Então, quando há dinheiro
     * aplicado E a conta de origem está no vermelho, exigimos o caminho
     * explícito (resgatar), que deixa rastro.
     *
     * Investimento vazio, ou contas todas no azul: nada a proteger — libera.
     *
     * Devolve a mensagem PT-BR do bloqueio, ou null quando pode excluir.
     */
    private function travaDeExclusaoComContaNoVermelho(Investment $investimento): ?string
    {
        // Nada aplicado: excluir não mexe no disponível de conta nenhuma.
        if ($investimento->aplicado <= 0.001) {
            return null;
        }

        // Quanto CADA conta ainda tem aplicado aqui (aportes − resgates), numa
        // query só. Conta que já resgatou tudo não é afetada pela exclusão.
        $reservadoPorConta = InvestmentContribution::where('investment_id', $investimento->id)
            ->groupBy('account_id')
            ->selectRaw('account_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'aporte' THEN amount ELSE -amount END), 0) AS total")
            ->pluck('total', 'account_id')
            ->filter(fn ($total) => (float) $total > 0.001);

        if ($reservadoPorConta->isEmpty()) {
            return null;
        }

        // Escopado na família do próprio investimento: um dado cruzado jamais
        // pode fazer a mensagem de erro citar o nome da conta de outra pessoa.
        $contas = Account::where('user_id', $investimento->user_id)
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

        $aplicadoDela = (float) $reservadoPorConta->get($negativa->id, 0);

        return 'A conta “' . $negativa->name . '” está em ' . Brl::format($negativa->available)
            . ' e este investimento tem ' . Brl::format($aplicadoDela) . ' aplicados a partir dela. '
            . 'Excluir aqui zeraria esse saldo negativo em silêncio, sem deixar registrado que foi '
            . 'a aplicação que o cobriu. Faça um resgate para a conta “' . $negativa->name . '” '
            . '(aí fica gravado de onde saiu o dinheiro) ou deixe o saldo dela positivo antes de excluir.';
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
