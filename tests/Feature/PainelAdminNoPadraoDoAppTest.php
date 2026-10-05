<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O painel administrativo no padrão do app (out/2026 — pedido do Victor). Antes: a tela de
 * login com o logo torto, cores fora do padrão (variáveis que o design system não define) e o
 * painel que não rolava (`body { overflow: hidden }` sem o `#content` que rola no app).
 *
 * Agora: telas de entrada no split do login do app (`.auth`), e o painel no shell do app —
 * menu lateral, barra de cima com o tema claro/escuro, conteúdo que rola em `#content`.
 */
class PainelAdminNoPadraoDoAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.enabled' => true]);
    }

    public function test_login_e_setup_do_autenticador_usam_o_desenho_do_login_do_app(): void
    {
        $this->get(route('painel.login'))->assertOk()
            ->assertSee('<main class="auth painel-auth ', false)
            ->assertSee('<h1>Entrar no painel</h1>', false)
            ->assertSee('data-toggle="password"', false)
            ->assertSee('Painel administrativo · acesso restrito');

        Admin::factory()->create(['email' => 'chefe@exemplo.com', 'password' => Hash::make('senha-de-teste-bem-comprida')]);
        $this->post(route('painel.autenticar'), ['email' => 'chefe@exemplo.com', 'password' => 'senha-de-teste-bem-comprida']);

        // Formulário largo: o QR ao lado das instruções (cabe sem rolar).
        $this->get(route('painel.2fa.setup'))->assertOk()
            ->assertSee('painel-auth-largo', false)
            ->assertSee('<div class="painel-qr"><svg', false);
    }

    public function test_o_painel_usa_o_shell_do_app_com_menu_lateral_tema_e_conteudo_que_rola(): void
    {
        $admin = Admin::factory()->comDoisFatores()->create(['name' => 'Chefe da Silva']);

        $html = $this->actingAs($admin, 'admin')->withSession(['admin_2fa_ok' => true])
            ->get(route('painel.home'))->assertOk()->getContent();

        $this->assertStringContainsString('<body class="painel">', $html);
        $this->assertStringContainsString('<aside class="sidebar scroll" id="sidebar">', $html);
        $this->assertSame(3, substr_count($html, 'class="nav-item '));
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('id="themeBtn"', $html);
        $this->assertStringContainsString('id="mMenu"', $html);
        $this->assertStringContainsString('<main class="content scroll" id="content">', $html);
        $this->assertStringContainsString('<span class="avatar-initials">CS</span>', $html);
        // A faixa que diz onde se está continua, sempre.
        $this->assertStringContainsString('você está vendo dados de outras pessoas', $html);
    }

    public function test_as_cores_das_telas_do_painel_viram_tokens_do_design_system(): void
    {
        $css = file_get_contents(resource_path('css/design-system.css'));

        // As telas usam --text/--muted/--card em linha; sem estas definições, as cores caíam
        // no padrão do navegador (e não acompanhavam o tema).
        $this->assertMatchesRegularExpression('/\.painel \{ --text: var\(--ink\); --muted: var\(--ink-3\); --card: var\(--surface\);/', $css);
        $this->assertMatchesRegularExpression('/\.painel \.ab \{ width: 40px; height: 40px;/', $css);
    }
}
