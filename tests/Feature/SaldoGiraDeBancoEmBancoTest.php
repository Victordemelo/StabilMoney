<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O card do saldo na Visão geral (out/2026 — pedido do Victor): com UMA conta de banco, o selo
 * do banco fica no lugar da variação ("—"); com mais de uma, o saldo gira de banco em banco e
 * volta ao total (sm/saldo-giro.js). O servidor desenha todos os slides.
 */
class SaldoGiraDeBancoEmBancoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function conta(string $nome, string $banco, float $saldo, string $tipo = 'checking'): Account
    {
        return Account::factory()->for($this->user)->create([
            'name' => $nome, 'type' => $tipo, 'bank' => $banco, 'initial_balance' => $saldo, 'overdraft_limit' => 0,
        ]);
    }

    private function card(): string
    {
        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();
        $inicio = strpos($html, 'dashboard-balance-hero');
        $fim = strpos($html, '<div class="card stat', $inicio + 10) ?: $inicio + 6000;

        return substr($html, $inicio, $fim - $inicio);
    }

    public function test_com_uma_conta_o_selo_do_banco_fica_no_lugar_da_variacao(): void
    {
        $this->conta('Conta do dia a dia', 'itau', 800);
        $card = $this->card();

        $this->assertStringContainsString('<span class="saldo-banco-nome">Banco Itaú <small>Conta corrente</small></span>', $card);
        $this->assertStringContainsString('assets/banks/itau.png', $card);
        $this->assertStringNotContainsString('data-trend="saldo"', $card);
        $this->assertStringNotContainsString('data-saldo-giro', $card);
    }

    public function test_com_varias_contas_o_saldo_gira_com_um_slide_por_conta(): void
    {
        $this->conta('Santander', 'santander', 500);
        $this->conta('Itaú', 'itau', 300);
        $this->conta('Reserva', 'nubank', 200, 'savings');
        $card = $this->card();

        $this->assertStringContainsString('data-saldo-giro', $card);
        // Total primeiro (o `.num` que o dashboard.js anima), depois corrente, poupança e o nome.
        $this->assertMatchesRegularExpression('#<div class="value ativo " data-giro-item="0">.*?data-count="1000"#s', $card);
        $this->assertMatchesRegularExpression('#data-giro-item="1" aria-hidden="true">.*?300,00#s', $card);
        $this->assertMatchesRegularExpression('#data-giro-item="2" aria-hidden="true">.*?500,00#s', $card);
        $this->assertMatchesRegularExpression('#data-giro-item="3" aria-hidden="true">.*?200,00#s', $card);
        $this->assertSame(1, substr_count($card, 'class="num"'), 'só o total entra no casamento por índice do dashboard.js');
        $this->assertStringContainsString('Todas as contas <small>3</small>', $card);
        $this->assertStringContainsString('Banco Santander <small>Conta corrente</small>', $card);
        $this->assertStringContainsString('Nubank <small>Conta poupança</small>', $card);
        $this->assertStringContainsString('data-giro-selos role="button" tabindex="0"', $card);
    }

    public function test_cartoes_e_metodos_espelho_nao_entram_no_giro(): void
    {
        $corrente = $this->conta('Inter', 'inter', 400);
        Account::factory()->for($this->user)->creditCard()->create(['name' => 'Crédito Inter', 'bank' => 'inter']);
        Account::factory()->for($this->user)->pix($corrente->id)->create(['name' => 'Pix', 'bank' => 'inter']);

        $card = $this->card();
        $this->assertStringNotContainsString('data-saldo-giro', $card);
        $this->assertStringContainsString('Banco Inter <small>Conta corrente</small>', $card);
    }

    public function test_sem_conta_de_banco_fica_a_variacao_de_sempre(): void
    {
        $this->assertStringContainsString('data-trend="saldo"', $this->card());
    }
}
