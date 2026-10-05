<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu do perfil (out/2026): Meu perfil · Configurações · Tutorial · Informações do sistema, e o
 * aviso de sucesso como balão flutuante no canto superior direito.
 */
class MenuDoPerfilTutorialEInformacoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_menu_do_perfil_tem_os_quatro_itens_na_ordem(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()->getContent();
        $menu = substr($html, strpos($html, 'id="profilePop"'));

        $this->assertMatchesRegularExpression(
            '#<span>Meu perfil</span>.*<span>Configurações</span>.*<span>Tutorial</span>.*<span>Informações do sistema</span>.*<span>Sair</span>#s',
            $menu,
        );
        $this->assertStringContainsString('href="'.route('tutorial').'"', $menu);
        $this->assertStringContainsString('href="'.route('sistema').'"', $menu);
    }

    public function test_informacoes_do_sistema_mostram_versao_autor_e_documentos(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('sistema'))->assertOk()
            ->assertSee('<title>Informações do sistema', false)
            ->assertSee('Versão 1.0.0')
            ->assertSee('Victor de Melo da Rosa')
            ->assertSee('<span class="sis-autor-av" aria-hidden="true">VR</span>', false)
            ->assertSee('Criador e desenvolvedor do Stabil Money')
            ->assertSee('Sugestões')
            ->assertSee('href="https://victordemelo.com.br"', false)
            ->assertSee('href="https://www.linkedin.com/in/victor-de-melo-da-rosa/"', false)
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('Termos de Uso')
            ->assertSee('Política de Privacidade')
            ->assertSee('Versão '.config('legal.version').', atualizada em '.config('legal.updated_at'))
            ->assertSee('Seu aceite')
            ->assertSee(config('legal.contact_email'))
            // Sem a pilha exata (PHP/Laravel): só ajudaria quem procura falha conhecida.
            ->assertDontSee('Laravel')
            ->assertDontSee('PHP ');
    }

    public function test_tutorial_tem_o_botao_do_tour_e_o_resumo_por_escrito(): void
    {
        $this->actingAs(User::factory()->create())->get(route('tutorial'))->assertOk()
            ->assertSee('data-tutorial-iniciar', false)
            ->assertSee('Começar o tour')
            ->assertSee('Contas a pagar')
            ->assertSee('Contas e cartões');
    }

    public function test_as_paginas_exigem_login(): void
    {
        $this->get(route('sistema'))->assertRedirect(route('login'));
        $this->get(route('tutorial'))->assertRedirect(route('login'));
    }

    public function test_o_aviso_de_sucesso_e_um_balao_no_canto_superior_direito(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->withSession(['status' => 'Perfil atualizado com sucesso.'])
            ->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<span class="sm-balao-txt">Perfil atualizado com sucesso.</span>', $html);

        $css = file_get_contents(resource_path('css/design-system.css'));
        $this->assertMatchesRegularExpression('/\.sm-baloes \{[^}]*position: fixed; z-index: 95; top: calc\(16px[^}]*right: calc\(16px/', $css);
        // Sem JS o próprio .flash já fica fixo no canto.
        $this->assertMatchesRegularExpression('/\.flash:not\(\.sm-balao\) \{ position: fixed;/', $css);
    }
}
