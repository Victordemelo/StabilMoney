<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dinheiro negativo sai como `−R$ 150,00` — traço U+2212 ANTES do símbolo —,
 * nunca `R$ -150,00`.
 *
 * A regra já valia via `App\Support\Brl` / `@brl`, mas dois lugares montavam o
 * valor à mão com `number_format` e escapavam dela: os 4 stat cards do dashboard
 * (que o `dashboard.js` reescreve ao trocar de período) e o card "Patrimônio
 * total" da sidebar (que separa os centavos num `<span>` menor, por design).
 *
 * Nos dois casos a saída foi tirar o sinal de dentro do número e colocá-lo num
 * prefixo próprio, para o símbolo continuar estilizado à parte.
 */
class SinalDeDinheiroNaTelaTest extends TestCase
{
    use RefreshDatabase;

    private const MENOS = "\u{2212}";

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-12');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Conta que termina no vermelho: saldo 100, despesa 250, cheque especial 500. */
    private function contaNoVermelho(): Account
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Corrente',
            'initial_balance' => 100,
            'overdraft_limit' => 500,
        ]);

        Transaction::factory()->for($this->user)->for($conta)->expense()
            ->create(['amount' => 250, 'date' => '2026-08-12']);

        return $conta;
    }

    public function test_patrimonio_negativo_na_sidebar_sai_com_o_sinal_antes_do_simbolo(): void
    {
        $this->contaNoVermelho();

        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        // Antes: `R$ -150,00`.
        $this->assertStringContainsString(self::MENOS . 'R$ 150', $html);
        $this->assertStringNotContainsString('R$ -150', $html);
    }

    public function test_stat_card_negativo_tem_o_sinal_em_span_proprio(): void
    {
        $this->contaNoVermelho();

        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        // O sinal mora fora do .num porque o JS reescreve só o número ao trocar
        // de período — se ele viesse colado, o formato "pulava" no primeiro clique.
        $this->assertMatchesRegularExpression(
            '/<span class="sign">' . self::MENOS . '<\/span><span class="cur">R\$<\/span>/u',
            $html,
        );

        // E o número em si vai sem sinal (valor absoluto).
        $this->assertDoesNotMatchRegularExpression('/<span class="num"[^>]*>-/u', $html);
    }

    public function test_valor_positivo_nao_ganha_sinal_nenhum(): void
    {
        Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 3000,
        ]);

        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<span class="sign"></span>', $html);
        $this->assertStringNotContainsString(self::MENOS . 'R$ 3', $html);
    }

    public function test_saldo_negativo_de_conta_na_lista_ja_usava_brl(): void
    {
        $this->contaNoVermelho();

        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        // A lista de contas passou por @brl na mesma rodada — garante que não
        // regrediu para o number_format cru.
        $this->assertStringNotContainsString('R$ -', $html);
    }
}
