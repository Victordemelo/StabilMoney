<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O ícone ao lado dos selects de conta (14/10/2026 — pedido do Victor: "botar o ícone do lado do
 * Pagar com; a mesma coisa na receita e na transferência"). Cada opção leva a URL e a forma do
 * desenho; o `sm/select-icone.js` o mostra dentro do campo (tests/js/select-icone.test.js).
 */
class IconeAoLadoDoSelectDeContaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $corrente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->corrente = Account::factory()->for($this->user)->create(['type' => 'checking', 'name' => 'Corrente', 'bank' => 'itau', 'initial_balance' => 1000]);
        Account::factory()->for($this->user)->create(['type' => 'savings', 'name' => 'Poupança', 'bank' => 'caixa', 'initial_balance' => 500]);
        Account::factory()->for($this->user)->creditCard()->create(['name' => 'Roxinho', 'bank' => 'nubank']);
        Account::factory()->for($this->user)->pix($this->corrente->id)->create(['name' => 'Pix', 'bank' => 'itau']);
    }

    public function test_cada_metodo_traz_o_seu_desenho(): void
    {
        $opcoes = Account::paymentOptions($this->user->id)->keyBy('type');

        $this->assertStringEndsWith('assets/banks/icones/itau.png', $opcoes['checking']->icone);
        $this->assertSame('quadrado', $opcoes['checking']->iconeForma);
        $this->assertStringContainsString('assets/banks/nubank.png?v=', $opcoes['credit_card']->icone);
        $this->assertSame('cartao', $opcoes['credit_card']->iconeForma);
        $this->assertStringEndsWith('assets/banks/icones/pix.png', $opcoes['pix']->icone);
        $this->assertSame('quadrado', $opcoes['pix']->iconeForma);
    }

    public function test_o_lancar_e_a_pagina_cheia_tem_o_icone_no_de_e_no_para(): void
    {
        $painel = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();
        $criar = $this->actingAs($this->user)->get(route('transactions.create'))->assertOk()->getContent();

        foreach ([[$painel, 'lm-account'], [$painel, 'lm-to-account'], [$criar, 'account_id'], [$criar, 'to_account_id']] as [$html, $id]) {
            $this->assertMatchesRegularExpression('#<div class="sel-ico">\s*<select class="input[^"]*" id="'.$id.'"#', $html, "Falta o .sel-ico em #{$id}.");
            $select = substr($html, strpos($html, 'id="'.$id.'"'), 30000);
            $select = substr($select, 0, strpos($select, '</select>'));
            $this->assertMatchesRegularExpression('#data-icone="[^"]*assets/banks/icones/itau\.png" data-icone-forma="quadrado"#', $select, "Sem o ícone em #{$id}.");
        }
    }

    public function test_contas_a_pagar_tem_o_icone_no_pagar_com_e_no_debitar_de(): void
    {
        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        foreach (['cf-conta', 'cfe-conta', 'pay-account'] as $id) {
            $this->assertMatchesRegularExpression('#<div class="sel-ico">\s*<select class="input" id="'.$id.'"#', $html, "Falta o .sel-ico em #{$id}.");
        }

        $css = file_get_contents(resource_path('css/forms.css'));
        $this->assertStringContainsString('.sel-ico.com-icone > select.input { padding-left: 44px; }', $css);
    }
}
