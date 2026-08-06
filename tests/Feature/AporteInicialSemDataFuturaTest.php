<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A 5ª porta da data futura (revisão de 06/08/2026).
 *
 * `AporteResgateSemDataFuturaTest` fechou os 4 Form Requests de aporte/resgate de
 * metas e investimentos. Ficou de fora a CRIAÇÃO do investimento, que grava um
 * aporte inicial na MESMA tabela (`investment_contributions`) e continuava aceitando
 * data até +10 anos.
 *
 * Por que importa: `Account::reserved` soma as contribuições sem olhar `date` (igual
 * ao `balance` — decisão D-4 da spec). Um aporte datado em dezembro derrubava o
 * disponível de HOJE, e o limite de gasto passava a recusar despesa que cabe.
 */
class AporteInicialSemDataFuturaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_admin' => true]);

        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'initial_balance' => 5000,
            'overdraft_limit' => 0,
        ]);

        $this->actingAs($this->user);
    }

    private function criar(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('investimentos.store'), array_merge([
            'name' => 'CDB Futuro',
            'classe' => array_key_first(Investment::CLASSES),
            'account_id' => $this->conta->id,
            'valor_inicial' => '4.000,00',
            'date' => now()->toDateString(),
        ], $extra));
    }

    public function test_criar_investimento_recusa_aporte_inicial_no_futuro(): void
    {
        $this->criar(['date' => now()->addMonths(4)->toDateString()])
            ->assertSessionHasErrors('date');

        $this->assertDatabaseCount('investments', 0);
        $this->assertSame(
            5000.00,
            round(Account::find($this->conta->id)->available, 2),
            'Um aporte que ainda não aconteceu não pode reservar dinheiro de hoje.',
        );
    }

    /** Amanhã já é futuro: a fronteira é HOJE, inclusive. */
    public function test_amanha_tambem_e_recusado(): void
    {
        $this->criar(['date' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('date');
    }

    public function test_hoje_e_aceito(): void
    {
        $this->criar()->assertRedirect();

        $this->assertDatabaseCount('investments', 1);
        $this->assertSame(1000.00, round(Account::find($this->conta->id)->available, 2));
    }

    public function test_data_passada_continua_aceita(): void
    {
        $this->criar(['date' => now()->subMonth()->toDateString()])->assertRedirect();

        $this->assertDatabaseCount('investments', 1);
    }

    /** Sem aporte inicial não há contribuição — e a regra não pode atrapalhar. */
    public function test_investimento_sem_valor_inicial_nao_e_afetado(): void
    {
        $this->criar(['valor_inicial' => '0', 'date' => now()->toDateString()])
            ->assertRedirect();

        $this->assertDatabaseCount('investments', 1);
        $this->assertSame(5000.00, round(Account::find($this->conta->id)->available, 2));
    }

    /**
     * A regra existe no servidor E na tela: input sem `max` oferece uma data que o
     * servidor recusa, e o usuário só descobre depois de salvar.
     */
    public function test_os_inputs_de_data_nao_oferecem_o_futuro(): void
    {
        $hoje = now()->toDateString();

        $this->get(route('investimentos.index'))
            ->assertOk()
            ->assertSee('id="inv-c-date" name="date" max="' . $hoje . '"', false)
            ->assertSee('id="inv-aporte-date" name="date" max="' . $hoje . '"', false)
            ->assertSee('id="inv-resgate-date" name="date" max="' . $hoje . '"', false);

        $this->get(route('metas.index'))
            ->assertOk()
            ->assertSee('id="meta-aporte-date" name="date" max="' . $hoje . '"', false)
            ->assertSee('id="meta-resgate-date" name="date" max="' . $hoje . '"', false);
    }
}
