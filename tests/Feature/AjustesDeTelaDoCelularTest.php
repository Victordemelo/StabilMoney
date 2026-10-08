<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ajustes pedidos pelo Victor a partir de prints do celular (out/2026).
 */
class AjustesDeTelaDoCelularTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_cadastro_e_esqueci_a_senha_tem_o_voltar_para_o_inicio(): void
    {
        foreach (['login', 'register', 'password.request'] as $rota) {
            $this->get(route($rota))->assertOk()
                ->assertSee('<a class="ac-voltar" href="'.url('/').'">', false)
                ->assertSee('Voltar para o início');
        }
    }

    public function test_o_olho_esta_ao_lado_do_sino_no_celular_e_no_computador(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-ocultar-valores aria-pressed="false" aria-label="Esconder os valores"'));
        // Decidido no <head>, antes da primeira pintura (senão o saldo piscava a cada tela).
        $this->assertStringContainsString("localStorage.getItem('sm-ocultar-valores') === '1'", $html);
        $css = file_get_contents(resource_path('css/design-system.css'));
        $this->assertStringContainsString('html[data-valores-ocultos] .sm-valor { filter: blur(7px);', $css);
        $this->assertMatchesRegularExpression('/html\[data-valores-ocultos\]:not\(\[data-valores-prontos\]\) \.app \{\s*visibility: hidden; animation: sm-valores-prontos 0s \.8s forwards;/', $css);
    }

    public function test_os_segmentos_do_tipo_tem_colunas_iguais(): void
    {
        // Com `1fr` a palavra "Transferência" alargava a coluna dela e a pílula saía torta.
        $this->assertStringContainsString('.type-toggle.tt-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }', file_get_contents(resource_path('css/design-system.css')));
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px;', file_get_contents(resource_path('css/forms.css')));
    }

    public function test_o_codigo_do_2fa_fica_no_centro_do_campo(): void
    {
        // Sem ícone dentro do campo e com a mesma folga dos dois lados (out/2026).
        $this->assertStringContainsString('padding-left: 18px; padding-right: 18px; text-align: center;', file_get_contents(resource_path('css/auth.css')));
        $view = file_get_contents(resource_path('views/auth/two-factor-challenge.blade.php'));
        $this->assertStringNotContainsString('<svg class="lead"', $view);
        // A caixa e a frase juntas, no centro; a explicação embaixo.
        $this->assertStringContainsString('.auth .tfa-confiar { margin: 4px 0 20px; text-align: center; }', file_get_contents(resource_path('css/auth.css')));
        $this->assertStringContainsString('<small id="tfa-confiar-ajuda">', $view);
    }

    public function test_informacoes_do_sistema_tem_a_foto_e_o_github(): void
    {
        $this->actingAs(User::factory()->create())->get(route('sistema'))->assertOk()
            ->assertSee('<img class="sis-autor-av sis-autor-foto" src="'.asset(config('sistema.autor.foto')).'"', false)
            ->assertSee('href="'.config('sistema.autor.github').'"', false)
            ->assertSee('GitHub');
    }

    public function test_o_instalar_no_android_tem_o_caminho_pelo_menu(): void
    {
        $this->get('/')->assertOk()->assertSee('data-instalar-android hidden>No Chrome: toque em', false);
        $this->get(route('login'))->assertOk()->assertSee('data-instalar-android hidden>No Chrome: toque em', false);
    }
}
