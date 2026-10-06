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
}
