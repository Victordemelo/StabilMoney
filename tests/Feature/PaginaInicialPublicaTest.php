<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Página inicial pública (out/2026 — pedido do Victor): a raiz do site, para quem não entrou,
 * apresenta o projeto — o que faz, como funciona, a segurança, quem fez e as perguntas
 * frequentes. Quem entrou continua indo para a Visão geral, no mesmo endereço.
 */
class PaginaInicialPublicaTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'https://stabilmoney.victordemelo.com.br';

    public function test_visitante_ve_a_apresentacao_com_as_secoes_e_os_caminhos_para_entrar(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<title>'.config('seo.paginas.dashboard.titulo').'</title>', $html);
        $this->assertStringContainsString('<h1>Seu dinheiro com clareza, controle e crescimento.</h1>', $html);
        foreach (['id="recursos"', 'id="como-funciona"', 'id="familia"', 'id="seguranca"', 'id="quem-fez"', 'id="perguntas"'] as $secao) {
            $this->assertStringContainsString($secao, $html);
        }
        $this->assertStringContainsString('href="'.route('register').'"', $html);
        $this->assertStringContainsString('href="'.route('login').'"', $html);
        $this->assertStringContainsString('href="'.route('termos').'"', $html);
        $this->assertStringContainsString('href="'.route('privacidade').'"', $html);
    }

    public function test_quem_fez_apresenta_o_autor_com_foto_site_linkedin_github_e_contato(): void
    {
        $this->get('/')->assertOk()
            ->assertSee(config('sistema.autor.nome'))
            ->assertSee('Criador e desenvolvedor do Stabil Money')
            ->assertSee('href="'.config('sistema.autor.site').'"', false)
            ->assertSee('href="'.config('sistema.autor.linkedin').'"', false)
            ->assertSee('href="'.config('sistema.autor.github').'"', false)
            ->assertSee('mailto:'.config('legal.contact_email'), false)
            // A foto de verdade (out/2026), não mais as iniciais num círculo.
            ->assertSee('<img class="in-autor-foto" src="'.asset(config('sistema.autor.foto')).'"', false)
            ->assertDontSee('in-autor-av', false);

        $this->assertFileExists(public_path(config('sistema.autor.foto')));
    }

    /**
     * Ajustes de out/2026 (pedido do Victor): o topo sem os botões repetidos ("Criar conta" e
     * "Entrar" já estão no menu e na chamada do fim), sem o card inclinado e sem emoji; e o
     * selo do Stabil Money sobre a marca d'água do vídeo nas telas de entrada (a abertura daqui
     * deixou de ter vídeo na reformulação de out/2026).
     */
    public function test_o_topo_nao_repete_os_botoes_e_o_video_do_login_leva_o_selo(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $hero = substr($html, strpos($html, '<section class="in-hero">'), strpos($html, 'id="recursos"') - strpos($html, '<section class="in-hero">'));

        $this->assertStringNotContainsString('in-hero-acoes', $hero, 'Os botões repetidos voltaram ao topo.');
        $this->assertStringNotContainsString('🛒', $hero);
        $this->assertSame(2, substr_count($html, 'href="'.route('register').'"'), 'Criar conta: no menu e na chamada do fim.');

        $this->get(route('login'))->assertOk()->assertSee('class="selo-do-video"', false);
    }

    /**
     * A reformulação de out/2026 (design_stabilmoney/): as fontes próprias da página, a
     * ilustração das contas no "Como funciona" e, no topo, uma prévia VIVA do app (a
     * ilustração do celular saiu — "tinha cara de IA"). A prévia é desenhada pelo servidor,
     * então vale sem JS, e o `sm/vitrine.js` a anima (tests/js/vitrine.test.js).
     */
    public function test_a_reformulacao_usa_as_fontes_a_previa_viva_e_a_ilustracao_das_contas(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('family=Bricolage+Grotesque', $html);
        $this->assertStringContainsString('family=Geist', $html);
        $this->assertStringContainsString('<meta name="theme-color" content="#032628" />', $html);

        $inicio = strpos($html, '<section class="in-hero">');
        $hero = substr($html, $inicio, strpos($html, 'in-equilibrio') - $inicio);
        $this->assertStringNotContainsString('<video', $hero);
        $this->assertStringNotContainsString('<img', $hero, 'O topo voltou a ter uma imagem no lugar da prévia do app.');
        $this->assertMatchesRegularExpression('/<figure class="in-vitrine" data-vitrine[^>]*role="img" aria-label="Exemplo da Visão geral do app/', $hero);
        foreach (['Saldo disponível', 'Gasto do mês', 'Últimos lançamentos', 'Viagem de férias', '4.218,30', '−R$ 1.450,00'] as $trecho) {
            $this->assertStringContainsString($trecho, $hero);
        }
        $this->assertSame(3, substr_count($hero, '<li class="vt-item'));

        $inicio = strpos($html, 'id="como-funciona"');
        $passos = substr($html, $inicio, strpos($html, 'id="familia"') - $inicio);
        $this->assertStringContainsString(asset('assets/inicio-contas-1672.jpg'), $passos);
        $this->assertStringContainsString(asset('assets/inicio-contas-960.jpg'), $passos);

        foreach (['inicio-contas-1672.jpg', 'inicio-contas-960.jpg'] as $arquivo) {
            $this->assertFileExists(public_path('assets/'.$arquivo));
            $this->assertLessThan(260 * 1024, filesize(public_path('assets/'.$arquivo)), "{$arquivo} pesado demais para a página inicial.");
        }
        $this->assertFileDoesNotExist(public_path('assets/inicio-app-1672.jpg'), 'A ilustração que saiu do topo ficou no site.');

        $css = file_get_contents(resource_path('css/inicio.css'));
        $this->assertStringContainsString('--lima: #9FE870;', $css);
        $this->assertStringContainsString('--mata: #032628;', $css);
    }

    /**
     * O menu do topo fica no meio do espaço entre a marca e as ações, com o mesmo respiro dos
     * dois lados (out/2026 — "estão meio tortos"; centrado na PÁGINA, o vão da esquerda era o
     * dobro do da direita, porque as ações são mais largas que a marca).
     */
    public function test_o_menu_do_topo_fica_centralizado_entre_a_marca_e_as_acoes(): void
    {
        $css = file_get_contents(resource_path('css/inicio.css'));

        $this->assertMatchesRegularExpression('/\.in-menu \{[^}]*margin-inline: auto;/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.in-menu \{[^}]*margin-left: auto/', $css, 'O menu voltou a ser empurrado para a direita.');
    }

    /**
     * A abertura ocupa EXATAMENTE a janela: menor, a faixa seguinte aparecia embaixo (tela de
     * 1080px); maior, a prévia passava do fim num notebook. E a lista da prévia tem altura
     * travada — encolhendo e crescendo na troca, a página inteira descia e subia a cada
     * lançamento (medido num Chromium: altura da página e topo do painel constantes por 8 s).
     */
    public function test_a_abertura_ocupa_a_janela_e_a_previa_nao_mexe_a_pagina(): void
    {
        $css = file_get_contents(resource_path('css/inicio.css'));

        $this->assertMatchesRegularExpression('/\.in-hero-in \{[^}]*min-height: calc\(100svh - 72px\);/', $css);
        $this->assertMatchesRegularExpression('/\.vt-lista \{[^}]*overflow: hidden;/', $css);
        $this->assertDoesNotMatchRegularExpression('/\.vt-item\.vt-sai \{[^}]*max-height/', $css, 'O item que sai não pode encolher a lista.');
        $this->assertStringContainsString('@media (min-width: 981px) and (max-height: 800px)', $css);
    }

    /** A faixa clara logo abaixo do topo, com a ilustração da carteira na balança (out/2026). */
    public function test_a_faixa_do_equilibrio_vem_logo_abaixo_do_topo_com_a_ilustracao(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $faixa = strpos($html, 'class="in-equilibrio"');
        $this->assertNotFalse($faixa);
        $this->assertGreaterThan(strpos($html, '<section class="in-hero">'), $faixa);
        $this->assertLessThan(strpos($html, 'id="recursos"'), $faixa, 'A faixa vem antes dos Recursos.');
        $this->assertStringContainsString('src="'.asset('assets/equilibrio-1600.jpg').'"', $html);
        $this->assertMatchesRegularExpression('/<img class="in-equilibrio-img"[^>]*alt="Ilustração de uma carteira/', $html);

        foreach (['equilibrio-1600.jpg', 'equilibrio-900.jpg'] as $arquivo) {
            $this->assertFileExists(public_path('assets/'.$arquivo));
        }
    }

    public function test_quem_entrou_continua_indo_para_a_visao_geral(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertOk()
            ->assertSee('<title>Visão geral · StabilMoney</title>', false)
            ->assertDontSee('id="quem-fez"', false);
    }

    public function test_as_perguntas_frequentes_viram_dados_estruturados_com_nonce(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<script type="application/ld\+json" nonce="[^"]*">\{"@context":"https://schema.org","@type":"FAQPage"#', $html);
        $this->assertStringContainsString('"name":"É grátis mesmo?"', $html);
        // E o app (WebApplication) pelo partial de SEO, como no login.
        $this->assertStringContainsString('"@type":"WebApplication"', $html);
    }

    public function test_em_producao_a_raiz_e_indexavel_so_para_o_visitante(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => self::APP_URL]);

        $visitante = $this->get(self::APP_URL.'/')->assertOk()->assertHeaderMissing('X-Robots-Tag');
        $this->assertStringContainsString('<link rel="canonical" href="'.self::APP_URL.'/" />', $visitante->getContent());

        // A Visão geral de quem entrou mora no mesmo endereço e nunca vai para os buscadores.
        $this->actingAs(User::factory()->create())->get(self::APP_URL.'/')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_sair_leva_ao_login_e_o_app_instalado_abre_no_login(): void
    {
        $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect(route('login'));

        $this->assertSame('/login', $this->get('/site.webmanifest')->json('start_url'));
        // Quem já entrou e abre o app instalado vai do /login direto para a Visão geral.
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect(route('dashboard'));
    }

    public function test_sessao_expirada_dentro_do_app_vai_ao_login_e_nao_a_apresentacao(): void
    {
        // O pjax do menu ("Visão geral") com a sessão vencida: segue o `auth`, que manda ao
        // login — o nav.js navega para o endereço final do redirecionamento.
        $this->get('/', ['X-Pjax' => '1', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertRedirect(route('login'));
        // E quem pede JSON recebe o 401 de sempre, não o HTML da apresentação.
        $this->getJson('/')->assertUnauthorized();
        // O visitante que digita o endereço continua vendo a apresentação.
        $this->get('/')->assertOk()->assertSee('id="quem-fez"', false);
    }

    public function test_a_marca_do_login_leva_a_pagina_inicial(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('<a class="av-top" href="'.url('/').'" aria-label="Stabil Money — página inicial">', false);
    }

    public function test_a_pagina_rola_e_tem_os_proprios_tokens_claros(): void
    {
        $css = file_get_contents(resource_path('css/inicio.css'));

        $this->assertMatchesRegularExpression('/\.inicio-body \{[^}]*overflow-y: auto;/', $css);
        $this->assertStringContainsString("@import './inicio.css';", file_get_contents(resource_path('css/app.css')));
    }

    public function test_a_pagina_oferece_instalar_o_app_no_topo_e_no_fim(): void
    {
        // O botão nasce escondido e o sm/instalar.js o mostra quando o navegador oferece a
        // instalação (no iPhone, a instrução do Compartilhar). Sem o manifest na página, o
        // navegador nunca ofereceria nada.
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-instalar-app hidden>'));
        $this->assertSame(2, substr_count($html, 'data-instalar-ios hidden>'));
        $this->assertSame(2, substr_count($html, 'data-instalado hidden>'));
        $this->assertStringContainsString('<link rel="manifest"', $html);
        $this->assertStringContainsString('.inicio [hidden] { display: none !important; }', file_get_contents(resource_path('css/inicio.css')));
    }

    public function test_as_perguntas_dizem_que_nao_pedimos_os_dados_do_cartao(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('"name":"Preciso informar os dados do meu cartão?"', $html);
        $this->assertStringContainsString('nunca pede o número do cartão, o código de segurança (CVV), a validade nem a senha', $html);
    }

    public function test_quem_fez_conta_a_historia_do_projeto(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Por muito tempo eu procurei um aplicativo para organizar a minha vida financeira.')
            ->assertSee('O projeto nasceu com o nome MoneyLife')
            ->assertSee('<strong>Stabil Money</strong>, dinheiro estável.', false);
    }

    public function test_o_menu_desliza_ate_a_secao_e_respeita_reduzir_movimento(): void
    {
        $css = file_get_contents(resource_path('css/inicio.css'));
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: no-preference\) \{\s*\.inicio-body \{ scroll-behavior: smooth; \}/', $css);
        $this->assertStringContainsString("import { initRolagemSuave } from './sm/rolagem-suave';", file_get_contents(resource_path('js/app.js')));
    }

    public function test_a_abertura_tem_o_fundo_verde_com_as_ondas_leve(): void
    {
        $css = file_get_contents(resource_path('css/inicio.css'));
        $this->assertStringContainsString("background: var(--mata) url('/assets/inicio-fundo-1920.jpg') right center / cover no-repeat;", $css);
        $this->assertStringContainsString("background-image: url('/assets/inicio-fundo-900.jpg')", $css);

        foreach (['inicio-fundo-1920.jpg', 'inicio-fundo-900.jpg'] as $arquivo) {
            $caminho = public_path('assets/'.$arquivo);
            $this->assertFileExists($caminho);
            $this->assertLessThan(260 * 1024, filesize($caminho), "{$arquivo} pesado demais para a abertura");
        }
    }
}
