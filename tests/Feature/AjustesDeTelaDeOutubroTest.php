<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ajustes de tela pedidos pelo Victor em out/2026 que um teste de feature consegue prender.
 */
class AjustesDeTelaDeOutubroTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelar_a_configuracao_do_2fa_fica_ao_lado_do_confirmar_e_continua_funcionando(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.2fa.ativar'), ['password' => 'password'])->assertSessionHasNoErrors();

        $html = $this->actingAs($user)->get(route('settings', '2fa'))->assertOk()->getContent();

        // O botão mora no formulário do código, mas envia o de cancelar (atributo `form`).
        $this->assertMatchesRegularExpression('#<div class="tfa-acoes">\s*<button type="submit" class="btn-primary">Confirmar e ativar</button>\s*.*?form="tfaCancelarForm">Cancelar configuração</button>#s', $html);
        $this->assertStringContainsString('id="tfaCancelarForm"', $html);
        $this->assertStringContainsString('Situações comuns', $html);

        // E cancelar continua cancelando (nada protegido ainda, sem senha).
        $this->actingAs($user)->delete(route('settings.2fa.desativar'))->assertRedirect();
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_o_modal_de_excluir_conta_e_largo_com_os_avisos_lado_a_lado(): void
    {
        $titular = User::factory()->create();
        User::factory()->create(['account_owner_id' => $titular->id]);

        $html = $this->actingAs($titular)->get(route('settings', 'conta'))->assertOk()->getContent();

        $this->assertStringContainsString('class="modal modal-xl" role="dialog"', $html);
        $this->assertMatchesRegularExpression('#<div class="excl-grid">.*?<div class="excl-col">.*?confirmo_dependentes#s', $html);
    }

    public function test_as_paginas_legais_tem_a_barra_com_os_dois_documentos(): void
    {
        $termos = $this->get(route('termos'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('termos'), '#').'"\s+class="ativo"\s+aria-current="page"\s*>Termos de Uso</a>#', $termos);
        $this->assertStringContainsString('href="'.route('privacidade').'"', $termos);

        $privacidade = $this->get(route('privacidade'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#class="ativo"\s+aria-current="page"\s*>Política de Privacidade</a>#', $privacidade);
    }

    public function test_autenticadores_da_familia_sem_foto_mostram_as_iniciais_e_nao_imagem_quebrada(): void
    {
        $titular = User::factory()->create(['name' => 'Victor Rosa', 'avatar_path' => null]);
        User::factory()->create(['account_owner_id' => $titular->id, 'name' => 'Maria Rosa', 'avatar_path' => null]);

        $html = $this->actingAs($titular)->get(route('settings', '2fa'))->assertOk()->getContent();

        $this->assertStringNotContainsString('src=""', $html);
        $this->assertStringContainsString('<span class="tfa-familia-foto iniciais" aria-hidden="true">MR</span>', $html);
        $this->assertStringContainsString('<span class="tfa-familia-foto iniciais" aria-hidden="true">VR</span>', $html);
    }
}
