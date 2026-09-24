<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `php artisan admin:criar --email=<existente>` troca a senha do admin — e encerra as sessões
 * dele no painel.
 *
 * O defeito: o comando só regravava a senha. As sessões já abertas no painel continuavam
 * valendo (o guard `admin` não confere a senha a cada requisição), então trocar a senha — o
 * remédio que o próprio app indica quando ela pode ter vazado (o alerta do painel e a saída do
 * `admin:zerar-2fa`) — não tirava de dentro quem já estava lá. A troca de senha do cliente
 * (PasswordController) derruba as outras sessões desde a primeira auditoria; o painel ficou
 * sem isso.
 *
 * Criar um admin NOVO não mexe em sessão nenhuma, e a sessão do cliente do app com o mesmo
 * número de id não cai (as sessões do painel são achadas pelo payload, como no zerar-2fa).
 */
class PainelAdminSenhaTrocadaPeloTerminalEncerraAsSessoesTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-bem-comprida';

    private const SENHA_NOVA = 'outra-senha-bem-comprida-2026';

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.enabled' => true, 'session.driver' => 'database']);
        Mail::fake();

        $this->admin = Admin::factory()->comDoisFatores()->create([
            'name' => 'Chefe',
            'email' => 'chefe@exemplo.com',
            'password' => Hash::make(self::SENHA),
        ])->fresh();
    }

    /** Outro navegador: sem guards na memória, sem sessão, sem cookies (ver o teste do zerar-2fa). */
    private function navegadorNovo(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['cookie']->flushQueuedCookies();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];

        return $this;
    }

    private function comASessao(string $id): static
    {
        return $this->navegadorNovo()->withCookie(config('session.cookie'), $id);
    }

    private function sessaoDa(TestResponse $resposta): string
    {
        $cookie = $resposta->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie, 'A resposta não mandou o cookie de sessão.');

        return $cookie->getValue();
    }

    /** Login completo no painel (senha + código). */
    private function entrarNoPainel(): string
    {
        $sessao = $this->sessaoDa($this->navegadorNovo()->post(route('painel.autenticar'), [
            'email' => 'chefe@exemplo.com',
            'password' => self::SENHA,
        ])->assertRedirect(route('painel.home')));

        return $this->sessaoDa($this->comASessao($sessao)->post(route('painel.2fa.verificar'), [
            'codigo' => Totp::codigo($this->admin->fresh()->two_factor_secret, Totp::passoAtual()),
        ])->assertRedirect(route('painel.home')));
    }

    private function trocarASenhaPeloTerminal(string $email = 'chefe@exemplo.com'): void
    {
        $this->artisan('admin:criar', ['--email' => $email, '--nome' => 'Chefe'])
            ->expectsQuestion('Senha (não aparece na tela)', self::SENHA_NOVA)
            ->expectsQuestion('Repita a senha', self::SENHA_NOVA)
            ->assertSuccessful();
    }

    public function test_trocar_a_senha_pelo_terminal_encerra_as_sessoes_abertas_no_painel(): void
    {
        $sessao = $this->entrarNoPainel();

        $this->comASessao($sessao)->get(route('painel.home'))->assertOk();

        $this->trocarASenhaPeloTerminal();

        $this->assertTrue(Hash::check(self::SENHA_NOVA, $this->admin->fresh()->password));

        $this->comASessao($sessao)->get(route('painel.home'))->assertRedirect(route('painel.login'));
    }

    /** A sessão do cliente do app com o MESMO número de id não tem nada com isso. */
    public function test_a_sessao_do_cliente_com_o_mesmo_id_fica(): void
    {
        $cliente = User::factory()->create(['id' => $this->admin->id]);

        $sessaoDoCliente = $this->sessaoDa($this->navegadorNovo()->post('/login', [
            'email' => $cliente->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false)));

        $this->trocarASenhaPeloTerminal();

        $this->comASessao($sessaoDoCliente)->get('/')->assertOk();
    }

    /** Criar um admin novo não derruba ninguém. */
    public function test_criar_um_admin_novo_nao_mexe_nas_sessoes_dos_outros(): void
    {
        $sessao = $this->entrarNoPainel();

        $this->trocarASenhaPeloTerminal('novo@exemplo.com');

        $this->assertNotNull(Admin::where('email', 'novo@exemplo.com')->first());
        $this->comASessao($sessao)->get(route('painel.home'))->assertOk();
    }
}
