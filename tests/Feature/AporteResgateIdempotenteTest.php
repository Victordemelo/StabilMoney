<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Aporte, resgate e investimento com valor inicial são idempotentes por
 * `client_uuid` (auditoria de 02/09/2026).
 *
 * Antes, um duplo clique em "Aportar" gravava DOIS aportes: o disponível caía
 * de verdade nas duas vezes, então não criava dinheiro — mas registrava em
 * dobro o que a pessoa fez uma vez só. Convenção do projeto: toda escrita de
 * dinheiro disparada por clique leva `client_uuid`, e o segundo POST igual é
 * respondido como sucesso sem tocar no banco.
 */
class AporteResgateIdempotenteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-02');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 5000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function meta(): Goal
    {
        return Goal::create([
            'user_id' => $this->user->id,
            'name' => 'Viagem',
            'emoji' => '✈️',
            'color' => '#0F6B47',
            'target_amount' => 10000,
            'target_date' => '2027-06-01',
        ]);
    }

    private function investimento(): Investment
    {
        return Investment::create([
            'user_id' => $this->user->id,
            'name' => 'CDB',
            'classe' => 'renda_fixa',
            'indexador' => 'cdi',
            'taxa' => 100,
        ]);
    }

    private function movimento(string $valor, ?string $uuid): array
    {
        return array_filter([
            'account_id' => $this->conta->id,
            'amount' => $valor,
            'date' => '2026-09-02',
            'client_uuid' => $uuid,
        ]);
    }

    public function test_aporte_em_meta_com_o_mesmo_uuid_grava_uma_vez(): void
    {
        $meta = $this->meta();
        $uuid = (string) Str::uuid();

        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), $this->movimento('1.000,00', $uuid))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), $this->movimento('1.000,00', $uuid))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('goal_contributions', 1);
        $this->assertSame(1000.0, $meta->fresh()->saved);
        $this->assertSame(4000.0, $this->conta->fresh()->available);
    }

    public function test_resgate_de_meta_com_o_mesmo_uuid_grava_uma_vez(): void
    {
        $meta = $this->meta();
        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), $this->movimento('1.000,00', (string) Str::uuid()));

        $uuid = (string) Str::uuid();
        $this->actingAs($this->user)->post(route('metas.resgates.store', $meta), $this->movimento('400,00', $uuid))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('metas.resgates.store', $meta), $this->movimento('400,00', $uuid))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('goal_contributions', 2);
        $this->assertSame(600.0, $meta->fresh()->saved);
        $this->assertSame(4400.0, $this->conta->fresh()->available);
    }

    public function test_aporte_em_investimento_com_o_mesmo_uuid_grava_uma_vez(): void
    {
        $inv = $this->investimento();
        $uuid = (string) Str::uuid();

        $this->actingAs($this->user)->post(route('investimentos.aportes.store', $inv), $this->movimento('800,00', $uuid))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('investimentos.aportes.store', $inv), $this->movimento('800,00', $uuid))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('investment_contributions', 1);
        $this->assertSame(800.0, $inv->fresh()->aplicado);
        $this->assertSame(4200.0, $this->conta->fresh()->available);
    }

    public function test_resgate_de_investimento_com_o_mesmo_uuid_grava_uma_vez(): void
    {
        $inv = $this->investimento();
        $this->actingAs($this->user)->post(route('investimentos.aportes.store', $inv), $this->movimento('800,00', (string) Str::uuid()));

        $uuid = (string) Str::uuid();
        $this->actingAs($this->user)->post(route('investimentos.resgates.store', $inv), $this->movimento('300,00', $uuid))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('investimentos.resgates.store', $inv), $this->movimento('300,00', $uuid))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('investment_contributions', 2);
        $this->assertSame(500.0, $inv->fresh()->aplicado);
        $this->assertSame(4500.0, $this->conta->fresh()->available);
    }

    public function test_criar_investimento_com_valor_inicial_e_o_mesmo_uuid_grava_uma_vez(): void
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'name' => 'CDB Novo',
            'classe' => array_key_first(Investment::CLASSES),
            'account_id' => $this->conta->id,
            'valor_inicial' => '2.000,00',
            'date' => '2026-09-02',
            'client_uuid' => $uuid,
        ];

        $this->actingAs($this->user)->post(route('investimentos.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('investimentos.store'), $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('investments', 1);
        $this->assertDatabaseCount('investment_contributions', 1);
        $this->assertSame(3000.0, $this->conta->fresh()->available);
    }

    public function test_sem_uuid_o_comportamento_antigo_permanece(): void
    {
        $meta = $this->meta();

        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), $this->movimento('100,00', null));
        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), $this->movimento('100,00', null));

        $this->assertDatabaseCount('goal_contributions', 2);
        $this->assertSame(200.0, $meta->fresh()->saved);
    }

    public function test_uuids_diferentes_sao_movimentos_diferentes(): void
    {
        $meta = $this->meta();

        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), $this->movimento('100,00', (string) Str::uuid()));
        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), $this->movimento('100,00', (string) Str::uuid()));

        $this->assertDatabaseCount('goal_contributions', 2);
    }

    public function test_uuid_invalido_e_recusado(): void
    {
        $meta = $this->meta();

        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), $this->movimento('100,00', 'nao-e-uuid'))
            ->assertSessionHasErrors('client_uuid');

        $this->assertDatabaseCount('goal_contributions', 0);
    }

    public function test_os_formularios_levam_o_uuid_oculto(): void
    {
        $this->meta();
        $this->investimento();

        $this->actingAs($this->user)->get(route('metas.index'))
            ->assertOk()->assertSee('name="client_uuid"', false);
        $this->actingAs($this->user)->get(route('investimentos.index'))
            ->assertOk()->assertSee('name="client_uuid"', false);
    }
}
