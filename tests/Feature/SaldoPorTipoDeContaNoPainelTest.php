<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saldo disponível por tipo de conta na Visão geral (out/2026 — Victor: "se eu tiver conta
 * poupança e conta corrente cadastrada, separa o saldo dos dois; se tiver só uma, mantém como
 * está, mas nomeia ali conta poupança ou corrente").
 *
 * O total do card não muda (é o mesmo disponível de sempre, sem metas e investimentos); as
 * partes somam o total. Cartões, débito, Pix e TED não entram: não são dinheiro próprio.
 */
class SaldoPorTipoDeContaNoPainelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function conta(string $tipo, float $saldo, string $nome = 'Conta'): Account
    {
        return Account::factory()->for($this->user)->create([
            'name' => $nome, 'type' => $tipo, 'bank' => 'nubank', 'initial_balance' => $saldo, 'overdraft_limit' => 0,
        ]);
    }

    private function painel(): HTMLDocument
    {
        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    private function rotuloDoSaldo(HTMLDocument $doc): string
    {
        return trim($doc->querySelector('.dashboard-balance-hero .label')->textContent);
    }

    public function test_com_corrente_e_poupanca_o_card_mostra_as_duas_e_elas_somam_o_total(): void
    {
        $corrente = $this->conta('checking', 1000, 'Nubank');
        $this->conta('savings', 5000, 'Poupança');
        // Dinheiro guardado numa meta sai do disponível da conta que aportou.
        $meta = Goal::factory()->for($this->user)->create(['name' => 'Viagem', 'target_amount' => 900]);
        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), [
            'account_id' => $corrente->id, 'amount' => '200,00', 'date' => CarbonImmutable::today()->toDateString(),
        ])->assertSessionHasNoErrors();
        // Cartão de crédito não é saldo.
        Account::factory()->for($this->user)->creditCard()->create(['credit_limit' => 3000]);

        $doc = $this->painel();
        $hero = $doc->querySelector('.dashboard-balance-hero');
        $this->assertStringContainsString('com-tipos', $hero->getAttribute('class'));
        $this->assertSame('Saldo disponível', $this->rotuloDoSaldo($doc));
        $this->assertSame('5800', $hero->querySelector('.num')->getAttribute('data-count'));

        $this->assertSame('Conta corrente', trim($hero->querySelector('[data-saldo-tipo="checking"] dt')->textContent));
        $this->assertSame('R$ 800,00', trim($hero->querySelector('[data-saldo-tipo="checking"] dd')->textContent));
        $this->assertSame('Conta poupança', trim($hero->querySelector('[data-saldo-tipo="savings"] dt')->textContent));
        $this->assertSame('R$ 5.000,00', trim($hero->querySelector('[data-saldo-tipo="savings"] dd')->textContent));

        // Fora do `.num`: o dashboard.js casa os quatro stat cards por índice.
        $this->assertCount(4, $doc->querySelectorAll('.stat .num'));
    }

    public function test_com_um_tipo_so_o_rotulo_diz_qual_e_e_nao_ha_divisao(): void
    {
        $this->conta('savings', 300);

        $doc = $this->painel();
        $this->assertSame('Saldo disponível · Conta poupança', $this->rotuloDoSaldo($doc));
        $this->assertNull($doc->querySelector('[data-saldo-tipos]'));
        $this->assertStringNotContainsString('com-tipos', $doc->querySelector('.dashboard-balance-hero')->getAttribute('class'));
    }

    public function test_duas_correntes_somam_juntas_no_plural(): void
    {
        $this->conta('checking', 100, 'Nubank');
        $this->conta('checking', 250, 'Itaú');
        $this->conta('savings', 50);

        $doc = $this->painel();
        $this->assertSame('Contas correntes', trim($doc->querySelector('[data-saldo-tipo="checking"] dt')->textContent));
        $this->assertSame('R$ 350,00', trim($doc->querySelector('[data-saldo-tipo="checking"] dd')->textContent));
    }

    public function test_conta_no_vermelho_sai_com_o_sinal_do_app(): void
    {
        $corrente = $this->conta('checking', 0);
        $corrente->update(['overdraft_limit' => 500]);
        $this->conta('savings', 1000);
        \App\Models\Transaction::factory()->for($this->user)->create([
            'account_id' => $corrente->id, 'type' => 'expense', 'amount' => 120, 'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $dd = $this->painel()->querySelector('[data-saldo-tipo="checking"] dd');
        $this->assertSame('−R$ 120,00', trim($dd->textContent));
        $this->assertStringContainsString('neg', $dd->getAttribute('class'));
    }

    public function test_no_celular_as_contas_ficam_abaixo_do_total(): void
    {
        // O card é coluna flex ORDENADA (rótulo 1, valor 2, variação 3): sem `order` as contas
        // (order 0) subiam para cima do rótulo "Saldo disponível" no celular.
        $css = file_get_contents(resource_path('css/design-system.css'));
        $this->assertSame(1, preg_match('/\.dashboard-balance-hero \.saldo-tipos \{([^}]*)\}/', $css, $m));
        $this->assertMatchesRegularExpression('/(^|[;\s])order:\s*2\s*;/', $m[1]);
    }

    public function test_sem_conta_de_banco_o_card_fica_como_antes(): void
    {
        $doc = $this->painel();
        $this->assertSame('Saldo disponível', $this->rotuloDoSaldo($doc));
        $this->assertNull($doc->querySelector('[data-saldo-tipos]'));
    }
}
