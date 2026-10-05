<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AparelhoConfiavel;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Confiar neste aparelho por 7 dias" na segunda etapa do login (pedido do dono, out/2026:
 * "o 2fa tem registro de 7 dias para não ficar pedindo toda hora?").
 *
 * Com a caixa marcada e o código certo, o navegador recebe o cookie `sm-aparelho-confiavel`
 * (App\Support\AparelhoConfiavel), e nos 7 dias seguintes o login NESTE navegador, NESTA
 * conta, pede só a senha. O que não pode mudar:
 *  - a senha continua sendo pedida, e os limites de tentativa, o banimento e o "lembrar de
 *    mim" do formulário continuam valendo;
 *  - o cookie de uma pessoa nunca pula o desafio de outra, nem adulterado, nem copiado;
 *  - a confiança cai em 7 dias e cai na hora quando a senha muda (por qualquer caminho), o
 *    2FA é refeito, os códigos são trocados, as outras sessões são encerradas ou a pessoa
 *    pede "Esquecer todos os aparelhos confiáveis".
 *
 * Os aparelhos fazem login de verdade (POST /login + POST do código) e guardam só o cookie que
 * o app emitiu — como um navegador.
 */
class ConfiarNesteAparelhoNoDoisFatoresTest extends TestCase
{
    use RefreshDatabase;

    /** A senha da UserFactory. */
    private const SENHA = 'password';

    private const SENHA_NOVA = 'outra-senha-bem-comprida';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    // ══════════════════════════════════════════════════ pular o desafio

    public function test_com_a_caixa_marcada_o_proximo_login_neste_aparelho_pede_so_a_senha(): void
    {
        $user = $this->comDoisFatores();

        $confianca = $this->entrarComCodigo($user, confiar: true);
        $this->assertNotNull($confianca, 'Marcar "Confiar neste aparelho" não emitiu o cookie.');

        $this->entrarSoComSenha($user, $confianca)->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_sem_a_caixa_nada_e_lembrado_e_o_codigo_continua_sendo_pedido(): void
    {
        $user = $this->comDoisFatores();

        $this->assertNull($this->entrarComCodigo($user, confiar: false));

        $this->entrarSoComSenha($user, null)->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    /** O que se dispensa é o CÓDIGO. A senha errada continua não entrando. */
    public function test_a_senha_continua_sendo_pedida(): void
    {
        $user = $this->comDoisFatores();
        $confianca = $this->entrarComCodigo($user, confiar: true);

        $this->entrarSoComSenha($user, $confianca, senha: 'senha-errada')->assertSessionHasErrors('email');
        $this->assertGuest();

        // E o cookie sozinho não abre sessão nenhuma.
        $this->aparelhoNovo()->withCookie(AparelhoConfiavel::COOKIE, $confianca)
            ->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_confianca_dura_sete_dias_e_nem_um_minuto_a_mais(): void
    {
        $user = $this->comDoisFatores();
        $confianca = $this->entrarComCodigo($user, confiar: true);

        $this->travel(7)->days();
        $this->travel(-1)->minutes();
        $this->entrarSoComSenha($user, $confianca)->assertRedirect(route('dashboard', absolute: false));

        $this->travel(2)->minutes();
        $this->entrarSoComSenha($user, $confianca)->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    /** Fixa, e não renovada a cada login: "confiar por 7 dias" é pedir o código em até 7 dias. */
    public function test_entrar_pelo_aparelho_confiavel_nao_renova_o_prazo(): void
    {
        $user = $this->comDoisFatores();
        $confianca = $this->entrarComCodigo($user, confiar: true);

        $this->travel(6)->days();
        $resposta = $this->entrarSoComSenha($user, $confianca)->assertRedirect(route('dashboard', absolute: false));
        $this->assertNull($resposta->getCookie(AparelhoConfiavel::COOKIE), 'O login confiável reemitiu o cookie.');

        $this->travel(2)->days();
        $this->entrarSoComSenha($user, $confianca)->assertRedirect(route('two-factor.login'));
    }

    public function test_o_cookie_e_cifrado_httponly_lax_e_vale_sete_dias(): void
    {
        config(['session.secure' => true]);
        $user = $this->comDoisFatores();

        $resposta = $this->entrarComCodigo($user, confiar: true, resposta: true);
        $cru = $resposta->getCookie(AparelhoConfiavel::COOKIE, decrypt: false);

        $this->assertNotNull($cru);
        $this->assertTrue($cru->isHttpOnly(), 'O JavaScript da página leria o cookie.');
        $this->assertSame('lax', $cru->getSameSite());
        $this->assertTrue($cru->isSecure(), 'O cookie não seguiu o `secure` da sessão.');
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $cru->getExpiresTime(), 5);

        // Cifrado pelo EncryptCookies: o navegador não vê o id da conta nem a assinatura.
        $claro = $resposta->getCookie(AparelhoConfiavel::COOKIE)->getValue();
        $this->assertMatchesRegularExpression('/^'.$user->id.':\d+:[0-9a-f]{64}$/', $claro);
        $this->assertStringNotContainsString($claro, $cru->getValue());
    }

    // ══════════════════════════════════════════════════ as barreiras que continuam

    public function test_banido_continua_barrado_mesmo_no_aparelho_confiavel(): void
    {
        $user = $this->comDoisFatores();
        $confianca = $this->entrarComCodigo($user, confiar: true);

        $user->forceFill(['banned_at' => now(), 'banned_reason' => 'teste'])->save();
        $token = $user->fresh()->remember_token;

        $resposta = $this->entrarSoComSenha($user, $confianca, lembrar: true)->assertSessionHasErrors('email');
        $this->assertGuest();
        // Nem sessão, nem "lembrar de mim": o banido é barrado antes do `login()`.
        $this->assertSame($token, $user->fresh()->remember_token);
        $this->assertNull($resposta->getCookie(auth()->guard('web')->getRecallerName()));
    }

    public function test_o_lembrar_de_mim_do_formulario_vale_no_login_confiavel(): void
    {
        $user = $this->comDoisFatores();
        $confianca = $this->entrarComCodigo($user, confiar: true);
        $nome = auth()->guard('web')->getRecallerName();

        $semLembrar = $this->entrarSoComSenha($user, $confianca, lembrar: false);
        $this->assertNull($semLembrar->getCookie($nome));

        $comLembrar = $this->entrarSoComSenha($user, $confianca, lembrar: true);
        $this->assertNotNull($comLembrar->getCookie($nome), 'O "lembrar de mim" sumiu no login confiável.');
    }

    public function test_o_login_confiavel_troca_o_id_da_sessao(): void
    {
        $user = $this->comDoisFatores();
        $confianca = $this->entrarComCodigo($user, confiar: true);

        // O navegador chega com uma sessão de visitante (o id que ele já tinha). Sem o
        // `regenerate()`, quem plantou esse id antes do login (fixação de sessão) entraria junto.
        $antes = str_repeat('a', 40);

        $this->aparelhoNovo()
            ->withCookie(config('session.cookie'), $antes)
            ->withCookie(AparelhoConfiavel::COOKIE, $confianca)
            ->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertNotSame($antes, $this->app['session']->getId());
    }

    public function test_o_limite_de_tentativas_do_login_continua_valendo(): void
    {
        $user = $this->comDoisFatores();
        $confianca = $this->entrarComCodigo($user, confiar: true);

        for ($i = 0; $i < 5; $i++) {
            $this->entrarSoComSenha($user, $confianca, senha: 'senha-errada');
        }

        $this->entrarSoComSenha($user, $confianca)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // ══════════════════════════════════════════════════ um cookie, uma conta

    public function test_o_cookie_de_uma_pessoa_nao_pula_o_desafio_de_outra_no_mesmo_navegador(): void
    {
        $ana = $this->comDoisFatores();
        $bia = $this->comDoisFatores();
        $confiancaDaAna = $this->entrarComCodigo($ana, confiar: true);

        $this->entrarSoComSenha($bia, $confiancaDaAna)->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        // A Bia confia no mesmo navegador: as duas passam a entrar só com a senha — a
        // confiança de uma não apaga a da outra (computador da família).
        $confiancaDasDuas = $this->entrarComCodigo($bia, confiar: true, cookieAnterior: $confiancaDaAna);

        $this->entrarSoComSenha($ana, $confiancaDasDuas)->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($ana);
        $this->entrarSoComSenha($bia, $confiancaDasDuas)->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($bia);
    }

    /** Trocar o id na entrada não serve: o id está dentro da assinatura. */
    public function test_entrada_copiada_para_o_id_de_outra_pessoa_nao_vale(): void
    {
        $ana = $this->comDoisFatores();
        $bia = $this->comDoisFatores();
        [, $validade, $assinatura] = explode(':', $this->entrarComCodigo($ana, confiar: true));

        $this->entrarSoComSenha($bia, "{$bia->id}:{$validade}:{$assinatura}")
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    /** @return array<string, array{0: string}> */
    public static function adulteracoes(): array
    {
        return [
            'assinatura inventada' => ['assinatura'],
            'validade esticada' => ['validade'],
            'lixo' => ['lixo'],
        ];
    }

    #[DataProvider('adulteracoes')]
    public function test_cookie_adulterado_nao_vale(string $adulteracao): void
    {
        $user = $this->comDoisFatores();
        [$id, $validade, $assinatura] = explode(':', $this->entrarComCodigo($user, confiar: true));

        // Um dia depois, a validade esticada em um dia ainda cabe no teto de 7 dias contados
        // de agora: quem a barra é a assinatura, não o teto.
        $this->travel(1)->days();

        $falso = match ($adulteracao) {
            'assinatura' => "{$id}:{$validade}:".str_repeat('a', 64),
            'validade' => $id.':'.((int) $validade + 86400).':'.$assinatura,
            'lixo' => "{$id}:{$validade}:{$assinatura}<script>",
        };

        $this->entrarSoComSenha($user, $falso)->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    /** Valor que não foi cifrado pelo app (montado à mão no navegador) nem chega a ser lido. */
    public function test_cookie_sem_a_cifra_do_app_nao_vale(): void
    {
        $user = $this->comDoisFatores();
        $valor = $this->entrarComCodigo($user, confiar: true);

        $this->aparelhoNovo()
            ->withUnencryptedCookie(AparelhoConfiavel::COOKIE, $valor)
            ->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    // ══════════════════════════════════════════════════ revogação

    /** @return array<string, array{0: string}> */
    public static function revogacoes(): array
    {
        return [
            'trocar a senha' => ['trocar a senha'],
            'redefinir a senha pelo link' => ['redefinir a senha'],
            'senha trocada direto no banco' => ['senha no banco'],
            'desligar e religar o 2FA' => ['religar'],
            'trocar os códigos de recuperação' => ['trocar os códigos'],
            'encerrar outras sessões' => ['encerrar sessões'],
            'esquecer todos os aparelhos' => ['esquecer'],
        ];
    }

    #[DataProvider('revogacoes')]
    public function test_a_confianca_cai_em_todos_os_aparelhos(string $acao): void
    {
        $user = $this->comDoisFatores();
        $confianca = $this->entrarComCodigo($user, confiar: true);

        // Controle: até aqui, o aparelho entra só com a senha.
        $this->entrarSoComSenha($user, $confianca)->assertRedirect(route('dashboard', absolute: false));

        $senha = $this->revogar($acao, $user->fresh());

        $this->entrarSoComSenha($user->fresh(), $confianca, senha: $senha)
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_o_titular_trocar_a_senha_do_dependente_derruba_a_confianca_dele(): void
    {
        $titular = User::factory()->create();
        $dependente = $this->comDoisFatores(['account_owner_id' => $titular->id, 'is_admin' => false]);
        $confianca = $this->entrarComCodigo($dependente, confiar: true);

        $this->aparelhoNovo()->actingAs($titular)
            ->patch(route('dependentes.update', $dependente), [
                'name' => $dependente->name,
                'email' => $dependente->email,
                'password' => self::SENHA_NOVA,
            ])->assertSessionHasNoErrors();

        $this->entrarSoComSenha($dependente->fresh(), $confianca, senha: self::SENHA_NOVA)
            ->assertRedirect(route('two-factor.login'));
    }

    /** Senha errada no botão não revoga nada — senão uma sessão sem a senha apagaria as confianças. */
    public function test_esquecer_os_aparelhos_exige_a_senha(): void
    {
        $user = $this->comDoisFatores();
        $confianca = $this->entrarComCodigo($user, confiar: true);

        $this->aparelhoNovo()->actingAs($user->fresh())
            ->post(route('settings.2fa.esquecer-aparelhos'), ['password' => 'senha-errada'])
            ->assertSessionHasErrors('password', errorBag: 'twoFactorAparelhos');

        $this->assertSame(0, (int) $user->fresh()->two_factor_trust_version);
        $this->entrarSoComSenha($user, $confianca)->assertRedirect(route('dashboard', absolute: false));
    }

    /** Esquecer também tira o cookie deste navegador (o que ficaria lá não valeria mais nada). */
    public function test_esquecer_apaga_o_cookie_deste_aparelho(): void
    {
        $user = $this->comDoisFatores();
        $confianca = $this->entrarComCodigo($user, confiar: true);

        $resposta = $this->aparelhoNovo()->actingAs($user->fresh())
            ->withCookie(AparelhoConfiavel::COOKIE, $confianca)
            ->post(route('settings.2fa.esquecer-aparelhos'), ['password' => self::SENHA])
            ->assertRedirect(route('settings', '2fa'));

        $cookie = $resposta->getCookie(AparelhoConfiavel::COOKIE, decrypt: false);
        $this->assertNotNull($cookie);
        $this->assertLessThan(time(), $cookie->getExpiresTime(), 'O cookie deste aparelho não foi apagado.');
    }

    // ══════════════════════════════════════════════════ a tela

    public function test_a_tela_do_codigo_oferece_a_caixa_desmarcada(): void
    {
        $user = $this->comDoisFatores();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);

        foreach ([route('two-factor.login'), route('two-factor.login', ['recuperacao' => 1])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#<input type="checkbox" name="confiar" value="1"\s*/>#', $html);
            $this->assertStringContainsString('Confiar neste aparelho por 7 dias', $html);
        }
    }

    public function test_codigo_de_recuperacao_tambem_pode_confiar_no_aparelho(): void
    {
        $user = $this->comDoisFatores();
        $codigo = $user->two_factor_recovery_codes[0];

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);
        $resposta = $this->post(route('two-factor.login'), ['codigo' => $codigo, 'recuperacao' => '1', 'confiar' => '1'])
            ->assertRedirect(route('dashboard', absolute: false));

        $confianca = $resposta->getCookie(AparelhoConfiavel::COOKIE)?->getValue();
        $this->assertNotNull($confianca);
        $this->entrarSoComSenha($user, $confianca)->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_codigo_errado_com_a_caixa_marcada_nao_confia_em_nada(): void
    {
        $user = $this->comDoisFatores();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);
        $resposta = $this->from(route('two-factor.login'))
            ->post(route('two-factor.login'), ['codigo' => '000000', 'confiar' => '1'])
            ->assertSessionHasErrors('codigo');

        $this->assertNull($resposta->getCookie(AparelhoConfiavel::COOKIE));
    }

    public function test_as_configuracoes_dizem_se_este_aparelho_e_confiavel_e_ate_quando(): void
    {
        $user = $this->comDoisFatores();
        $confianca = $this->entrarComCodigo($user, confiar: true);
        $ate = now()->addDays(7)->translatedFormat('j \d\e F');

        $this->aparelhoNovo()->actingAs($user->fresh())
            ->withCookie(AparelhoConfiavel::COOKIE, $confianca)
            ->get(route('settings', '2fa'))->assertOk()
            ->assertSee('data-aparelho-confiavel="sim"', false)
            ->assertSee('É confiável até '.$ate, false)
            ->assertSee('Esquecer todos os aparelhos confiáveis');

        $this->aparelhoNovo()->actingAs($user->fresh())
            ->get(route('settings', '2fa'))->assertOk()
            ->assertSee('data-aparelho-confiavel="nao"', false)
            ->assertSee('Não é confiável: o código é pedido em todo login.', false);
    }

    public function test_depois_de_esquecer_a_tela_avisa_e_mostra_o_aparelho_como_nao_confiavel(): void
    {
        $user = $this->comDoisFatores();
        $confianca = $this->entrarComCodigo($user, confiar: true);

        $this->aparelhoNovo()->actingAs($user->fresh())
            ->withCookie(AparelhoConfiavel::COOKIE, $confianca)
            ->followingRedirects()
            ->post(route('settings.2fa.esquecer-aparelhos'), ['password' => self::SENHA])
            ->assertOk()
            ->assertSee('Pronto: nenhum aparelho é confiável agora.')
            ->assertSee('data-aparelho-confiavel="nao"', false);
    }

    // ══════════════════════════════════════════════════ apoio

    private function comDoisFatores(array $atributos = []): User
    {
        $user = User::factory()->create($atributos);

        $user->forceFill([
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_recovery_codes' => RecoveryCodes::gerar(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    /** Um código que o autenticador mostraria e que ainda não foi gasto (a janela aceita ±1 passo). */
    private function codigoNovo(User $user): string
    {
        $user = $user->fresh();
        $passo = max(Totp::passoAtual() - 1, (int) $user->two_factor_last_step + 1);

        return Totp::codigo($user->two_factor_secret, $passo);
    }

    /**
     * Outro navegador: sem usuário na memória dos guards, sem sessão e sem cookie. O app de
     * teste não é recriado entre requisições, então isto é zerado à mão.
     */
    private function aparelhoNovo(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['cookie']->flushQueuedCookies();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this;
    }

    /**
     * Login completo num navegador novo: senha e código, com ou sem a caixa. Devolve o valor
     * do cookie de confiança que o navegador guardaria (ou null) — ou a resposta inteira.
     */
    private function entrarComCodigo(User $user, bool $confiar, bool $resposta = false, ?string $cookieAnterior = null): string|TestResponse|null
    {
        $this->aparelhoNovo();

        if ($cookieAnterior !== null) {
            $this->withCookie(AparelhoConfiavel::COOKIE, $cookieAnterior);
        }

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('two-factor.login'));

        $final = $this->post(route('two-factor.login'), array_filter([
            'codigo' => $this->codigoNovo($user),
            'confiar' => $confiar ? '1' : null,
        ]))->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);

        return $resposta ? $final : $final->getCookie(AparelhoConfiavel::COOKIE)?->getValue();
    }

    /** Um navegador que só guarda o cookie de confiança (ou nenhum) tenta entrar com a senha. */
    private function entrarSoComSenha(User $user, ?string $confianca, string $senha = self::SENHA, bool $lembrar = false): TestResponse
    {
        $this->aparelhoNovo();

        if ($confianca !== null) {
            $this->withCookie(AparelhoConfiavel::COOKIE, $confianca);
        }

        return $this->post('/login', array_filter([
            'email' => $user->email,
            'password' => $senha,
            'remember' => $lembrar ? 'on' : null,
        ]));
    }

    /** Executa a ação que revoga e devolve a senha que vale depois dela. */
    private function revogar(string $acao, User $user): string
    {
        $this->aparelhoNovo();

        switch ($acao) {
            case 'trocar a senha':
                $this->actingAs($user)->put(route('password.update'), [
                    'current_password' => self::SENHA,
                    'password' => self::SENHA_NOVA,
                    'password_confirmation' => self::SENHA_NOVA,
                ])->assertSessionHasNoErrors();

                return self::SENHA_NOVA;

            case 'redefinir a senha':
                $this->post(route('password.store'), [
                    'token' => Password::broker()->createToken($user),
                    'email' => $user->email,
                    'password' => self::SENHA_NOVA,
                    'password_confirmation' => self::SENHA_NOVA,
                ])->assertSessionHasNoErrors();

                return self::SENHA_NOVA;

            case 'senha no banco':
                // Um caminho que ainda não existe e esqueça de revogar: o hash da senha está
                // na assinatura, então trocar a senha derruba a confiança de qualquer jeito.
                $user->forceFill(['password' => Hash::make(self::SENHA_NOVA)])->save();

                return self::SENHA_NOVA;

            case 'religar':
                $this->actingAs($user)->delete(route('settings.2fa.desativar'), [
                    'password' => self::SENHA,
                    'codigo' => $this->codigoNovo($user),
                ])->assertSessionHasNoErrors();
                $this->actingAs($user->fresh())->post(route('settings.2fa.ativar'), ['password' => self::SENHA])
                    ->assertSessionHasNoErrors();
                $this->actingAs($user->fresh())->post(route('settings.2fa.confirmar'), [
                    'codigo' => $this->codigoNovo($user),
                ])->assertSessionHasNoErrors();
                $this->assertTrue($user->fresh()->temDoisFatores());

                return self::SENHA;

            case 'trocar os códigos':
                $this->actingAs($user)->post(route('settings.2fa.codigos'), [
                    'password' => self::SENHA,
                    'codigo' => $this->codigoNovo($user),
                ])->assertSessionHasNoErrors();

                return self::SENHA;

            case 'encerrar sessões':
                $this->actingAs($user)->delete(route('settings.sessions.destroy'), ['password' => self::SENHA])
                    ->assertSessionHasNoErrors();

                return self::SENHA;

            case 'esquecer':
                $this->actingAs($user)->post(route('settings.2fa.esquecer-aparelhos'), ['password' => self::SENHA])
                    ->assertSessionHasNoErrors();

                return self::SENHA;
        }

        $this->fail("Ação desconhecida: {$acao}");
    }
}
