<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\GoogleLoginController;
use App\Models\Atividade;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as UsuarioDoGoogle;
use Tests\TestCase;

/**
 * "Entrar com o Google" (out/2026 — GoogleLoginController). O Google substitui só a SENHA:
 * banido não entra, quem tem 2FA passa pelo código, e conta nova só nasce com o aceite dos
 * Termos. A conta é achada pelo identificador do Google ou, sem ele, pelo e-mail que o Google
 * diz estar verificado — nunca por um e-mail não verificado.
 *
 * O Google em si é um dublê (Socialite::shouldReceive): o teste confere o que o APP faz com a
 * resposta, que é onde moram as decisões de segurança.
 */
class EntrarComOGoogleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'id-de-teste', 'services.google.client_secret' => 'segredo-de-teste']);
    }

    private function googleResponde(string $sub, string $email, bool $verificado = true, string $nome = 'Pessoa do Google'): void
    {
        $usuario = (new UsuarioDoGoogle)
            ->setRaw(['sub' => $sub, 'email' => $email, 'email_verified' => $verificado, 'name' => $nome])
            ->map(['id' => $sub, 'name' => $nome, 'email' => $email]);

        Socialite::shouldReceive('driver->user')->andReturn($usuario);
    }

    // ══════════════════════════════════════════════════ ligado / desligado

    public function test_sem_as_chaves_o_botao_some_e_as_rotas_respondem_404(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->get(route('login'))->assertOk()->assertDontSee('Continuar com o Google');
        $this->get(route('register'))->assertOk()->assertDontSee('Continuar com o Google');
        $this->get('/auth/google')->assertNotFound();
        $this->get('/auth/google/callback')->assertNotFound();
        $this->get('/auth/google/criar-conta')->assertNotFound();
    }

    public function test_com_as_chaves_o_botao_aparece_no_login_e_no_cadastro_como_link(): void
    {
        foreach ([route('login'), route('register')] as $tela) {
            $this->get($tela)->assertOk()
                ->assertSee('Continuar com o Google')
                ->assertSee('<a class="google-btn" href="'.route('google.redirect').'">', false);
        }
    }

    public function test_o_botao_leva_ao_google_pedindo_so_nome_e_email(): void
    {
        $resposta = $this->get(route('google.redirect'));

        $resposta->assertRedirect();
        $destino = $resposta->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $destino);
        parse_str((string) parse_url($destino, PHP_URL_QUERY), $q);
        $escopos = explode(' ', $q['scope']);
        sort($escopos);
        $this->assertSame(['email', 'openid', 'profile'], $escopos, 'Só nome e e-mail: nada de foto, contatos ou arquivos.');
        $this->assertSame(route('google.callback'), $q['redirect_uri']);
        $this->assertNotEmpty($q['state'], 'Sem state, o retorno do Google seria forjável.');
    }

    // ══════════════════════════════════════════════════ quem já tem conta

    public function test_conta_ja_ligada_ao_google_entra(): void
    {
        $user = User::factory()->create(['google_id' => 'g-1', 'email' => 'eu@exemplo.test']);
        $this->googleResponde('g-1', 'eu@exemplo.test');

        $this->get(route('google.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('atividades', ['acao' => 'acesso.entrou_google', 'user_id' => $user->id]);
    }

    /**
     * Conta que já existe com o mesmo e-mail NÃO se liga sozinha: pede a senha dela. "E-mail
     * confirmado" nem sempre prova de quem é a conta (login de dependente, conta criada sem envio
     * de e-mail), e alguém pode ter cadastrado o e-mail de outra pessoa esperando a dona chegar
     * pelo Google — o pré-sequestro de conta que a revisão de segurança apontou.
     */
    public function test_conta_existente_pelo_email_pede_a_senha_antes_de_ligar(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'eu@exemplo.test', 'password' => Hash::make('minha-senha')]);
        $this->googleResponde('g-2', 'EU@exemplo.test');

        $this->get(route('google.callback'))->assertRedirect(route('google.ligar'));
        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);

        $this->get(route('google.ligar'))->assertOk()->assertSee('eu@exemplo.test')->assertSee('name="password"', false);

        $this->post(route('google.ligar.confirmar'), ['password' => 'minha-senha'])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $user->refresh();
        $this->assertSame('g-2', $user->google_id);
        $this->assertNotNull($user->email_verified_at, 'O Google provou que a caixa é da pessoa.');
        $this->assertTrue(Hash::check('minha-senha', $user->password), 'Ligar não mexe na senha de quem a sabe.');
        $this->assertDatabaseHas('atividades', ['acao' => 'acesso.entrou_google', 'user_id' => $user->id]);
    }

    /** O pré-sequestro: quem só tem o e-mail (pelo Google) não sabe a senha de quem criou a conta. */
    public function test_sem_a_senha_da_conta_o_google_nao_entra_nela(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'vitima@exemplo.test', 'password' => Hash::make('senha-do-invasor')]);
        $this->googleResponde('g-vitima', 'vitima@exemplo.test');
        $this->get(route('google.callback'));

        $this->post(route('google.ligar.confirmar'), ['password' => 'chute'])->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);
        $this->get(route('google.ligar'))->assertOk()->assertSee(route('password.request'), false);
    }

    public function test_login_de_dependente_tambem_pede_a_senha(): void
    {
        $titular = User::factory()->create();
        $dependente = User::factory()->create(['email' => 'dep@exemplo.test', 'account_owner_id' => $titular->id, 'is_admin' => false, 'password' => Hash::make('senha-dep')]);
        $this->googleResponde('g-dep', 'dep@exemplo.test');

        $this->get(route('google.callback'))->assertRedirect(route('google.ligar'));
        $this->assertNull($dependente->fresh()->google_id);

        $this->post(route('google.ligar.confirmar'), ['password' => 'senha-dep']);
        $this->assertAuthenticatedAs($dependente);
    }

    public function test_ligar_com_2fa_ainda_pede_o_codigo(): void
    {
        User::factory()->create([
            'email' => 'eu@exemplo.test', 'password' => Hash::make('minha-senha'),
            'two_factor_secret' => Totp::gerarSegredo(), 'two_factor_confirmed_at' => now(),
        ]);
        $this->googleResponde('g-6', 'eu@exemplo.test');
        $this->get(route('google.callback'));

        $this->post(route('google.ligar.confirmar'), ['password' => 'minha-senha'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_sem_passar_pelo_google_nao_da_para_ligar(): void
    {
        $this->get(route('google.ligar'))->assertRedirect(route('login'));
        $this->post(route('google.ligar.confirmar'), ['password' => 'x'])->assertRedirect(route('login'))->assertSessionHasErrors('email');
    }

    public function test_email_que_o_google_nao_verificou_nao_entra_em_conta_nenhuma(): void
    {
        $user = User::factory()->create(['email' => 'eu@exemplo.test']);
        $this->googleResponde('g-3', 'eu@exemplo.test', verificado: false);

        $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull($user->fresh()->google_id);
    }

    public function test_email_ja_ligado_a_outra_conta_google_nao_e_trocado(): void
    {
        $user = User::factory()->create(['email' => 'eu@exemplo.test', 'google_id' => 'g-dono']);
        $this->googleResponde('g-outro', 'eu@exemplo.test');

        $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame('g-dono', $user->fresh()->google_id);
    }

    public function test_conta_banida_nao_entra(): void
    {
        User::factory()->create(['email' => 'eu@exemplo.test', 'google_id' => 'g-4', 'banned_at' => now()]);
        $this->googleResponde('g-4', 'eu@exemplo.test');

        $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_quem_ligou_o_2fa_passa_pela_tela_do_codigo(): void
    {
        User::factory()->create([
            'email' => 'eu@exemplo.test', 'google_id' => 'g-5',
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->googleResponde('g-5', 'eu@exemplo.test');

        $this->get(route('google.callback'))->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->get(route('two-factor.login'))->assertOk();
    }

    public function test_cancelar_na_tela_do_google_volta_ao_login_sem_erro(): void
    {
        $this->get(route('google.callback', ['error' => 'access_denied']))
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors();
    }

    public function test_falha_no_retorno_vira_mensagem_e_nao_erro_500(): void
    {
        Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => GoogleLoginController::MENSAGEM_FALHOU]);
        $this->assertGuest();
    }

    // ══════════════════════════════════════════════════ conta nova

    public function test_conta_nova_nao_nasce_no_retorno_pede_o_aceite_dos_termos(): void
    {
        $this->googleResponde('g-novo', 'novo@exemplo.test', nome: 'Pessoa Nova');

        $this->get(route('google.callback'))->assertRedirect(route('google.cadastro'));
        $this->assertDatabaseMissing('users', ['email' => 'novo@exemplo.test']);

        $this->get(route('google.cadastro'))->assertOk()
            ->assertSee('Pessoa Nova')
            ->assertSee('novo@exemplo.test')
            ->assertSee('name="terms"', false);

        $this->post(route('google.criar-conta'))->assertSessionHasErrors('terms');
        $this->assertDatabaseMissing('users', ['email' => 'novo@exemplo.test']);
    }

    public function test_com_o_aceite_a_conta_nasce_titular_verificada_e_ligada_ao_google(): void
    {
        $this->googleResponde('g-novo', 'novo@exemplo.test', nome: 'Pessoa Nova');
        $this->get(route('google.callback'));

        $this->post(route('google.criar-conta'), ['terms' => '1'])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'novo@exemplo.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Pessoa Nova', $user->name);
        $this->assertSame('g-novo', $user->google_id);
        $this->assertTrue($user->isTitular());
        $this->assertTrue((bool) $user->is_admin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(config('legal.version'), $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertGreaterThan(0, $user->categories()->count(), 'As categorias padrão não foram criadas.');
        $this->assertDatabaseHas('atividades', ['acao' => 'app.conta_criada', 'user_id' => $user->id]);
        $this->assertSame(0, Atividade::where('acao', 'acesso.entrou_google')->count(), 'O cadastro já registra a própria linha.');
    }

    public function test_sem_passar_pelo_google_nao_da_para_criar_conta(): void
    {
        $this->get(route('google.cadastro'))->assertRedirect(route('login'));
        $this->post(route('google.criar-conta'), ['terms' => '1'])->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertSame(0, User::count());
    }

    public function test_o_cadastro_pendente_vence_em_10_minutos(): void
    {
        $this->googleResponde('g-novo', 'novo@exemplo.test');
        $this->get(route('google.callback'));

        $this->travel(11)->minutes();

        $this->post(route('google.criar-conta'), ['terms' => '1'])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'novo@exemplo.test']);
    }

    public function test_o_identificador_do_google_nao_sai_no_json_do_usuario(): void
    {
        $user = User::factory()->create(['google_id' => 'g-secreto']);

        $this->assertArrayNotHasKey('google_id', $user->toArray());
    }
}
