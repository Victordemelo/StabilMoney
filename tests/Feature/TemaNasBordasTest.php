<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As "bordas do sistema" no celular: splash da PWA, barra do navegador e barra de status
 * do iPhone. É a moldura em volta do app — e ela não é pintada pelo CSS, e sim por metas
 * no `<head>` e pelo manifest.
 *
 * Por que virou teste: os três defeitos que estas asserções travam eram **invisíveis em
 * desktop** e escancarados no aparelho. O app declarava `background_color: #FFFFFF` no
 * manifest (clarão de tela cheia a cada abertura fria, brutal no tema escuro) e um
 * `theme-color` verde-escuro fixo que nunca acompanhava o tema (faixa escura no topo de
 * uma tela clara). Nada disso quebra teste de rota, aparece em screenshot de CI ou
 * incomoda quem desenvolve no monitor — só o dono do celular vê.
 */
class TemaNasBordasTest extends TestCase
{
    use RefreshDatabase;

    /** A splash da PWA acompanha a marca, não o branco padrão do Laravel. */
    public function test_splash_da_pwa_nao_e_branca(): void
    {
        $manifest = $this->get('/site.webmanifest')->assertOk()->json();

        $this->assertSame('#0C3D2B', $manifest['background_color'],
            'A splash voltou a ser clara — é um clarão de tela cheia a cada abertura no tema escuro.');

        // Mesma cor do fundo dos ícones: o quadrado do ícone se dissolve na splash
        // em vez de aparecer colado sobre um retângulo de outra cor.
        $this->assertSame($manifest['background_color'], $manifest['theme_color']);
    }

    /** Dentro do app, a barra do navegador tem de ter os dois valores para o JS alternar. */
    public function test_a_barra_do_navegador_carrega_a_cor_dos_dois_temas(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('data-sm-theme', escape: false)
            ->assertSee('data-light="#EFF4F1"', escape: false)
            ->assertSee('data-dark="#07140E"', escape: false);

        // Páginas legais são públicas e usam o outro layout — mesma regra.
        $this->get('/termos')
            ->assertOk()
            ->assertSee('data-dark="#07140E"', escape: false);
    }

    /**
     * As telas de auth ficam DE FORA da troca: elas são sempre claras e o topo do
     * celular é ocupado pelo painel do vídeo, que é verde-escuro nos dois temas.
     * Marcar a meta ali faria a barra clarear sobre um painel escuro.
     */
    public function test_telas_de_auth_tem_cor_fixa_e_nao_entram_na_troca(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('content="#0C3D2B"', escape: false)
            ->assertDontSee('data-sm-theme', escape: false);
    }

    /**
     * Barra de status do iPhone. `black-translucent` (o valor antigo) forçava os glifos
     * a branco — invisíveis sobre o fundo claro do tema padrão — e ainda jogava o
     * conteúdo por baixo da barra, o que exigiria `env(safe-area-inset-top)`, que o CSS
     * não usa em lugar nenhum.
     */
    public function test_barra_de_status_do_iphone_nao_e_translucida(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('name="apple-mobile-web-app-status-bar-style" content="default"', escape: false)
            ->assertDontSee('black-translucent', escape: false);
    }

    /** A tela offline é escura nos dois temas, então ali os glifos precisam ser claros. */
    public function test_tela_offline_pede_glifos_claros(): void
    {
        $this->get('/offline')
            ->assertOk()
            ->assertSee('name="apple-mobile-web-app-status-bar-style" content="black"', escape: false);
    }
}
