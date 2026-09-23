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
 * Contas fixas mensais (condomínio, aluguel, carro).
 *
 * A regra central: as competências NÃO são materializadas — são projetadas de
 * `starts_on` até hoje. Por isso a conta "nunca some" e uma competência em
 * aberto NÃO derruba o saldo; só o pagamento vira transação.
 */
class FixedBillTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)
            ->create(['type' => 'checking', 'initial_balance' => 10000]);
    }

    private function condominio(array $overrides = []): FixedBill
    {
        return FixedBill::create(array_merge([
            'user_id' => $this->user->id,
            'name' => 'Condomínio',
            'amount' => 800,
            'due_day' => 10,
            'account_id' => $this->conta->id,
            'starts_on' => '2026-05-01',
            'active' => true,
        ], $overrides));
    }

    public function test_conta_fixa_pode_ser_cadastrada(): void
    {
        $this->actingAs($this->user)->post(route('contas-fixas.store'), [
            'name' => 'Aluguel',
            'amount' => '1.800,00',
            'due_day' => 5,
            'account_id' => $this->conta->id,
            'starts_on' => '2026-07-01',
        ])->assertSessionHasNoErrors()->assertRedirect(route('faturas.index'));

        $bill = FixedBill::firstOrFail();
        $this->assertSame('1800.00', (string) $bill->amount);
        $this->assertSame(5, $bill->due_day);
        $this->assertTrue($bill->active);
    }

    public function test_dia_31_e_aceito_e_cai_no_ultimo_dia_do_mes_curto(): void
    {
        // Cartões limitam 1..28; conta fixa aceita 31 porque o clamp é no model.
        $this->actingAs($this->user)->post(route('contas-fixas.store'), [
            'name' => 'Financiamento', 'amount' => '900,00', 'due_day' => 31,
            'starts_on' => '2027-01-01',
        ])->assertSessionHasNoErrors();

        $bill = FixedBill::firstOrFail();
        $this->assertSame(
            '2027-02-28',
            $bill->dueDateFor(CarbonImmutable::parse('2027-02-01'))->toDateString(),
        );
    }

    public function test_competencias_sao_projetadas_sem_materializar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15'));
        $this->condominio(); // começa em maio

        $ocorrencias = app(FixedBillService::class)
            ->occurrences($this->user->id, CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-07-31'));

        // Mai, jun, jul = 3 competências projetadas...
        $this->assertCount(3, $ocorrencias);
        // ...e NENHUMA transação criada: em aberto não derruba o saldo.
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(10000.0, $this->conta->fresh()->balance);

        $this->travelBack();
    }

    public function test_competencia_nao_paga_no_passado_fica_vencida_e_avisa_no_sino(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13')); // 3 dias após o dia 10
        $this->condominio(['starts_on' => '2026-07-01']);

        $ocorrencia = app(FixedBillService::class)->currentAndOverdue($this->user->id)->firstOrFail();

        $this->assertTrue((bool) $ocorrencia['vencida']);
        $this->assertSame(-3, $ocorrencia['diasRestantes']);

        // O sino da topbar recebe o item marcado como vencido.
        $sino = app(FaturaService::class)->upcomingDue($this->user->id, 7);
        $this->assertCount(1, $sino);
        $this->assertSame('conta_fixa', $sino->first()['tipo']);
        $this->assertTrue((bool) $sino->first()['vencida']);

        $this->travelBack();
    }

    public function test_pagar_cria_a_transacao_e_desconta_do_saldo(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $bill = $this->condominio(['starts_on' => '2026-07-01']);

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $this->conta->id,
                'amount' => '812,45', // veio diferente do previsto — vale o real
            ])->assertSessionHasNoErrors();

        $tx = Transaction::firstOrFail();
        $this->assertSame('812.45', (string) $tx->amount);
        $this->assertSame($bill->id, $tx->fixed_bill_id);
        $this->assertSame('2026-07-01', $tx->competence->toDateString());
        $this->assertNotNull($tx->paid_at);
        $this->assertSame(9187.55, $this->conta->fresh()->balance);

        // A competência de julho passa a constar como paga.
        $julho = app(FixedBillService::class)
            ->occurrences($this->user->id, CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'))
            ->firstOrFail();
        $this->assertTrue((bool) $julho['paga']);

        $this->travelBack();
    }

    public function test_pagar_duas_vezes_a_mesma_competencia_e_idempotente(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $bill = $this->condominio(['starts_on' => '2026-07-01']);

        $payload = ['account_id' => $this->conta->id, 'amount' => '800,00'];

        $this->actingAs($this->user)->post(route('contas-fixas.pagar', [$bill, '2026-07']), $payload);
        $this->actingAs($this->user)->post(route('contas-fixas.pagar', [$bill, '2026-07']), $payload);

        // O UNIQUE (fixed_bill_id, competence) impede o duplo pagamento.
        $this->assertDatabaseCount('transactions', 1);

        $this->travelBack();
    }

    /**
     * Conta fixa vencida é OBRIGAÇÃO: sem cheque especial nem investimento, o
     * pagamento passa e a conta fica negativa. Não se recusa um boleto.
     */
    public function test_pagar_sem_fonte_negativa_a_conta_em_vez_de_recusar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $pobre = Account::factory()->for($this->user)
            ->create(['type' => 'checking', 'initial_balance' => 50]);
        $bill = $this->condominio(['starts_on' => '2026-07-01']);

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $pobre->id,
                'amount' => '800,00',
            ])->assertSessionHasNoErrors();

        $this->assertSame(-750.0, $pobre->fresh()->available);

        $this->travelBack();
    }

    /**
     * Mas o app NUNCA usa o cheque especial sozinho: havendo fonte, pergunta
     * antes (409) e a conta continua vencida até o usuário escolher.
     */
    public function test_pagar_com_cheque_especial_disponivel_pergunta_antes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13'));
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'initial_balance' => 50, 'overdraft_limit' => 2000,
        ]);
        $bill = $this->condominio(['starts_on' => '2026-07-01', 'account_id' => $conta->id]);

        $this->actingAs($this->user)
            ->postJson(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $conta->id, 'amount' => '800,00',
            ])->assertStatus(409)->assertJsonPath('precisa_fonte', true);

        // Nada pago enquanto ele não escolhe — a competência segue vencida.
        $this->assertDatabaseCount('transactions', 0);
        $ocorrencia = app(FixedBillService::class)->currentAndOverdue($this->user->id)->firstOrFail();
        $this->assertTrue((bool) $ocorrencia['vencida']);

        // Escolhendo o cheque especial, paga e negativa dentro do limite.
        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $conta->id, 'amount' => '800,00',
                'funding_source' => 'cheque_especial',
            ])->assertSessionHasNoErrors();

        $this->assertSame(-750.0, $conta->fresh()->available);

        $this->travelBack();
    }

    public function test_conta_encerrada_para_de_gerar_competencias(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15'));
        $this->condominio(['starts_on' => '2026-05-01', 'ends_on' => '2026-06-30']);

        $ocorrencias = app(FixedBillService::class)
            ->occurrences($this->user->id, CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-09-30'));

        // Só maio e junho.
        $this->assertCount(2, $ocorrencias);

        $this->travelBack();
    }

    public function test_dependente_da_familia_enxerga_as_contas_fixas(): void
    {
        $dependente = User::factory()->create([
            'account_owner_id' => $this->user->id,
            'is_admin' => false,
        ]);
        $this->condominio();

        $this->actingAs($dependente)->get(route('faturas.index'))
            ->assertOk()
            ->assertSee('Condomínio');
    }

    public function test_conta_fixa_de_outra_familia_nao_pode_ser_paga(): void
    {
        $estranho = User::factory()->create();
        $bill = FixedBill::create([
            'user_id' => $estranho->id, 'name' => 'Aluguel dele',
            'amount' => 100, 'due_day' => 5, 'starts_on' => '2026-07-01', 'active' => true,
        ]);

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-07']), [
                'account_id' => $this->conta->id, 'amount' => '100,00',
            ])->assertNotFound();
    }
}
