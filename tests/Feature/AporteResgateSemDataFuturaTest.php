<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Aporte e resgate não aceitam data futura.
 *
 * `Account::reserved` soma TODAS as contributions sem olhar `date` — do mesmo
 * jeito que `Account::balance` ignora `date`/`paid_at` (decisão D-4 da spec).
 * Consequência: um aporte datado no mês que vem já derrubava o disponível de
 * HOJE, e um resgate futuro já o levantava. O limite de gasto passava a decidir
 * com um saldo que ainda não era verdade.
 *
 * A saída coerente é barrar a movimentação que ainda não aconteceu: tornar
 * `reserved` sensível à data faria ele discordar do `balance`.
 */
class AporteResgateSemDataFuturaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-02');

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

    // ---------- Aporte ----------

    public function test_aporte_com_data_futura_e_recusado_e_nao_mexe_no_disponivel(): void
    {
        $meta = $this->meta();

        $this->actingAs($this->user)
            ->post(route('metas.aportes.store', $meta), [
                'account_id' => $this->conta->id,
                'amount' => '1.000,00',
                'date' => '2026-09-15',
            ])->assertSessionHasErrors('date');

        // Antes: o aporte entrava e o disponível de HOJE caía para 4.000.
        $this->assertSame(5000.0, $this->conta->fresh()->available);
        $this->assertSame(0.0, $meta->fresh()->saved);
    }

    public function test_aporte_de_hoje_continua_passando(): void
    {
        $meta = $this->meta();

        $this->actingAs($this->user)
            ->post(route('metas.aportes.store', $meta), [
                'account_id' => $this->conta->id,
                'amount' => '1.000,00',
                'date' => '2026-08-02',
            ])->assertSessionHasNoErrors();

        $this->assertSame(4000.0, $this->conta->fresh()->available);
    }

    public function test_aporte_retroativo_continua_passando(): void
    {
        $inv = $this->investimento();

        $this->actingAs($this->user)
            ->post(route('investimentos.aportes.store', $inv), [
                'account_id' => $this->conta->id,
                'amount' => '800,00',
                'date' => '2026-07-10',
            ])->assertSessionHasNoErrors();

        $this->assertSame(800.0, $inv->fresh()->aplicado);
    }

    public function test_aporte_em_investimento_com_data_futura_e_recusado(): void
    {
        $inv = $this->investimento();

        $this->actingAs($this->user)
            ->post(route('investimentos.aportes.store', $inv), [
                'account_id' => $this->conta->id,
                'amount' => '800,00',
                'date' => '2026-08-03',
            ])->assertSessionHasErrors('date');

        $this->assertSame(0.0, $inv->fresh()->aplicado);
        $this->assertSame(5000.0, $this->conta->fresh()->available);
    }

    // ---------- Resgate ----------

    public function test_resgate_com_data_futura_e_recusado(): void
    {
        $inv = $this->investimento();

        $this->actingAs($this->user)->post(route('investimentos.aportes.store', $inv), [
            'account_id' => $this->conta->id,
            'amount' => '1.000,00',
            'date' => '2026-08-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(4000.0, $this->conta->fresh()->available);

        $this->actingAs($this->user)
            ->post(route('investimentos.resgates.store', $inv), [
                'account_id' => $this->conta->id,
                'amount' => '1.000,00',
                'date' => '2026-12-01',
            ])->assertSessionHasErrors('date');

        // Antes: o resgate futuro já devolvia os R$ 1.000 ao disponível de hoje —
        // dinheiro liberado para gastar antes de existir.
        $this->assertSame(4000.0, $this->conta->fresh()->available);
        $this->assertSame(1000.0, $inv->fresh()->aplicado);
    }

    public function test_resgate_de_meta_com_data_futura_e_recusado(): void
    {
        $meta = $this->meta();

        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), [
            'account_id' => $this->conta->id,
            'amount' => '500,00',
            'date' => '2026-08-01',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->user)
            ->post(route('metas.resgates.store', $meta), [
                'account_id' => $this->conta->id,
                'amount' => '500,00',
                'date' => '2026-08-03',
            ])->assertSessionHasErrors('date');

        $this->assertSame(4500.0, $this->conta->fresh()->available);
        $this->assertSame(500.0, $meta->fresh()->saved);
    }

    public function test_resgate_de_hoje_continua_passando(): void
    {
        $meta = $this->meta();

        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), [
            'account_id' => $this->conta->id,
            'amount' => '500,00',
            'date' => '2026-08-01',
        ]);

        $this->actingAs($this->user)
            ->post(route('metas.resgates.store', $meta), [
                'account_id' => $this->conta->id,
                'amount' => '500,00',
                'date' => '2026-08-02',
            ])->assertSessionHasNoErrors();

        $this->assertSame(5000.0, $this->conta->fresh()->available);
    }

    public function test_sem_data_informada_continua_valendo_hoje(): void
    {
        $meta = $this->meta();

        $this->actingAs($this->user)
            ->post(route('metas.aportes.store', $meta), [
                'account_id' => $this->conta->id,
                'amount' => '300,00',
            ])->assertSessionHasNoErrors();

        $this->assertSame(4700.0, $this->conta->fresh()->available);
    }
}
