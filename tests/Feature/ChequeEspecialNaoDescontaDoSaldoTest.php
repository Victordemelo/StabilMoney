<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\SidebarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O cheque especial em uso NÃO desconta do saldo total (14/10/2026 — pedido do Victor: "o saldo
 * total não pode descontar o cheque especial"). Itaú com R$ 1.000 e Nubank usando R$ 200 do cheque
 * especial: o total é R$ 1.000, a conta do Nubank aparece em vermelho (−R$ 200), e o aviso "Você
 * está usando R$ 200,00 do cheque especial" fica no painel, na barra lateral e no card da conta.
 */
class ChequeEspecialNaoDescontaDoSaldoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $nubank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Account::factory()->for($this->user)->create(['type' => 'checking', 'name' => 'Itaú', 'bank' => 'itau', 'initial_balance' => 1000]);
        $this->nubank = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Nubank', 'bank' => 'nubank', 'initial_balance' => 0, 'overdraft_limit' => 500,
        ]);
        Transaction::factory()->for($this->user)->expense()->create([
            'account_id' => $this->nubank->id, 'amount' => 200, 'date' => now()->toDateString(),
        ]);
    }

    public function test_o_total_soma_so_o_que_existe_e_o_uso_vem_a_parte(): void
    {
        $this->assertSame(-200.0, $this->nubank->fresh()->available);

        $painel = app(DashboardService::class)->build($this->user->id);
        $this->assertSame(1000.0, $painel['payload']['periods']['mes']['stats']['saldo']);
        $this->assertSame(200.0, $painel['chequeEmUso']);
        // Por conta, o Nubank segue negativo (vermelho no giro do card).
        $nubank = collect($painel['contasDoSaldo'] ?? [])->firstWhere('apelido', 'Nubank');
        $this->assertSame(-200.0, $nubank['valor']);

        $lateral = app(SidebarService::class)->build($this->user->id);
        $this->assertSame(1000.0, $lateral['saldoTotal']);
        $this->assertSame(1000.0, $lateral['disponivel']);
        $this->assertSame(200.0, $lateral['chequeUsado']);
    }

    public function test_as_telas_avisam_em_vermelho(): void
    {
        $painel = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('Você está usando R$ 200,00 do cheque especial', $painel);
        $this->assertStringContainsString('<p class="saldo-cheque" role="note">', $painel);
        $this->assertStringContainsString('<div class="sb-subline neg sb-cheque">Você está usando R$ 200,00 do cheque especial</div>', $painel);

        $contas = $this->actingAs($this->user)->get(route('accounts.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<div class="acct-balance neg">−R$ 200,00', $contas);
        $this->assertSame(1, substr_count($contas, '<p class="acct-cheque" role="note">Usando o cheque especial</p>'));
    }

    public function test_sem_cheque_especial_em_uso_nao_ha_aviso(): void
    {
        Transaction::query()->delete();

        $painel = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('do cheque especial</p>', $painel);
        $this->assertStringNotContainsString('sb-cheque', $painel);
        $this->assertStringContainsString('Cheque especial livre: R$ 500,00', $painel);
    }
}
