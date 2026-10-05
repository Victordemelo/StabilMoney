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
        $this->assertContains('Débito Nubank → Conta Corrente', $opcoes->pluck('rotulo')->all());

        $this->actingAs($u)->get(route('dashboard'))->assertOk()
            ->assertSee('Nubank Roxinho (Crédito)')
            ->assertSee('Conta Corrente (Nubank)')
            ->assertSee('Débito Nubank → Conta Corrente');
    }
}
