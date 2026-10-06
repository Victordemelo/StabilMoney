<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Atividade;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aportes e resgates aparecem em Movimentações (out/2026 — pedido do Victor: "aportei nos
 * investimentos, saiu do meu saldo, porém não apareceu em Movimentações").
 *
 * Não são lançamentos: o aporte não é despesa (só carimba o dinheiro como reservado), então
 * não entram em "Receitas"/"Despesas", nem em filtro de categoria, e não se editam por lá.
 * Mas tiram (ou devolvem) dinheiro do saldo disponível, e quem olha o extrato precisa vê-los.
 */
class AportesEResgatesEmMovimentacoesTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private Account $corrente;

    protected function setUp(): void
    {
        parent::setUp();

        // Meio-dia: os testes andam o relógio alguns minutos, e perto da meia-noite o "hoje" viraria.
        $this->travelTo(now()->setTime(12, 0));

        $this->titular = User::factory()->create(['name' => 'Victor']);
        $this->corrente = Account::factory()->for($this->titular)->create([
            'name' => 'Conta Corrente', 'type' => 'checking', 'bank' => 'nubank',
            'initial_balance' => 5000, 'overdraft_limit' => 0,
        ]);
    }

    private function dia(int $offset): string
    {
        return CarbonImmutable::today()->subDays($offset)->toDateString();
    }

    public function test_aporte_feito_pela_tela_aparece_na_lista_e_na_atividade(): void
    {
        $tesouro = Investment::factory()->for($this->titular)->create(['name' => 'Tesouro Selic']);

        $this->actingAs($this->titular)->post(route('investimentos.aportes.store', $tesouro), [
            'account_id' => $this->corrente->id, 'amount' => '300,00', 'date' => $this->dia(0),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->titular)->get(route('transactions.index'))->assertOk()
            ->assertSee('Aporte em Tesouro Selic')
            ->assertSee('Saiu de Conta Corrente')
            ->assertSee('− R$ 300,00')
            ->assertSee('<span class="tx-tag">Investimento</span>', false);

        // E na auditoria (Configurações › Atividade), que já registrava.
        $this->assertSame(
            'Victor aplicou R$ 300,00 em “Tesouro Selic”, saindo de Conta Corrente (Nubank)',
            Atividade::where('acao', 'investimento.aporte')->sole()->descricao,
        );
        $this->actingAs($this->titular)->get(route('settings', 'atividade'))->assertOk()
            ->assertSee('aplicou R$ 300,00 em “Tesouro Selic”', false);
    }

    public function test_misturados_com_os_lancamentos_na_ordem_das_datas(): void
    {
        $viagem = Goal::factory()->for($this->titular)->create(['name' => 'Viagem', 'emoji' => '✈️']);
        $tesouro = Investment::factory()->for($this->titular)->create(['name' => 'Tesouro']);

        Transaction::factory()->for($this->titular)->create([
            'account_id' => $this->corrente->id, 'type' => 'expense', 'amount' => 10, 'description' => 'Mercado', 'date' => $this->dia(3),
        ]);
        $viagem->contributions()->create(['account_id' => $this->corrente->id, 'type' => 'aporte', 'amount' => 200, 'date' => $this->dia(2)]);
        Transaction::factory()->for($this->titular)->create([
            'account_id' => $this->corrente->id, 'type' => 'income', 'amount' => 50, 'description' => 'Pix recebido', 'date' => $this->dia(1),
        ]);
        // No mesmo dia, quem desempata é a hora em que foi feito: o aporte veio depois do Pix.
        $this->travel(5)->minutes();
        $tesouro->contributions()->create(['account_id' => $this->corrente->id, 'type' => 'aporte', 'amount' => 900, 'date' => $this->dia(1)]);
        $tesouro->contributions()->create(['account_id' => $this->corrente->id, 'type' => 'resgate', 'amount' => 100, 'date' => $this->dia(0)]);

        $html = $this->actingAs($this->titular)->get(route('transactions.index'))->assertOk()->getContent();

        $ordem = ['Resgate de Tesouro', 'Aporte em Tesouro', 'Pix recebido', 'Aporte em Viagem', 'Mercado'];
        $posicoes = array_map(fn ($t) => strpos($html, $t), $ordem);
        $this->assertNotContains(false, $posicoes);
        $copia = $posicoes;
        sort($copia);
        $this->assertSame($copia, $posicoes, 'a lista é uma só, por data (mais recente primeiro)');

        $this->assertStringContainsString('+ R$ 100,00', $html);
        $this->assertStringContainsString('Voltou para Conta Corrente', $html);
        $this->assertStringContainsString('href="'.route('metas.index').'"', $html);
    }

    public function test_filtros_receitas_despesas_e_categoria_deixam_os_aportes_de_fora_e_ha_filtro_proprio(): void
    {
        $tesouro = Investment::factory()->for($this->titular)->create(['name' => 'Tesouro']);
        $tesouro->contributions()->create(['account_id' => $this->corrente->id, 'type' => 'aporte', 'amount' => 900, 'date' => $this->dia(0)]);
        $categoria = Category::factory()->expense()->for($this->titular)->create(['name' => 'Lazer']);
        Transaction::factory()->for($this->titular)->create([
            'account_id' => $this->corrente->id, 'category_id' => $categoria->id, 'type' => 'expense', 'amount' => 10, 'description' => 'Cinema', 'date' => $this->dia(0),
        ]);

        foreach (['expense', 'income', 'transfer'] as $tipo) {
            $this->actingAs($this->titular)->get(route('transactions.index', ['type' => $tipo]))->assertOk()
                ->assertDontSee('Aporte em Tesouro');
        }
        $this->actingAs($this->titular)->get(route('transactions.index', ['category' => $categoria->id]))->assertOk()
            ->assertSee('Cinema')->assertDontSee('Aporte em Tesouro');

        $this->actingAs($this->titular)->get(route('transactions.index', ['type' => 'reserva']))->assertOk()
            ->assertSee('Aporte em Tesouro')->assertDontSee('Cinema')
            ->assertSee('<option value="reserva" selected>Metas e investimentos</option>', false);
    }

    public function test_filtro_de_conta_e_de_periodo_valem_para_os_aportes(): void
    {
        $poupanca = Account::factory()->for($this->titular)->create(['name' => 'Poupança', 'type' => 'savings', 'initial_balance' => 1000]);
        $tesouro = Investment::factory()->for($this->titular)->create(['name' => 'Tesouro']);
        $tesouro->contributions()->create(['account_id' => $this->corrente->id, 'type' => 'aporte', 'amount' => 900, 'date' => $this->dia(10)]);
        $tesouro->contributions()->create(['account_id' => $poupanca->id, 'type' => 'aporte', 'amount' => 70, 'date' => $this->dia(0)]);

        $this->actingAs($this->titular)->get(route('transactions.index', ['account' => $poupanca->id]))->assertOk()
            ->assertSee('R$ 70,00')->assertDontSee('R$ 900,00');
        $this->actingAs($this->titular)->get(route('transactions.index', ['de' => $this->dia(2)]))->assertOk()
            ->assertSee('R$ 70,00')->assertDontSee('R$ 900,00');
    }

    public function test_aporte_de_outra_familia_nunca_aparece(): void
    {
        $estranho = User::factory()->create();
        $contaDele = Account::factory()->for($estranho)->create(['type' => 'checking', 'initial_balance' => 5000]);
        $dele = Investment::factory()->for($estranho)->create(['name' => 'Cofre secreto']);
        InvestmentContribution::factory()->for($dele)->create(['account_id' => $contaDele->id, 'type' => 'aporte', 'amount' => 1234, 'date' => $this->dia(0)]);

        $this->actingAs($this->titular)->get(route('transactions.index'))->assertOk()
            ->assertDontSee('Cofre secreto')->assertDontSee('1.234,00');
    }

    public function test_dependente_ve_os_aportes_da_familia_e_a_paginacao_conta_os_dois(): void
    {
        $maria = User::factory()->create(['name' => 'Maria', 'account_owner_id' => $this->titular->id, 'is_admin' => false]);
        $tesouro = Investment::factory()->for($this->titular)->create(['name' => 'Tesouro']);

        for ($i = 0; $i < 10; $i++) {
            Transaction::factory()->for($this->titular)->create([
                'account_id' => $this->corrente->id, 'type' => 'expense', 'amount' => 1, 'description' => "Gasto {$i}", 'date' => $this->dia(20 + $i),
            ]);
            $tesouro->contributions()->create(['account_id' => $this->corrente->id, 'type' => 'aporte', 'amount' => 1, 'date' => $this->dia($i)]);
        }

        $pagina1 = $this->actingAs($maria)->get(route('transactions.index'))->assertOk();
        $this->assertSame(20, $pagina1->viewData('transactions')->total());
        $this->assertCount(15, $pagina1->viewData('transactions')->items());
        $pagina1->assertSee('Aporte em Tesouro')->assertDontSee('Gasto 9');

        $this->actingAs($maria)->get(route('transactions.index', ['page' => 2]))->assertOk()
            ->assertSee('Gasto 9')->assertDontSee('Aporte em Tesouro');
    }

    public function test_o_ate_inclui_o_aporte_feito_no_proprio_dia(): void
    {
        // A data do aporte tem cast `date` (sem formato): no sqlite ela é gravada com a hora
        // ("Y-m-d 00:00:00"), e comparar `<= 'Y-m-d'` como texto deixava de fora o próprio dia.
        $tesouro = Investment::factory()->for($this->titular)->create(['name' => 'Tesouro']);
        $tesouro->contributions()->create(['account_id' => $this->corrente->id, 'type' => 'aporte', 'amount' => 70, 'date' => $this->dia(3)]);

        $this->actingAs($this->titular)->get(route('transactions.index', ['de' => $this->dia(3), 'ate' => $this->dia(3)]))->assertOk()
            ->assertSee('Aporte em Tesouro');
        $this->actingAs($this->titular)->get(route('transactions.index', ['ate' => $this->dia(4)]))->assertOk()
            ->assertDontSee('Aporte em Tesouro');
    }

    public function test_no_mesmo_dia_vale_a_ordem_em_que_foram_feitos(): void
    {
        $tesouro = Investment::factory()->for($this->titular)->create(['name' => 'Tesouro']);
        $this->travelTo(now()->setTime(9, 0));
        $tesouro->contributions()->create(['account_id' => $this->corrente->id, 'type' => 'aporte', 'amount' => 70, 'date' => $this->dia(2)]);
        $this->travelTo(now()->setTime(10, 0));
        Transaction::factory()->for($this->titular)->create([
            'account_id' => $this->corrente->id, 'type' => 'expense', 'amount' => 10, 'description' => 'Padaria', 'date' => $this->dia(2),
        ]);

        $html = $this->actingAs($this->titular)->get(route('transactions.index'))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Aporte em Tesouro'), strpos($html, 'Padaria'), 'o lançamento feito depois vem antes');
    }

    public function test_tipo_desconhecido_vale_como_todos(): void
    {
        $tesouro = Investment::factory()->for($this->titular)->create(['name' => 'Tesouro']);
        $tesouro->contributions()->create(['account_id' => $this->corrente->id, 'type' => 'aporte', 'amount' => 70, 'date' => $this->dia(0)]);
        Transaction::factory()->for($this->titular)->create([
            'account_id' => $this->corrente->id, 'type' => 'expense', 'amount' => 10, 'description' => 'Padaria', 'date' => $this->dia(0),
        ]);

        $this->actingAs($this->titular)->get(route('transactions.index', ['type' => 'xyz']))->assertOk()
            ->assertSee('Padaria')->assertSee('Aporte em Tesouro');
    }

    public function test_mesmo_id_e_mesmo_instante_em_tabelas_diferentes_nao_somem_na_paginacao(): void
    {
        // Lançamento e aporte com o MESMO id, data e criação: a origem desempata, e as duas
        // páginas juntas trazem cada linha uma vez só.
        $tesouro = Investment::factory()->for($this->titular)->create(['name' => 'Tesouro']);
        for ($i = 0; $i < 16; $i++) {
            Transaction::factory()->for($this->titular)->create([
                'account_id' => $this->corrente->id, 'type' => 'expense', 'amount' => 1, 'description' => "Gasto {$i}", 'date' => $this->dia(0),
            ]);
            $tesouro->contributions()->create(['account_id' => $this->corrente->id, 'type' => 'aporte', 'amount' => 1, 'date' => $this->dia(0)]);
        }

        $vistos = [];
        foreach ([1, 2, 3] as $pagina) {
            foreach ($this->actingAs($this->titular)->get(route('transactions.index', ['page' => $pagina]))->viewData('transactions')->items() as $item) {
                $vistos[] = class_basename($item).'#'.$item->id;
            }
        }

        $this->assertCount(32, $vistos);
        $this->assertCount(32, array_unique($vistos));
    }
}
