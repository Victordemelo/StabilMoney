<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Os cards de Metas e Investimentos do dashboard não custam queries por item — e mostram
 * exatamente os mesmos números de antes.
 *
 * `DashboardService::featureResumos` lia `$meta->saved` e `$investimento->aplicado` de
 * cada item para somar e ordenar, e cada accessor faz 2 SUMs: com 5 metas e 4
 * investimentos eram 18 queries a cada abertura do dashboard (V-3 da auditoria de volume
 * de 06/09/2026). Agora são 2 queries agregadas, com a mesma conta dos accessors.
 *
 * A segunda metade importa tanto quanto a primeira: os números passaram a vir de outra
 * query, então o teste compara, item a item, com o accessor de verdade — inclusive nos
 * casos de borda (resgate maior que o aporte, alvo zerado, acima de 100%, meta sem
 * contribuição e um tipo que não é aporte nem resgate, que o accessor ignora).
 */
class ResumosDoDashboardSemNMaisUmTest extends TestCase
{
    use RefreshDatabase;

    private function familiaCom(int $metas, int $investimentos): User
    {
        $titular = User::factory()->create(['is_admin' => true]);
        $conta = Account::factory()->for($titular)->create(['type' => 'checking', 'initial_balance' => 100000]);

        for ($i = 0; $i < $metas; $i++) {
            $meta = Goal::factory()->for($titular)->create(['target_amount' => 1000]);
            $meta->contributions()->create(['account_id' => $conta->id, 'type' => 'aporte', 'amount' => 100 + $i, 'date' => now()->toDateString()]);
            $meta->contributions()->create(['account_id' => $conta->id, 'type' => 'resgate', 'amount' => 10, 'date' => now()->toDateString()]);
        }

        for ($i = 0; $i < $investimentos; $i++) {
            $inv = Investment::factory()->for($titular)->create();
            $inv->contributions()->create(['account_id' => $conta->id, 'type' => 'aporte', 'amount' => 500 + $i, 'date' => now()->toDateString()]);
            $inv->contributions()->create(['account_id' => $conta->id, 'type' => 'resgate', 'amount' => 50, 'date' => now()->toDateString()]);
        }

        return $titular;
    }

    /** @return array{0: int, 1: array<string, mixed>} queries do build e o resultado */
    private function construir(User $titular): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $resultado = app(DashboardService::class)->build($titular->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $resultado];
    }

    public function test_o_dashboard_nao_custa_queries_por_meta_nem_por_investimento(): void
    {
        [$poucos] = $this->construir($this->familiaCom(1, 1));
        [$muitos, $resultado] = $this->construir($this->familiaCom(6, 5));

        $this->assertSame(6, $resultado['metasResumo']['count']);
        $this->assertSame(5, $resultado['investimentosResumo']['count']);
        $this->assertSame(
            $poucos,
            $muitos,
            "O dashboard voltou a consultar o banco por meta/investimento: {$poucos} queries com 1+1, {$muitos} com 6+5."
        );
    }

    public function test_os_resumos_mostram_os_mesmos_numeros_dos_accessors(): void
    {
        $titular = User::factory()->create(['is_admin' => true]);
        $conta = Account::factory()->for($titular)->create(['type' => 'checking', 'initial_balance' => 100000]);
        $hoje = now()->toDateString();

        $metas = [
            // [alvo, contribuições]
            'comum' => [1000, [['aporte', 300.10], ['aporte', 99.99], ['resgate', 50.05]]],
            'acima do alvo' => [200, [['aporte', 350]]],
            'alvo zerado' => [0, [['aporte', 40]]],
            'resgatou mais que aportou' => [500, [['aporte', 30], ['resgate', 80.5]]],
            'sem contribuição' => [700, []],
            // O seeder antigo gravava 'deposit' (o certo é 'aporte'): o accessor ignora.
            'tipo estranho' => [900, [['aporte', 10], ['deposit', 999]]],
            'empata com a comum' => [1000, [['aporte', 350.04]]],
        ];
        foreach ($metas as $nome => [$alvo, $contribuicoes]) {
            $meta = Goal::factory()->for($titular)->create(['name' => $nome, 'target_amount' => $alvo]);
            foreach ($contribuicoes as [$tipo, $valor]) {
                $meta->contributions()->create(['account_id' => $conta->id, 'type' => $tipo, 'amount' => $valor, 'date' => $hoje]);
            }
        }

        foreach (['CDB' => [['aporte', 1000.33], ['resgate', 0.33]], 'Ações' => [['aporte', 2500]], 'Vazio' => [], 'Cripto' => [['aporte', 10], ['resgate', 25]]] as $nome => $contribuicoes) {
            $inv = Investment::factory()->for($titular)->create(['name' => $nome]);
            foreach ($contribuicoes as [$tipo, $valor]) {
                $inv->contributions()->create(['account_id' => $conta->id, 'type' => $tipo, 'amount' => $valor, 'date' => $hoje]);
            }
        }

        [, $resultado] = $this->construir($titular);

        // O que os accessors dizem, lendo tudo de novo do banco (instâncias novas, sem cache).
        $metasDoBanco = Goal::where('user_id', $titular->id)->orderByDesc('id')->get();
        $investimentosDoBanco = Investment::where('user_id', $titular->id)->orderByDesc('id')->get();

        $this->assertSame(round((float) $metasDoBanco->sum(fn (Goal $g) => $g->saved), 2), $resultado['metasResumo']['total']);
        $this->assertSame(
            $metasDoBanco->sortByDesc(fn (Goal $g) => $g->saved)->take(3)->map(fn (Goal $g) => [
                'name' => $g->name, 'saved' => $g->saved, 'progress' => $g->progress,
            ])->values()->all(),
            $resultado['metasResumo']['top'],
        );

        $this->assertSame(round((float) $investimentosDoBanco->sum(fn (Investment $i) => $i->aplicado), 2), $resultado['investimentosResumo']['total']);
        $this->assertSame(
            $investimentosDoBanco->sortByDesc(fn (Investment $i) => $i->aplicado)->take(3)->map(fn (Investment $i) => [
                'name' => $i->name, 'aplicado' => $i->aplicado, 'classe' => Investment::CLASSES[$i->classe] ?? $i->classe,
            ])->values()->all(),
            $resultado['investimentosResumo']['top'],
        );
    }

    /**
     * O progresso de TODAS as metas bate com o accessor, não só o das três do topo — é a
     * regra copiada de `Goal::progress` que precisa continuar igual.
     */
    public function test_o_progresso_calculado_bate_com_o_do_accessor_em_toda_meta(): void
    {
        // [alvo, guardado]: arredondamento nas duas direções da meia unidade, alvo
        // minúsculo, alvo zerado, e guardado negativo (resgate maior que o aporte).
        foreach ([[1000, 333.33], [3, 1], [0.01, 5], [0, 0], [1000, 995], [1000, 994.99], [700, -20]] as [$alvo, $guardado]) {
            // Uma família por caso, com uma meta só: ela é sempre a primeira do topo.
            $titular = User::factory()->create(['is_admin' => true]);
            $conta = Account::factory()->for($titular)->create(['type' => 'checking', 'initial_balance' => 100000]);
            $meta = Goal::factory()->for($titular)->create(['target_amount' => $alvo]);

            if ($guardado != 0) {
                $meta->contributions()->create([
                    'account_id' => $conta->id,
                    'type' => $guardado > 0 ? 'aporte' : 'resgate',
                    'amount' => abs($guardado),
                    'date' => now()->toDateString(),
                ]);
            }

            [, $resultado] = $this->construir($titular);
            $doBanco = $meta->fresh();

            $this->assertSame($doBanco->saved, $resultado['metasResumo']['top'][0]['saved'], "Guardado da meta de alvo {$alvo}.");
            $this->assertSame($doBanco->progress, $resultado['metasResumo']['top'][0]['progress'], "Progresso da meta de alvo {$alvo}.");
        }
    }
}
