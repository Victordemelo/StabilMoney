<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Histórico: filtro por período ("de" / "até"), somado aos de tipo e conta.
 *
 * O filtro chega pela URL, então qualquer um pode digitar `?de=ontem`. A regra
 * aqui é: data inválida é IGNORADA, nunca derruba a listagem — filtro é
 * conveniência, e uma tela de erro no lugar do histórico inteiro é pior que um
 * filtro que não pegou.
 */
class FiltroDePeriodoNoHistoricoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'initial_balance' => 50000,
        ]);

        foreach (['2026-06-10', '2026-07-20', '2026-08-05', '2026-08-14'] as $data) {
            Transaction::factory()->for($this->user)->for($this->conta)->expense()->create([
                'amount' => 100,
                'date' => $data,
                'description' => 'Gasto '.$data,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function listar(array $filtros = []): Collection
    {
        return $this->actingAs($this->user)
            ->get(route('transactions.index', $filtros))
            ->assertOk()
            ->viewData('transactions')
            ->pluck('description');
    }

    public function test_filtra_a_partir_de_uma_data(): void
    {
        $itens = $this->listar(['de' => '2026-08-01']);

        $this->assertCount(2, $itens);
        $this->assertTrue($itens->contains('Gasto 2026-08-05'));
        $this->assertFalse($itens->contains('Gasto 2026-07-20'));
    }

    public function test_filtra_ate_uma_data(): void
    {
        $itens = $this->listar(['ate' => '2026-07-01']);

        $this->assertCount(1, $itens);
        $this->assertTrue($itens->contains('Gasto 2026-06-10'));
    }

    public function test_filtra_um_intervalo_fechado(): void
    {
        $itens = $this->listar(['de' => '2026-07-01', 'ate' => '2026-08-10']);

        $this->assertCount(2, $itens);
        $this->assertTrue($itens->contains('Gasto 2026-07-20'));
        $this->assertTrue($itens->contains('Gasto 2026-08-05'));
    }

    public function test_as_fronteiras_sao_inclusivas(): void
    {
        // Mesmo dia nos dois campos precisa trazer o lançamento daquele dia.
        $itens = $this->listar(['de' => '2026-08-05', 'ate' => '2026-08-05']);

        $this->assertCount(1, $itens);
        $this->assertTrue($itens->contains('Gasto 2026-08-05'));
    }

    public function test_datas_invertidas_sao_trocadas_em_vez_de_devolver_vazio(): void
    {
        // Quem digita 10/08 no "de" e 01/07 no "até" quis julho a agosto. Uma lista
        // em branco não ajudaria a perceber o erro.
        $itens = $this->listar(['de' => '2026-08-10', 'ate' => '2026-07-01']);

        $this->assertCount(2, $itens);
        $this->assertTrue($itens->contains('Gasto 2026-07-20'));
    }

    public function test_data_invalida_e_ignorada_e_a_lista_continua_de_pe(): void
    {
        foreach (['ontem', '2026-13-45', '<script>', '99999999'] as $lixo) {
            $itens = $this->listar(['de' => $lixo]);
            $this->assertCount(4, $itens, "o filtro '{$lixo}' não pode derrubar a lista");
        }
    }

    public function test_periodo_combina_com_os_outros_filtros(): void
    {
        Transaction::factory()->for($this->user)->for($this->conta)->income()->create([
            'amount' => 3000, 'date' => '2026-08-05', 'description' => 'Salário de agosto',
        ]);

        $itens = $this->listar(['de' => '2026-08-01', 'type' => 'income']);

        $this->assertCount(1, $itens);
        $this->assertTrue($itens->contains('Salário de agosto'));
    }

    public function test_sem_filtro_a_lista_vem_inteira(): void
    {
        $this->assertCount(4, $this->listar());
    }

    public function test_a_tela_oferece_os_campos_e_devolve_o_que_foi_filtrado(): void
    {
        $html = $this->actingAs($this->user)
            ->get(route('transactions.index', ['de' => '2026-08-01', 'ate' => '2026-08-10']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="de"', $html);
        $this->assertStringContainsString('name="ate"', $html);
        // Os campos voltam preenchidos — senão o usuário não sabe o que está vendo.
        $this->assertStringContainsString('value="2026-08-01"', $html);
        $this->assertStringContainsString('value="2026-08-10"', $html);
        $this->assertStringContainsString('Limpar', $html);
    }

    public function test_sem_filtro_nao_aparece_o_botao_limpar(): void
    {
        $html = $this->actingAs($this->user)->get(route('transactions.index'))->assertOk()->getContent();

        // Um "limpar" permanente vira ruído numa barra que quase sempre está vazia.
        $this->assertStringNotContainsString('filtro-limpar', $html);
    }

    public function test_a_paginacao_preserva_o_periodo(): void
    {
        $html = $this->actingAs($this->user)
            ->get(route('transactions.index', ['de' => '2026-01-01']))
            ->assertOk()
            ->getContent();

        // `withQueryString()` no paginate: sem isso, ir para a página 2 perdia o filtro.
        $this->assertStringContainsString('name="de"', $html);
        $this->assertSame(4, $this->listar(['de' => '2026-01-01'])->count());
    }
}
