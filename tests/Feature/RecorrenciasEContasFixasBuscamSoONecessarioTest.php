<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use App\Services\FixedBillService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `/faturas` e o sino buscam só o necessário — com os MESMOS resultados de antes
 * (V-4 e V-6 da auditoria de volume e backup de 06/09/2026).
 *
 * V-4: `FaturaService::recorrenciasParaAvancar` fazia `get()` de TODAS as
 * ocorrências de todas as recorrências do cartão, desde sempre, só para ficar com
 * a mais recente de cada série — 36 linhas hidratadas por assinatura com três anos
 * de uso, a cada abertura de /faturas.
 *
 * V-6: `FixedBillService::occurrences` carregava TODOS os pagamentos de contas
 * fixas, sem janela de datas, e roda em toda página pelo sino. Além disso o sino
 * carregava conta e categoria de cada conta fixa, que ele nunca lê.
 *
 * Cada correção tem dois testes: um que compara a saída nova com a da busca
 * antiga (copiada aqui como referência) num histórico variado e em várias datas,
 * e um que prova que o histórico antigo não é mais carregado nem conta queries.
 */
class RecorrenciasEContasFixasBuscamSoONecessarioTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cartao(int $fechamento, int $vencimento): Account
    {
        return Account::factory()->for($this->user)->creditCard()->create([
            'credit_limit' => 1000000, 'closing_day' => $fechamento, 'due_day' => $vencimento,
        ]);
    }

    private function linha(Account $conta, CarbonImmutable $data, array $extra = []): Transaction
    {
        return Transaction::create($extra + [
            'user_id' => $this->user->id,
            'account_id' => $conta->id,
            'type' => 'expense',
            'amount' => 49.90,
            'description' => 'Linha',
            'date' => $data->toDateString(),
        ]);
    }

    /**
     * Mede uma chamada: quantas queries e QUAIS transações foram hidratadas.
     *
     * @return array{queries: list<string>, carregadas: list<int>, resultado: mixed}
     */
    private function medir(callable $chamada): array
    {
        $carregadas = [];
        $gravando = true;
        Transaction::retrieved(function (Transaction $t) use (&$carregadas, &$gravando) {
            if ($gravando) {
                $carregadas[] = $t->id;
            }
        });

        DB::flushQueryLog();
        DB::enableQueryLog();
        $resultado = $chamada();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $gravando = false;

        return ['queries' => $queries, 'carregadas' => $carregadas, 'resultado' => $resultado];
    }

    // ── V-4 · recorrências para avançar ──────────────────────────────────────

    /** A busca de ANTES, copiada: todas as recorrentes, a mais recente de cada série, filtrada pelo ciclo. */
    private function recorrenciasPelaBuscaAntiga(Account $cartao): array
    {
        $ciclo = $cartao->fresh()->billingCycle();
        if (! $ciclo) {
            return [];
        }

        [$inicioAberto] = $ciclo;

        return Transaction::with(['category'])
            ->where('account_id', $cartao->id)
            ->where('type', 'expense')
            ->where('recurring', true)
            ->whereNotNull('group_id')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->unique('group_id')
            ->filter(fn (Transaction $t) => $t->date->lessThanOrEqualTo($inicioAberto))
            ->pluck('id')
            ->all();
    }

    /** Histórico variado e reproduzível (semente fixa). Devolve quantos empates de data criou. */
    private function historicoDeRecorrencias(Account $cartao, int $semente): int
    {
        mt_srand($semente);
        $empates = 0;

        for ($s = 0; $s < 9; $s++) {
            $grupo = (string) Str::uuid();
            $inicio = CarbonImmutable::parse('2023-09-01')->addDays(mt_rand(0, 1100));
            $meses = mt_rand(1, 16);

            for ($i = 0; $i < $meses; $i++) {
                $data = $inicio->addMonthsNoOverflow($i);
                $this->linha($cartao, $data, [
                    'group_id' => $grupo, 'recurring' => true,
                    'paid_at' => mt_rand(0, 1) ? $data->toDateString() : null,
                ]);

                // Duas ocorrências da mesma série na mesma data: fica a de id maior.
                if (mt_rand(0, 5) === 0) {
                    $this->linha($cartao, $data, ['group_id' => $grupo, 'recurring' => true]);
                    $empates++;
                }
            }
        }

        // Ruído que NÃO pode entrar: compra avulsa, parcelas, estorno (receita) e
        // recorrência legada sem grupo.
        for ($i = 0; $i < 12; $i++) {
            $data = CarbonImmutable::parse('2024-01-05')->addDays(mt_rand(0, 1000));
            $this->linha($cartao, $data);
            $this->linha($cartao, $data, ['group_id' => (string) Str::uuid(), 'installment_no' => 1, 'installments' => 2]);
            $this->linha($cartao, $data, ['type' => 'income']);
            $this->linha($cartao, $data, ['recurring' => true]);
        }

        return $empates;
    }

    public function test_recorrencias_para_avancar_dao_o_mesmo_resultado_da_busca_antiga(): void
    {
        $nubank = $this->cartao(fechamento: 10, vencimento: 20);
        $inter = $this->cartao(fechamento: 28, vencimento: 5);

        $empates = $this->historicoDeRecorrencias($nubank, 20260922)
            + $this->historicoDeRecorrencias($inter, 42);
        $this->assertGreaterThan(0, $empates, 'O histórico precisa ter empate de data para o teste valer.');

        $comparados = 0;
        foreach (['2024-06-10', '2024-06-11', '2025-01-31', '2025-12-28', '2025-12-29', '2026-02-28', '2026-09-22', '2027-01-15'] as $hoje) {
            Carbon::setTestNow($hoje);

            $cards = app(FaturaService::class)->build($this->user->id)['cards']
                ->keyBy(fn ($c) => $c['account']->id);

            foreach ([$nubank, $inter] as $cartao) {
                $novo = $cards[$cartao->id]['recorrenciasParaAvancar']->pluck('id')->all();
                $antigo = $this->recorrenciasPelaBuscaAntiga($cartao);

                $this->assertSame($antigo, $novo, "Resultado diferente no cartão {$cartao->closing_day} em {$hoje}.");
                $comparados += count($novo);
            }
        }

        $this->assertGreaterThan(10, $comparados, 'Poucas séries comparadas — o teste não provaria nada.');
    }

    /**
     * Três assinaturas com a última ocorrência já fechada. Com 12 ou 36 meses de
     * histórico, /faturas faz as MESMAS queries e carrega as MESMAS linhas — e
     * nenhuma ocorrência antiga (que já tem sucessora) é hidratada.
     */
    public function test_recorrencias_para_avancar_nao_carregam_o_historico_das_series(): void
    {
        Carbon::setTestNow('2026-09-15'); // ciclo aberto (10/09, 10/10]
        $cartao = $this->cartao(fechamento: 10, vencimento: 20);

        $grupos = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $meses = function (int $de, int $ate) use ($cartao, $grupos) {
            foreach ($grupos as $g => $grupo) {
                for ($i = $de; $i < $ate; $i++) {
                    $data = CarbonImmutable::parse('2026-09-01')->addDays($g)->subMonthsNoOverflow($i);
                    $this->linha($cartao, $data, ['group_id' => $grupo, 'recurring' => true, 'paid_at' => $data->toDateString()]);
                }
            }
        };

        $meses(0, 12);
        $com12 = $this->medir(fn () => app(FaturaService::class)->build($this->user->id));

        $meses(12, 36);
        $com36 = $this->medir(fn () => app(FaturaService::class)->build($this->user->id));

        $ultimas = Transaction::whereIn('date', ['2026-09-01', '2026-09-02', '2026-09-03'])->pluck('id')->all();
        $antigas = Transaction::whereNotIn('id', $ultimas)->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            $ultimas,
            $com36['resultado']['cards']->first()['recorrenciasParaAvancar']->pluck('id')->all(),
        );
        $this->assertSame([], array_values(array_intersect($antigas, $com36['carregadas'])),
            'Ocorrências antigas das séries foram carregadas só para descobrir a mais recente.');
        $this->assertCount(count($com12['carregadas']), $com36['carregadas'], 'As linhas carregadas cresceram com o histórico.');
        $this->assertCount(count($com12['queries']), $com36['queries'], 'As queries de /faturas cresceram com o histórico.');
    }

    // ── V-6 · contas fixas ───────────────────────────────────────────────────

    /** A projeção de ANTES, copiada: todos os pagamentos, sem janela, e o valor atual da conta. */
    private function ocorrenciasPelaBuscaAntiga(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $bills = FixedBill::where('user_id', $this->user->id)->where('active', true)->orderBy('due_day')->get();

        if ($bills->isEmpty()) {
            return [];
        }

        $pagos = Transaction::whereIn('fixed_bill_id', $bills->pluck('id'))
            ->whereNotNull('competence')
            ->get(['id', 'fixed_bill_id', 'competence', 'amount', 'paid_at'])
            ->keyBy(fn ($t) => $t->fixed_bill_id.':'.CarbonImmutable::parse($t->competence)->format('Y-m'));

        $hoje = CarbonImmutable::today();
        $itens = collect();

        foreach ($bills as $bill) {
            $comeco = CarbonImmutable::parse($bill->starts_on)->startOfDay();
            $inicio = $comeco->startOfMonth();
            $fim = $bill->ends_on ? CarbonImmutable::parse($bill->ends_on)->startOfMonth() : null;

            $competencia = $de->startOfMonth()->greaterThan($inicio) ? $de->startOfMonth() : $inicio;
            $ultima = $ate->startOfMonth();
            if ($fim && $fim->lessThan($ultima)) {
                $ultima = $fim;
            }

            while ($competencia->lessThanOrEqualTo($ultima)) {
                $pagamento = $pagos->get($bill->id.':'.$competencia->format('Y-m'));
                $vencimento = $bill->dueDateFor($competencia);

                if ($vencimento->lessThan($comeco)) {
                    $competencia = $competencia->addMonthNoOverflow();

                    continue;
                }

                $itens->push([
                    'bill' => $bill->id,
                    'competence' => $competencia->toDateString(),
                    'vencimento' => $vencimento->toDateString(),
                    'valor' => $pagamento ? (float) $pagamento->amount : (float) $bill->amount,
                    'paga' => (bool) $pagamento,
                    'pagamento' => $pagamento?->id,
                    'vencida' => ! $pagamento && $vencimento->lessThan($hoje),
                    'diasRestantes' => (int) $hoje->diffInDays($vencimento, false),
                    '_ts' => $vencimento->timestamp,
                ]);

                $competencia = $competencia->addMonthNoOverflow();
            }
        }

        return $itens->sortBy('_ts')->values()->map(fn ($i) => array_diff_key($i, ['_ts' => 1]))->all();
    }

    private function comoLista(Collection $ocorrencias): array
    {
        return $ocorrencias->map(fn ($o) => [
            'bill' => $o['bill']->id,
            'competence' => $o['competence']->toDateString(),
            'vencimento' => $o['vencimento']->toDateString(),
            'valor' => $o['valor'],
            'paga' => $o['paga'],
            'pagamento' => $o['pagamento']?->id,
            'vencida' => $o['vencida'],
            'diasRestantes' => $o['diasRestantes'],
        ])->values()->all();
    }

    private function contaFixa(array $dados): FixedBill
    {
        return FixedBill::factory()->for($this->user)->create($dados + ['name' => 'Conta', 'amount' => 800]);
    }

    private function pagamento(FixedBill $bill, string $competencia, float $valor): void
    {
        Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $bill->account_id,
            'type' => 'expense',
            'amount' => $valor,
            'date' => $competencia,
            'paid_at' => $competencia,
            'fixed_bill_id' => $bill->id,
            'competence' => $competencia,
        ]);
    }

    public function test_contas_fixas_dao_o_mesmo_resultado_da_busca_antiga(): void
    {
        Carbon::setTestNow('2026-09-15');
        mt_srand(7);
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 0]);

        $contas = [
            $this->contaFixa(['due_day' => 1, 'starts_on' => '2023-03-01', 'account_id' => $conta->id]),
            $this->contaFixa(['due_day' => 10, 'starts_on' => '2025-08-20', 'account_id' => $conta->id]),
            $this->contaFixa(['due_day' => 28, 'starts_on' => '2024-11-01', 'ends_on' => '2026-06-15', 'account_id' => $conta->id]),
            $this->contaFixa(['due_day' => 30, 'starts_on' => '2026-01-01', 'ends_on' => '2026-09-30', 'account_id' => $conta->id]),
            $this->contaFixa(['due_day' => 31, 'starts_on' => '2026-10-01', 'account_id' => $conta->id]),
            $this->contaFixa(['due_day' => 10, 'starts_on' => '2024-01-01', 'active' => false, 'account_id' => $conta->id]),
        ];

        // Pagamentos espalhados de 2023 a nov/2026 (inclusive adiantados), metade dos meses.
        foreach ($contas as $bill) {
            for ($mes = CarbonImmutable::parse('2023-01-01'); $mes->lessThanOrEqualTo(CarbonImmutable::parse('2026-11-01')); $mes = $mes->addMonthNoOverflow()) {
                if (mt_rand(0, 1)) {
                    $this->pagamento($bill, $mes->toDateString(), mt_rand(50000, 150000) / 100);
                }
            }
        }
        // Competência fora do dia 01 (a tela nunca grava assim, mas a busca não pode depender disso).
        $this->pagamento($contas[0], '2026-02-15', 777.77);
        Transaction::where('fixed_bill_id', $contas[0]->id)->where('competence', '2026-02-01')->delete();

        $janelasFixas = [['2023-01-01', '2027-12-31'], ['2026-06-10', '2026-06-10'], ['2026-02-01', '2026-04-30'], ['2022-01-01', '2022-12-31']];
        $comparadas = 0;

        // 10/02/2026: a competência de dia 15 cai no ÚLTIMO mês da janela do sino.
        foreach (['2025-12-31', '2026-02-10', '2026-02-28', '2026-03-01', '2026-06-30', '2026-09-15', '2026-09-24', '2026-12-31'] as $hoje) {
            Carbon::setTestNow($hoje);
            $h = CarbonImmutable::today();

            $janelas = array_merge($janelasFixas, [[
                $h->subMonthsNoOverflow(FixedBillService::MAX_MESES_ATRAS)->toDateString(),
                $h->addDays(FixedBillService::DIAS_A_FRENTE)->toDateString(),
            ]]);

            foreach ($janelas as [$de, $ate]) {
                $de = CarbonImmutable::parse($de);
                $ate = CarbonImmutable::parse($ate);

                $novo = $this->comoLista(app(FixedBillService::class)->occurrences($this->user->id, $de, $ate));
                $antigo = $this->ocorrenciasPelaBuscaAntiga($de, $ate);

                $this->assertSame($antigo, $novo, "Projeção diferente em {$hoje}, janela {$de->toDateString()}..{$ate->toDateString()}.");
                $comparadas += count($novo);
            }

            // Sem as relações (o caminho do sino), a saída é a mesma.
            $this->assertSame(
                $this->comoLista(app(FixedBillService::class)->currentAndOverdue($this->user->id)),
                $this->comoLista(app(FixedBillService::class)->currentAndOverdue($this->user->id, comRelacoes: false)),
            );
        }

        $this->assertGreaterThan(100, $comparadas, 'Poucas competências comparadas — o teste não provaria nada.');
    }

    /** 37 meses pagos: a projeção do sino carrega só os pagamentos da janela (13 meses). */
    public function test_contas_fixas_nao_carregam_pagamentos_fora_da_janela(): void
    {
        Carbon::setTestNow('2026-09-15');
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 0]);
        $aluguel = $this->contaFixa(['due_day' => 5, 'starts_on' => '2023-09-01', 'account_id' => $conta->id]);

        for ($mes = CarbonImmutable::parse('2023-09-01'); $mes->lessThanOrEqualTo(CarbonImmutable::parse('2026-09-01')); $mes = $mes->addMonthNoOverflow()) {
            $this->pagamento($aluguel, $mes->toDateString(), 800);
        }
        $this->assertSame(37, Transaction::count());

        $medida = $this->medir(fn () => app(FixedBillService::class)->currentAndOverdue($this->user->id));

        // Janela: set/2025 (hoje − 12 meses) .. set/2026 (hoje + 7 dias) = 13 competências.
        $this->assertCount(13, $medida['carregadas'], 'Pagamentos fora da janela foram carregados.');
        $this->assertSame(['2026-09-01'], $medida['resultado']->map(fn ($o) => $o['competence']->toDateString())->all());
    }

    /**
     * O sino roda em toda página: com uma conta fixa ou com seis e 180 pagamentos, as
     * MESMAS queries — e nenhuma para buscar categoria, que ele não mostra.
     */
    public function test_sino_faz_as_mesmas_queries_com_poucas_ou_muitas_contas_fixas(): void
    {
        Carbon::setTestNow('2026-09-15');
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 0]);
        $categoria = Category::factory()->for($this->user)->expense()->create();

        $this->contaFixa(['due_day' => 20, 'starts_on' => '2026-09-01', 'account_id' => $conta->id, 'category_id' => $categoria->id]);
        $pouco = $this->medir(fn () => app(FaturaService::class)->upcomingDue($this->user->id));

        for ($i = 0; $i < 5; $i++) {
            $bill = $this->contaFixa(['due_day' => 5 + $i, 'starts_on' => '2023-09-01', 'account_id' => $conta->id, 'category_id' => $categoria->id]);
            for ($mes = CarbonImmutable::parse('2023-09-01'); $mes->lessThanOrEqualTo(CarbonImmutable::parse('2026-08-01')); $mes = $mes->addMonthNoOverflow()) {
                $this->pagamento($bill, $mes->toDateString(), 100);
            }
        }
        $muito = $this->medir(fn () => app(FaturaService::class)->upcomingDue($this->user->id));

        $this->assertCount(count($pouco['queries']), $muito['queries'], 'As queries do sino cresceram com as contas fixas.');
        $this->assertLessThanOrEqual(4, count($muito['queries']), implode("\n", $muito['queries']));
        // Pela gramática do banco (M-3): com `"categories"` fixo, no MySQL a asserção passaria
        // sem testar nada.
        $categorias = DB::connection()->getQueryGrammar()->wrapTable('categories');
        foreach ($muito['queries'] as $sql) {
            $this->assertStringNotContainsString($categorias, $sql, 'O sino buscou categoria de conta fixa, que ele não mostra.');
        }

        // E o sino continua avisando o que deve: as 5 competências de setembro em aberto (vencidas) + a nova.
        $this->assertCount(6, $muito['resultado']->where('tipo', 'conta_fixa'));
    }
}
