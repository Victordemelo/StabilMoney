<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A identidade de out/2026 (design_stabilmoney/) no app inteiro: verde-petróleo (mata),
 * um destaque lima, Bricolage Grotesque nos títulos e Geist no texto.
 *
 * Ela mora numa folha só, `resources/css/identidade.css`, que precisa ser a ÚLTIMA do
 * app.css: ela redefine os tokens do design-system.css e do escopo .auth — carregada antes,
 * perde o empate e a tela volta ao visual antigo sem erro nenhum. E cada layout carrega as
 * fontes novas: sem o link, o CSS cai na fonte de reserva (Sora/Plus Jakarta, que não são
 * mais baixadas) e depois na do sistema.
 */
class IdentidadeVisualNoAppTest extends TestCase
{
    use RefreshDatabase;

    private const FONTES = 'family=Bricolage+Grotesque:opsz,wght@12..96,600..800&family=Geist:wght@400..600';

    public function test_a_folha_da_identidade_e_a_ultima_do_app_css(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        preg_match_all("/@import '\\.\\/([a-z-]+)\\.css';/", $css, $imports);

        $this->assertSame('identidade', end($imports[1]), 'A identidade.css tem de vir por último: ela redefine os tokens.');
    }

    public function test_os_tokens_da_identidade_estao_na_folha(): void
    {
        $css = file_get_contents(resource_path('css/identidade.css'));

        foreach (['--lima: #9FE870;', '--mata: #032628;', '--brand-300: #9FE870;', '--brand-900: #032628;',
            '--font-head: "Bricolage Grotesque"', '--font-body: "Geist"'] as $token) {
            $this->assertStringContainsString($token, $css);
        }
        // No escuro, os tons de TEXTO da marca viram lima (o verde-escuro sumia sobre o mata).
        $this->assertMatchesRegularExpression('/\[data-theme="dark"\] \{[^}]*--brand-600: #9FE870;/', $css);
    }

    public function test_os_layouts_carregam_as_fontes_novas_e_nao_as_antigas(): void
    {
        foreach (['app', 'auth', 'legal', 'admin', 'admin-auth'] as $layout) {
            $html = file_get_contents(resource_path("views/layouts/{$layout}.blade.php"));

            $this->assertStringContainsString(self::FONTES, $html, "O layout {$layout} não carrega Bricolage + Geist.");
            $this->assertStringNotContainsString('family=Sora', $html, "O layout {$layout} ainda baixa a Sora.");
        }
    }

    public function test_telas_servidas_trazem_as_fontes_novas(): void
    {
        $this->get(route('login'))->assertOk()->assertSee(self::FONTES, false);
        $this->get(route('termos'))->assertOk()->assertSee(self::FONTES, false);
        $this->actingAs(User::factory()->create())->get(route('transactions.index'))
            ->assertOk()
            ->assertSee(self::FONTES, false);
    }
}
