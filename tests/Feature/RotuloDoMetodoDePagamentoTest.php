<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os selects de pagamento mostram o APELIDO + tipo e banco (out/2026): "Nubank Roxinho"
 * sozinho não dizia se era crédito ou débito. O que o apelido já diz não se repete.
 */
class RotuloDoMetodoDePagamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_rotulo_completa_so_o_que_o_apelido_nao_diz(): void
    {
        $u = User::factory()->create();
        $corrente = Account::factory()->for($u)->create(['type' => 'checking', 'name' => 'Conta Corrente', 'bank' => 'nubank', 'initial_balance' => 0]);

        $this->assertSame('Conta Corrente (Nubank)', $corrente->rotulo);
        $this->assertSame('Nubank Roxinho (Crédito)', Account::factory()->for($u)->creditCard()->create(['name' => 'Nubank Roxinho', 'bank' => 'nubank'])->rotulo);
        $this->assertSame('Poupança (Caixa)', Account::factory()->for($u)->create(['type' => 'savings', 'name' => 'Poupança', 'bank' => 'caixa', 'initial_balance' => 0])->rotulo);
        $this->assertSame('Reserva (Poupança · Itaú)', Account::factory()->for($u)->create(['type' => 'savings', 'name' => 'Reserva', 'bank' => 'itau', 'initial_balance' => 0])->rotulo);
        $this->assertSame('Débito Nubank', Account::factory()->for($u)->debitCard($corrente->id)->create(['name' => 'Débito Nubank', 'bank' => 'nubank'])->rotulo);
        // Sem acento no apelido também conta como "já diz".
        $this->assertSame('Cartao de credito do Lucas (Inter)', Account::factory()->for($u)->creditCard()->create(['name' => 'Cartao de credito do Lucas', 'bank' => 'inter'])->rotulo);
    }

    public function test_o_modal_de_lancar_usa_o_rotulo_e_o_espelho_diz_de_onde_sai(): void
    {
        $u = User::factory()->create();
        $corrente = Account::factory()->for($u)->create(['type' => 'checking', 'name' => 'Conta Corrente', 'bank' => 'nubank', 'initial_balance' => 100]);
        Account::factory()->for($u)->creditCard()->create(['name' => 'Nubank Roxinho', 'bank' => 'nubank']);
        Account::factory()->for($u)->debitCard($corrente->id)->create(['name' => 'Débito Nubank', 'bank' => 'nubank']);

        $opcoes = Account::paymentOptions($u->id);
        // Uma conta só no Nubank: o débito já diz de onde sai, sem " → Conta Corrente" (13/10/2026).
        $this->assertContains('Débito Nubank', $opcoes->pluck('rotulo')->all());

        // No select o tipo vai no TÍTULO do grupo (out/2026): a opção fica só com o nome curto,
        // sem quebrar em duas linhas na lista do celular.
        $html = $this->actingAs($u)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('<optgroup label="Cartões de crédito" data-para="expense">', $html);
        $this->assertMatchesRegularExpression('#>Nubank Roxinho</option>#', $html);
        $this->assertMatchesRegularExpression('#>Conta Corrente \(Nubank\)</option>#', $html);
        $this->assertMatchesRegularExpression('#>Débito Nubank</option>#', $html);
        $this->assertStringNotContainsString('Nubank Roxinho (Crédito)</option>', $html);
    }

    public function test_o_rotulo_curto_tira_o_tipo_e_mantem_o_banco_quando_falta(): void
    {
        $u = User::factory()->create();

        $this->assertSame('Mercado Pago', Account::factory()->for($u)->create(['type' => 'checking', 'name' => 'Mercado Pago', 'bank' => 'mercado_pago', 'initial_balance' => 0])->rotuloCurto);
        $this->assertSame('Reserva (Itaú)', Account::factory()->for($u)->create(['type' => 'savings', 'name' => 'Reserva', 'bank' => 'itau', 'initial_balance' => 0])->rotuloCurto);
        $this->assertSame('Cartão crédito Inter', Account::factory()->for($u)->creditCard()->create(['name' => 'Cartão crédito Inter', 'bank' => 'inter'])->rotuloCurto);
    }

    /**
     * O " → conta" do débito/Pix só aparece quando ajuda (13/10/2026 — pedido do Victor: "Pix Itaú
     * → Itaú" repetia o banco). Com corrente E poupança no mesmo banco, diz qual delas.
     */
    public function test_o_espelho_so_diz_a_conta_quando_ha_duvida(): void
    {
        $u = User::factory()->create();
        $corrente = Account::factory()->for($u)->create(['type' => 'checking', 'name' => 'Itaú', 'bank' => 'itau', 'initial_balance' => 0]);
        Account::factory()->for($u)->create(['type' => 'pix', 'name' => 'Pix Itaú', 'bank' => 'itau', 'checking_account_id' => $corrente->id, 'initial_balance' => null]);

        $this->assertSame(['Pix Itaú'], Account::paymentOptions($u->id)->where('type', 'pix')->pluck('rotuloCurto')->values()->all());

        // Agora com uma poupança no Itaú também: o Pix precisa dizer de qual conta sai.
        $poupanca = Account::factory()->for($u)->create(['type' => 'savings', 'name' => 'Itaú', 'bank' => 'itau', 'initial_balance' => 0]);
        Account::factory()->for($u)->create(['type' => 'pix', 'name' => 'Pix da reserva', 'bank' => 'itau', 'savings_account_id' => $poupanca->id, 'initial_balance' => null]);

        $rotulos = Account::paymentOptions($u->id)->where('type', 'pix')->pluck('rotuloCurto')->sort()->values()->all();
        $this->assertSame(['Pix Itaú → Conta corrente', 'Pix da reserva (Itaú) → Poupança'], $rotulos);
    }
}
