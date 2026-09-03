<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O card do cartão de débito diz de qual conta o dinheiro sai.
 *
 * O débito saca da CORRENTE (e da poupança só quando não há corrente) — é o que
 * `Account::paymentOptions()` submete no modal de lançar. Antes o card mostrava o TOTAL das
 * duas vinculadas (1.200) como saldo principal, enquanto o select do lançamento avisava o
 * disponível da corrente (700): a tela prometia um número e o lançamento cobrava outro.
 * Agora a conta debitada é marcada e o saldo DELA é o principal; o total vira linha secundária.
 */
class CardDoDebitoMostraContaDebitadaTest extends TestCase
{
    use RefreshDatabase;

    public function test_debito_com_corrente_e_poupanca_destaca_a_corrente(): void
    {
        $user = User::factory()->create();
        $corrente = Account::factory()->for($user)->create(['type' => 'checking', 'name' => 'Nubank CC', 'initial_balance' => 700]);
        $poupanca = Account::factory()->for($user)->create(['type' => 'savings', 'name' => 'Poupança', 'initial_balance' => 500]);
        Account::factory()->for($user)->debitCard($corrente->id)->create(['name' => 'Débito Nubank', 'savings_account_id' => $poupanca->id]);

        $html = $this->actingAs($user)->get(route('accounts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('conta debitada', $html);
        $this->assertStringContainsString('sai de Nubank CC', $html);
        // Saldo principal do card = o da corrente; o total das duas vira secundário.
        $this->assertMatchesRegularExpression('/acct-balance[^>]*>R\$ 700,00 <span class="acct-balance-lbl">sai de Nubank CC/u', $html);
        $this->assertStringContainsString('Total disponível nas vinculadas <b>R$ 1.200,00</b>', $html);
        // O selo fica na corrente, não na poupança.
        $this->assertMatchesRegularExpression('/acct-debitada">Corrente/', $html);
        $this->assertDoesNotMatchRegularExpression('/acct-debitada">Poupança/', $html);
    }

    public function test_debito_so_com_poupanca_debita_a_poupanca(): void
    {
        $user = User::factory()->create();
        $poupanca = Account::factory()->for($user)->create(['type' => 'savings', 'name' => 'Minha Poupança', 'initial_balance' => 500]);
        Account::factory()->for($user)->debitCard()->create(['name' => 'Débito', 'savings_account_id' => $poupanca->id]);

        $html = $this->actingAs($user)->get(route('accounts.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/acct-debitada">Poupança/', $html);
        $this->assertDoesNotMatchRegularExpression('/acct-debitada">Corrente/', $html);
        $this->assertMatchesRegularExpression('/acct-balance[^>]*>R\$ 500,00 <span class="acct-balance-lbl">sai de Minha Poupança/u', $html);
    }

    public function test_pix_continua_mostrando_a_conta_de_origem(): void
    {
        $user = User::factory()->create();
        $corrente = Account::factory()->for($user)->create(['type' => 'checking', 'name' => 'Inter CC', 'initial_balance' => 300]);
        Account::factory()->for($user)->pix($corrente->id)->create(['name' => 'Pix Inter']);

        $html = $this->actingAs($user)->get(route('accounts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Sai de <b>Inter CC</b>', $html);
        $this->assertStringContainsString('disponível para Pix', $html);
        $this->assertStringNotContainsString('conta debitada', $html, 'o selo é do débito; Pix já vive numa conta só');
    }
}
