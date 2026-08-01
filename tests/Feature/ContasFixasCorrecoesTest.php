<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use App\Services\FixedBillService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correções da auditoria de 28/07/2026 nas CONTAS FIXAS.
 *
 * Cada teste aqui nasceu vermelho reproduzindo um achado real:
 *  C-2  pagar aceitava qualquer valor e furava a trava de gasto;
 *  A-8  o sino nunca avisava antes quando o vencimento cai no mês seguinte;
 *  A-10 a primeira competência nascia "vencida" antes de a conta existir;
 *  M-10 desativar escondia dívida vencida sem avisar;
 *  M-11 conta desativada continuava pagável;
 *  M-15 não havia UI para editar/excluir;
 *  M-6  `paid_on` aceitava data de anos atrás.
 */
class ContasFixasCorrecoesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)
            ->create(['type' => 'checking', 'initial_balance' => 10000, 'overdraft_limit' => 0]);
    }

    private function condominio(array $overrides = []): FixedBill
    {
        return FixedBill::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'name' => 'Condomínio',
            'amount' => 800,
            'due_day' => 10,
            'account_id' => $this->conta->id,
            'starts_on' => '2026-07-01',
            'active' => true,
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // C-2 — valor do pagamento
    // ------------------------------------------------------------------

    /** 1e12 destruía o patrimônio: 302 de sucesso e conta em −R$ 999.999.999.900. */
    public function test_valor_em_notacao_cientifica_e_recusado(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $bill = $this->condominio();

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $this->conta->id,
                'amount' => '1e12',
            ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(10000.0, $this->conta->fresh()->balance);

        $this->travelBack();
    }

    public function test_valor_com_tres_casas_decimais_e_recusado(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $bill = $this->condominio();

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $this->conta->id,
                'amount' => '800.123',
            ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);

        $this->travelBack();
    }

    /** Teto relativo ao previsto: 3× o valor da conta fixa. */
    public function test_valor_muito_acima_do_previsto_e_recusado_mas_ate_o_triplo_passa(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $bill = $this->condominio(); // previsto 800,00 → teto 2.400,00

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $this->conta->id,
                'amount' => '5.000,00',
            ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);

        // No teto (3×) ainda passa: conta de luz de verão realmente dispara.
        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $this->conta->id,
                'amount' => '2.400,00',
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transactions', 1);

        $this->travelBack();
    }

    /**
     * C-2 (c): competência do mês corrente que AINDA NÃO venceu não é obrigação
     * — a trava de gasto volta a valer e o pagamento é recusado.
     */
    public function test_competencia_ainda_nao_vencida_respeita_a_trava_de_gasto(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-05'));
        $pobre = Account::factory()->for($this->user)
            ->create(['type' => 'checking', 'initial_balance' => 50, 'overdraft_limit' => 0]);
        $bill = $this->condominio(['due_day' => 28, 'account_id' => $pobre->id]);

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $pobre->id,
                'amount' => '800,00',
            ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(50.0, $pobre->fresh()->balance);

        $this->travelBack();
    }

    /** Guarda de regressão: JÁ vencida continua sendo obrigação (fica negativa). */
    public function test_competencia_vencida_continua_sendo_obrigacao(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $pobre = Account::factory()->for($this->user)
            ->create(['type' => 'checking', 'initial_balance' => 50, 'overdraft_limit' => 0]);
        $bill = $this->condominio(['account_id' => $pobre->id]);

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $pobre->id,
                'amount' => '800,00',
            ])->assertSessionHasNoErrors();

        $this->assertSame(-750.0, $pobre->fresh()->available);

        $this->travelBack();
    }

    // ------------------------------------------------------------------
    // A-8 — aviso antecipado quando o vencimento cai no mês seguinte
    // ------------------------------------------------------------------

    public function test_sino_avisa_a_competencia_do_mes_seguinte_dentro_da_janela(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-30'));
        $bill = $this->condominio(['due_day' => 5, 'starts_on' => '2026-07-01', 'amount' => 1800]);

        // Julho já foi pago fora da janela do teste — sobra só agosto.
        Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $this->conta->id,
            'type' => 'expense',
            'amount' => 1800,
            'date' => '2026-07-05',
            'paid_at' => '2026-07-05',
            'description' => 'Condomínio — julho',
            'fixed_bill_id' => $bill->id,
            'competence' => '2026-07-01',
        ]);

        $sino = app(FaturaService::class)->upcomingDue($this->user->id, 7);
        $agosto = $sino->first(fn ($i) => $i['tipo'] === 'conta_fixa');

        $this->assertNotNull($agosto, 'O sino deveria avisar o vencimento de 05/08 seis dias antes.');
        $this->assertSame('2026-08-05', $agosto['due']->toDateString());
        $this->assertFalse((bool) $agosto['vencida']);

        $this->travelBack();
    }

    /** E a competência do mês seguinte, uma vez paga, continua visível como paga. */
    public function test_competencia_seguinte_paga_nao_some_da_tela(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-30'));
        $bill = $this->condominio(['due_day' => 5, 'starts_on' => '2026-07-01']);

        Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $this->conta->id,
            'type' => 'expense',
            'amount' => 800,
            'date' => '2026-07-30',
            'paid_at' => '2026-07-30',
            'description' => 'Condomínio — agosto',
            'fixed_bill_id' => $bill->id,
            'competence' => '2026-08-01',
        ]);

        $agosto = app(FixedBillService::class)->currentAndOverdue($this->user->id)
            ->first(fn ($o) => $o['competence']->format('Y-m') === '2026-08');

        $this->assertNotNull($agosto);
        $this->assertTrue((bool) $agosto['paga']);

        $this->travelBack();
    }

    // ------------------------------------------------------------------
    // A-10 — primeira competência anterior ao início da conta
    // ------------------------------------------------------------------

    public function test_competencia_com_vencimento_anterior_ao_inicio_nao_e_gerada(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-27'));
        // Conta cadastrada dia 20/07 que vence todo dia 5: julho NÃO existe.
        $this->condominio(['due_day' => 5, 'starts_on' => '2026-07-20']);

        $ocorrencias = app(FixedBillService::class)->occurrences(
            $this->user->id,
            CarbonImmutable::parse('2026-07-01'),
            CarbonImmutable::parse('2026-08-31'),
        );

        $this->assertCount(1, $ocorrencias);
        $this->assertSame('2026-08-05', $ocorrencias->first()['vencimento']->toDateString());

        // E o sino não pode chamar de "vencida" uma competência que nunca existiu.
        $this->assertCount(0, app(FixedBillService::class)->overdue($this->user->id));

        $this->travelBack();
    }

    public function test_pagar_competencia_anterior_ao_inicio_e_recusado(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-27'));
        $bill = $this->condominio(['due_day' => 5, 'starts_on' => '2026-07-20']);

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $this->conta->id,
                'amount' => '800,00',
            ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);

        $this->travelBack();
    }

    // ------------------------------------------------------------------
    // M-11 / M-10 — conta desativada
    // ------------------------------------------------------------------

    public function test_conta_fixa_desativada_nao_pode_ser_paga(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $bill = $this->condominio(['active' => false]);

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $this->conta->id,
                'amount' => '800,00',
            ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(10000.0, $this->conta->fresh()->balance);

        $this->travelBack();
    }

    public function test_desativar_conta_com_competencia_vencida_avisa_o_usuario(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-15'));
        $bill = $this->condominio(['starts_on' => '2026-06-01']);

        // Um pagamento já feito força o caminho "desativar" (em vez de apagar).
        Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $this->conta->id,
            'type' => 'expense',
            'amount' => 800,
            'date' => '2026-06-10',
            'paid_at' => '2026-06-10',
            'description' => 'Condomínio — junho',
            'fixed_bill_id' => $bill->id,
            'competence' => '2026-06-01',
        ]);

        $this->actingAs($this->user)
            ->delete(route('contas-fixas.destroy', $bill))
            ->assertRedirect(route('faturas.index'));

        $this->assertFalse((bool) $bill->fresh()->active);
        $this->assertStringContainsString('vencida', session('status'));

        $this->travelBack();
    }

    // ------------------------------------------------------------------
    // M-15 — UI de editar/excluir
    // ------------------------------------------------------------------

    public function test_bloco_de_contas_fixas_oferece_editar_e_excluir(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $bill = $this->condominio();

        $this->actingAs($this->user)->get(route('faturas.index'))
            ->assertOk()
            ->assertSee(route('contas-fixas.update', $bill), false)
            ->assertSee(route('contas-fixas.destroy', $bill), false)
            ->assertSee('Editar conta fixa');

        $this->travelBack();
    }

    public function test_editar_conta_fixa_corrige_o_valor_previsto(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $bill = $this->condominio(['amount' => 18000]); // digitou 18.000 no lugar de 1.800

        $this->actingAs($this->user)
            ->patch(route('contas-fixas.update', $bill), [
                'name' => 'Condomínio',
                'amount' => '1.800,00',
                'due_day' => 10,
                'account_id' => $this->conta->id,
                'starts_on' => '2026-07-01',
            ])->assertSessionHasNoErrors()->assertRedirect(route('faturas.index'));

        $this->assertSame('1800.00', (string) $bill->fresh()->amount);

        $this->travelBack();
    }

    // ------------------------------------------------------------------
    // M-6 — piso de `paid_on`
    // ------------------------------------------------------------------

    public function test_paid_on_de_anos_atras_e_recusado(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $bill = $this->condominio();

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $this->conta->id,
                'amount' => '800,00',
                'paid_on' => '2001-03-04',
            ])->assertSessionHasErrors('paid_on');

        $this->assertDatabaseCount('transactions', 0);

        $this->travelBack();
    }

    public function test_paid_on_no_mes_anterior_a_competencia_ainda_e_aceito(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $bill = $this->condominio();

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $this->conta->id,
                'amount' => '800,00',
                'paid_on' => '2026-06-28', // pagou adiantado no fim de junho
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transactions', 1);

        $this->travelBack();
    }

    // ------------------------------------------------------------------
    // 🔵 factory
    // ------------------------------------------------------------------

    public function test_factory_de_conta_fixa_cria_registro_valido(): void
    {
        $bill = FixedBill::factory()->for($this->user)->venceDia(15)->create();

        $this->assertDatabaseCount('fixed_bills', 1);
        $this->assertTrue($bill->active);
        $this->assertSame(15, $bill->due_day);
        $this->assertTrue(FixedBill::factory()->inativa()->create()->active === false);
    }
}
