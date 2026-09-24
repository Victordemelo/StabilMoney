<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * O segredo mostrado na configuração do 2FA do painel pertence à SESSÃO que o gerou.
 *
 * O defeito: a tela de configuração só gerava segredo quando não havia nenhum. Um segredo
 * gerado e não confirmado ficava no banco e era mostrado de novo a QUALQUER sessão que
 * chegasse à tela — o docblock do `setup()` prometia o contrário ("regerar a cada visita").
 * Com isso, o QR visto numa sessão anterior (de quem tinha só a senha) continuava valendo
 * depois que o admin de verdade confirmava a configuração na dele.
 *
 * O comportamento certo: uma sessão que abre a configuração e não foi ela que gerou o segredo
 * pendente recebe um segredo NOVO. Dentro da mesma sessão o segredo se mantém — recarregar a
 * tela ou errar o código não obriga a escanear o QR de novo.
 */
class PainelAdminSegredoDaConfiguracaoEDaSessaoTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-bem-comprida';

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.enabled' => true]);
        Mail::fake();
    }

    /** Admin como o `admin:criar` o deixa: senha, e nada de 2FA. */
    private function adminNovo(): Admin
    {
        return Admin::factory()->create(['email' => 'chefe@exemplo.com', 'password' => Hash::make(self::SENHA)]);
    }

    /** Outro navegador: sem a sessão, o login e os cookies do anterior. */
    private function navegadorNovo(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['cookie']->flushQueuedCookies();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];

        return $this;
    }

    /** Senha certa e a tela de configuração aberta. Devolve o segredo que ficou no banco. */
    private function abrirAConfiguracao(Admin $admin): string
    {
        $this->post(route('painel.autenticar'), ['email' => $admin->email, 'password' => self::SENHA])
            ->assertRedirect(route('painel.home'));

        $this->get(route('painel.2fa.setup'))->assertOk();

        return (string) $admin->fresh()->two_factor_secret;
    }

    private function codigoErrado(string $segredo): string
    {
        $validos = array_map(
            fn (int $delta) => Totp::codigo($segredo, Totp::passoAtual() + $delta),
            range(-Totp::JANELA - 1, Totp::JANELA + 1),
        );

        $n = 0;
        while (in_array(sprintf('%06d', $n), $validos, true)) {
            $n++;
        }

        return sprintf('%06d', $n);
    }

    public function test_o_segredo_pendente_de_outra_sessao_nao_e_reaproveitado(): void
    {
        $admin = $this->adminNovo();

        $segredoDaPrimeiraSessao = $this->abrirAConfiguracao($admin);

        $segredoDaSegundaSessao = $this->navegadorNovo()->abrirAConfiguracao($admin);

        $this->assertNotSame(
            $segredoDaPrimeiraSessao,
            $segredoDaSegundaSessao,
            'A configuração mostrou a esta sessão o segredo gerado em outra.',
        );

        // E a confirmação amarra o autenticador que ESTA sessão mostrou.
        $this->post(route('painel.2fa.confirmar'), ['codigo' => Totp::codigo($segredoDaSegundaSessao, Totp::passoAtual())])
            ->assertRedirect(route('painel.2fa.recuperacao'));

        $admin->refresh();
        $this->assertTrue($admin->temDoisFatores());
        $this->assertSame($segredoDaSegundaSessao, $admin->two_factor_secret);
        $this->assertNotSame($segredoDaPrimeiraSessao, $admin->two_factor_secret);
    }

    /** Recarregar a tela na MESMA sessão não troca o QR que acabou de ser escaneado. */
    public function test_na_mesma_sessao_o_segredo_se_mantem(): void
    {
        $admin = $this->adminNovo();

        $segredo = $this->abrirAConfiguracao($admin);

        $this->get(route('painel.2fa.setup'))->assertOk();
        $this->get(route('painel.2fa.setup'))->assertOk();

        $this->assertSame($segredo, $admin->fresh()->two_factor_secret);
    }

    /** Errar o código volta para a tela — e o QR já escaneado continua valendo. */
    public function test_errar_o_codigo_nao_obriga_a_escanear_de_novo(): void
    {
        $admin = $this->adminNovo();

        $segredo = $this->abrirAConfiguracao($admin);

        $this->from(route('painel.2fa.setup'))
            ->post(route('painel.2fa.confirmar'), ['codigo' => $this->codigoErrado($segredo)])
            ->assertRedirect(route('painel.2fa.setup'));

        $this->get(route('painel.2fa.setup'))->assertOk();
        $this->assertSame($segredo, $admin->fresh()->two_factor_secret);

        $this->post(route('painel.2fa.confirmar'), ['codigo' => Totp::codigo($segredo, Totp::passoAtual())])
            ->assertRedirect(route('painel.2fa.recuperacao'));

        $this->assertTrue($admin->fresh()->temDoisFatores());
    }
}
