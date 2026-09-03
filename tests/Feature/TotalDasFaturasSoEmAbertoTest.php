<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * T-3 (auditoria de 02/09/2026): o "Total das faturas" de /faturas soma SÓ o
 * que está em aberto. Antes somava `currentInvoice` (o gasto do ciclo, pago ou
 * não): depois de pagar o ciclo aberto o card dizia "Fatura paga" e o topo
 * seguia cobrando os mesmos 200.
 *
 * Cartão fecha dia 10 / vence dia 20; hoje = 15/09 → aberto = (10/09, 10/10].
 */
class TotalDasFaturasSoEmAbertoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'initial_balance' => 5000,
        ]);
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create();

        // 700 já fechado (ciclo que fechou em 10/09) + 200 no ciclo aberto.
        Transaction::factory()->for($this->user)->for($this->cartao)->expense()
            ->create(['amount' => 700, 'date' => '2026-09-01']);
        Transaction::factory()->for($this->user)->for($this->cartao)->expense()
            ->create(['amount' => 200, 'date' => '2026-09-15']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function total(): float
    {
        return app(FaturaService::class)->build($this->user->id)['stats']['totalFaturas'];
    }

    public function test_total_soma_o_fechado_e_o_aberto_enquanto_nada_foi_pago(): void
    {
        $this->assertSame(900.0, $this->total());
    }

    public function test_pagar_o_ciclo_aberto_tira_os_200_do_total(): void
    {
        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id,
            'ciclo' => 'aberto',
        ])->assertSessionHasNoErrors();

        // Antes: 900 (os 200 pagos continuavam no total, com "Fatura paga" ao lado).
        $this->assertSame(700.0, $this->total());

        $card = app(FaturaService::class)->build($this->user->id)['cards'][0];
        $this->assertTrue($card['isPaid']);
        $this->assertSame(200.0, $card['currentInvoice'], 'o gasto do ciclo continua sendo 200');
    }

    public function test_pagar_o_fechado_deixa_so_o_aberto(): void
    {
        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id,
            'ciclo' => 'fechado',
        ])->assertSessionHasNoErrors();

        $this->assertSame(200.0, $this->total());
    }

    public function test_a_tela_renderiza_o_total_em_aberto(): void
    {
        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id,
            'ciclo' => 'aberto',
        ]);

        $this->actingAs($this->user)->get(route('faturas.index'))
            ->assertOk()
            ->assertSee('700,00');
    }
}
