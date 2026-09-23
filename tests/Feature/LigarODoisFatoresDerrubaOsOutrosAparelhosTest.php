<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ligar o 2FA desconecta os OUTROS aparelhos da conta (decisão de 23/09/2026).
 *
 * O defeito: confirmar a configuração só gravava o 2FA. Quem liga a proteção muitas vezes
 * está reagindo a uma suspeita — e a sessão que um invasor já tinha aberta seguia valendo,
 * porque o código só é cobrado no LOGIN e quem já está dentro não passa por ele. O cookie de
 * "lembrar de mim" também: ele re-autentica sem login nenhum, e o guard confere só id + token.
 *
 * O comportamento certo, igual ao de "Encerrar outras sessões":
 *  - as linhas de `sessions` dos outros aparelhos saem;
 *  - o `remember_token` muda, e os cookies de "lembrar de mim" emitidos antes param de entrar;
 *  - ESTE aparelho continua dentro — e, se era lembrado, com um cookie novo, que carrega o
 *    token novo. Lá o `logoutOtherDevices($senha)` reemite o cookie; aqui não há senha (só o
 *    código), e quem o reemite é um `login()` do próprio aparelho;
 *  - código errado não derruba ninguém.
 *
 * Os aparelhos entram de verdade (POST /login), com o driver `database` (o de produção), e
 * cada um volta só com os cookies que o app lhe deu — como um navegador.
 */
class LigarODoisFatoresDerrubaOsOutrosAparelhosTest extends TestCase
{
    use RefreshDatabase;

    /** A senha da UserFactory. */
    private const SENHA = 'password';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        Mail::fake();

        $this->user = User::factory()->create();
    }

    // ══════════════════════════════════════════════════ os outros aparelhos

    public function test_a_sessao_de_outro_aparelho_cai(): void
    {
        $outraPessoa = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'sessao-de-outra-pessoa', 'user_id' => $outraPessoa->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
            ['id' => 'visitante-sem-login', 'user_id' => null, 'ip_address' => '203.0.113.9', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
        ]);

        $outroAparelho = $this->entrar(lembrar: false);
        $esteAparelho = $this->entrar(lembrar: false);

        // Controle: antes de ligar, o outro aparelho entra com a sessão que tem.
        $this->voltar($outroAparelho)->get(route('dashboard'))->assertOk();

        $this->ligarODoisFatores($esteAparelho)->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => $outroAparelho['sessao']]);
        $this->voltar($outroAparelho)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Só as desta conta: ninguém mais é desconectado.
        $this->assertDatabaseHas('sessions', ['id' => 'sessao-de-outra-pessoa']);
        $this->assertDatabaseHas('sessions', ['id' => 'visitante-sem-login']);
    }

    public function test_o_lembrar_de_mim_de_outro_aparelho_nao_entra_mais(): void
    {
        $outroAparelho = $this->entrar(lembrar: true);
        $esteAparelho = $this->entrar(lembrar: false);

        // Controle: sem sessão nenhuma, o cookie sozinho abre a conta. É por aqui que o
        // aparelho do invasor voltaria depois de perder a sessão.
        $this->soComOCookie($outroAparelho['lembrar'])->assertOk();
        $this->assertAuthenticatedAs($this->user);

        $this->ligarODoisFatores($esteAparelho)->assertSessionHasNoErrors();

        $this->soComOCookie($outroAparelho['lembrar'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ══════════════════════════════════════════════════ este aparelho

    public function test_este_aparelho_continua_conectado_sem_lembrar_de_mim(): void
    {
        $esteAparelho = $this->entrar(lembrar: false);

        $resposta = $this->ligarODoisFatores($esteAparelho)->assertSessionHasNoErrors();
        $depois = $this->guardarCookies($esteAparelho, $resposta);

        // Não era lembrado, e continua não sendo: ligar o 2FA não inventa um cookie longo.
        $this->assertNull($resposta->getCookie($this->nomeDoLembrar()));

        $this->voltar($depois)->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($this->user);
        // E o 2FA não é cobrado de quem já estava dentro: é este aparelho que o ligou.
        $this->voltar($depois)->get('/configuracoes/2fa')->assertOk()->assertSee('Ativada');
    }

    /**
     * A sessão deste aparelho sai com um id novo, e o antigo morre. Uma cópia do cookie de
     * sessão DESTE aparelho (o jeito mais comum de roubar uma conta sem a senha) também para
     * de valer — senão "os outros aparelhos saem" pouparia justamente o invasor que clonou
     * este.
     */
    public function test_a_sessao_deste_aparelho_ganha_id_novo_e_o_antigo_nao_entra(): void
    {
        $esteAparelho = $this->entrar(lembrar: false);

        $depois = $this->guardarCookies($esteAparelho, $this->ligarODoisFatores($esteAparelho));

        $this->assertNotSame($esteAparelho['sessao'], $depois['sessao']);
        $this->assertDatabaseMissing('sessions', ['id' => $esteAparelho['sessao']]);
        $this->voltar(['sessao' => $esteAparelho['sessao']])->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_este_aparelho_continua_lembrado_com_um_cookie_novo(): void
    {
        $esteAparelho = $this->entrar(lembrar: true);
        $tokenAntigo = $this->user->fresh()->getRememberToken();

        $resposta = $this->ligarODoisFatores($esteAparelho)->assertSessionHasNoErrors();

        $cookieNovo = $resposta->getCookie($this->nomeDoLembrar());
        $this->assertNotNull($cookieNovo, 'Quem ligou o 2FA perdeu o "lembrar de mim" deste aparelho.');

        [$id, $token] = explode('|', $cookieNovo->getValue());
        $this->assertSame((string) $this->user->id, $id);
        $this->assertNotSame($tokenAntigo, $token, 'O cookie novo saiu com o token antigo.');
        $this->assertSame($this->user->fresh()->getRememberToken(), $token);

        // Vale de fato: sem sessão, só com ele, o dono entra (o "lembrar de mim" pula o desafio,
        // como em qualquer aparelho confiável).
        $this->soComOCookie($cookieNovo->getValue())->assertOk();
        $this->assertAuthenticatedAs($this->user);

        // O que este aparelho tinha antes, não — é igual ao que os outros aparelhos guardam.
        $this->soComOCookie($esteAparelho['lembrar'])->assertRedirect(route('login'));
    }

    // ══════════════════════════════════════════════════ o que não derruba nada

    /** Código errado: nada ligou, e nada cai — nem sessão, nem "lembrar de mim". */
    public function test_codigo_errado_nao_derruba_ninguem(): void
    {
        $outroAparelho = $this->entrar(lembrar: true);
        $esteAparelho = $this->entrar(lembrar: false);
        $token = $this->user->fresh()->getRememberToken();

        $this->ligarODoisFatores($esteAparelho, codigoCerto: false)
            ->assertSessionHasErrors('codigo', errorBag: 'twoFactor');

        $this->assertFalse($this->user->fresh()->temDoisFatores());
        $this->assertSame($token, $this->user->fresh()->getRememberToken());
        $this->assertDatabaseHas('sessions', ['id' => $outroAparelho['sessao']]);

        $this->voltar(['sessao' => $outroAparelho['sessao']])->get(route('dashboard'))->assertOk();
        $this->soComOCookie($outroAparelho['lembrar'])->assertOk();
    }

    // ══════════════════════════════════════════════════ o que a pessoa lê

    public function test_o_aviso_por_email_conta_que_os_outros_aparelhos_sairam(): void
    {
        $this->ligarODoisFatores($this->entrar(lembrar: false))->assertSessionHasNoErrors();

        Mail::assertSent(AlertaDeSeguranca::class, function (AlertaDeSeguranca $mail) {
            $html = $mail->render();

            return $mail->hasTo($this->user->email)
                && $mail->titulo === 'Verificação em duas etapas ativada'
                && str_contains($html, 'os outros aparelhos em que a sua conta estava aberta foram <strong>desconectados</strong>')
                // E, se não foi a pessoa, o que fazer: o "Esqueci a senha" não basta.
                && str_contains($html, 'não tira esse celular da conta')
                && str_contains($html, (string) config('legal.contact_email'));
        });
    }

    public function test_a_tela_avisa_que_os_outros_aparelhos_sairam(): void
    {
        $this->actingAs($this->user)->post(route('settings.2fa.ativar'), ['password' => self::SENHA]);

        $resposta = $this->actingAs($this->user->fresh())
            ->followingRedirects()
            ->post(route('settings.2fa.confirmar'), [
                'codigo' => Totp::codigo($this->user->fresh()->two_factor_secret, Totp::passoAtual()),
            ])
            ->assertOk()
            ->assertSee('Verificação em duas etapas ativada. Se a sua conta estava aberta em outros aparelhos,')
            ->assertSee('eles foram desconectados');

        // Os códigos de recuperação continuam aparecendo junto — a sessão nova levou o flash.
        foreach ($this->user->fresh()->two_factor_recovery_codes as $codigo) {
            $resposta->assertSee($codigo);
        }
    }

    // ══════════════════════════════════════════════════ aparelhos

    /** Nome do cookie de "lembrar de mim" do guard `web`. */
    private function nomeDoLembrar(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    /**
     * Passa a agir de OUTRO aparelho: sem usuário na memória dos guards, sem os atributos da
     * sessão anterior e sem cookie. Entre requisições o app de teste não é recriado, então
     * três coisas que em produção nascem zeradas a cada requisição precisam ser zeradas à
     * mão: o `auth.driver` (o guard que o `DatabaseSessionHandler` consulta para preencher
     * `user_id`, um singleton que o `forgetGuards` não alcança) e a fila do `CookieJar` —
     * sem esvaziá-la, o cookie de "lembrar de mim" de um login anterior sairia de novo na
     * resposta de outro aparelho.
     */
    private function aparelhoNovo(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['cookie']->flushQueuedCookies();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];

        return $this;
    }

    /**
     * Login de verdade, com ou sem "lembrar de mim".
     *
     * @return array{sessao: string, lembrar?: string} os cookies que o aparelho guardou
     */
    private function entrar(bool $lembrar): array
    {
        $resposta = $this->aparelhoNovo()
            ->post('/login', array_filter([
                'email' => $this->user->email,
                'password' => self::SENHA,
                'remember' => $lembrar ? 'on' : null,
            ]))
            ->assertRedirect(route('dashboard', absolute: false));

        $aparelho = $this->guardarCookies([], $resposta);
        $this->assertSame($lembrar, isset($aparelho['lembrar']), 'O login não emitiu (ou emitiu à toa) o "lembrar de mim".');

        return $aparelho;
    }

    /**
     * O que o navegador guarda depois de uma resposta: o que ela mandou substitui o que ele
     * tinha.
     *
     * @param  array{sessao?: string, lembrar?: string}  $aparelho
     * @return array{sessao?: string, lembrar?: string}
     */
    private function guardarCookies(array $aparelho, TestResponse $resposta): array
    {
        if ($sessao = $resposta->getCookie(config('session.cookie'))) {
            $aparelho['sessao'] = $sessao->getValue();
        }

        if ($lembrar = $resposta->getCookie($this->nomeDoLembrar())) {
            $aparelho['lembrar'] = $lembrar->getValue();
        }

        return $aparelho;
    }

    /** O aparelho volta com os cookies que guarda. */
    private function voltar(array $aparelho): static
    {
        $this->aparelhoNovo();

        if (isset($aparelho['sessao'])) {
            $this->withCookie(config('session.cookie'), $aparelho['sessao']);
        }

        if (isset($aparelho['lembrar'])) {
            $this->withCookie($this->nomeDoLembrar(), $aparelho['lembrar']);
        }

        return $this;
    }

    /** Um aparelho SEM sessão, só com o cookie de "lembrar de mim", tenta abrir o app. */
    private function soComOCookie(string $cookie): TestResponse
    {
        return $this->aparelhoNovo()
            ->withCookie($this->nomeDoLembrar(), $cookie)
            ->get(route('dashboard'));
    }

    /**
     * Do aparelho, com os cookies dele: senha para gerar o QR e o código do autenticador.
     * Devolve a resposta da confirmação.
     *
     * @param  array{sessao: string, lembrar?: string}  $aparelho
     */
    private function ligarODoisFatores(array $aparelho, bool $codigoCerto = true): TestResponse
    {
        $depoisDoQr = $this->guardarCookies($aparelho, $this->voltar($aparelho)
            ->post(route('settings.2fa.ativar'), ['password' => self::SENHA])
            ->assertSessionHasNoErrors());

        // O código sai do segredo que o passo acima acabou de gerar.
        $segredo = (string) $this->user->fresh()->two_factor_secret;

        return $this->voltar($depoisDoQr)->post(route('settings.2fa.confirmar'), [
            'codigo' => $codigoCerto ? Totp::codigo($segredo, Totp::passoAtual()) : $this->codigoErrado($segredo),
        ]);
    }

    /** Um número de 6 dígitos que o autenticador NÃO mostraria agora (nem 30 s antes ou depois). */
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
}
