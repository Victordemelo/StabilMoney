<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Trocar a senha e "Encerrar outras sessões" têm de derrubar também o "lembrar de mim"
 * dos outros aparelhos — não só as sessões.
 *
 * O defeito: as duas ações (`PasswordController::update` e
 * `SecurityController::destroyOtherSessions`) chamavam `Auth::logoutOtherDevices()` e
 * apagavam as linhas de `sessions`, mas nenhuma trocava o `remember_token`. E o cookie de
 * "lembrar de mim" re-autentica SEM sessão nenhuma: o `SessionGuard` confere só id + token
 * (o hash de senha que vai dentro do cookie só é conferido pelo middleware
 * `AuthenticateSession`, que não está ligado no projeto). O aparelho do invasor perdia a
 * sessão, voltava na requisição seguinte pelo cookie e seguia dentro — logo depois da ação
 * que a vítima toma justamente para tirá-lo de lá. Como a caixa "Lembrar de mim" vem
 * marcada no login, era o caso comum, não o raro.
 *
 * O redefinir pelo link (A-2) e a troca da senha do dependente pelo titular (A-6) já
 * trocavam o token; estes eram os dois caminhos que faltavam.
 *
 * A outra metade importa tanto quanto: o aparelho de quem AGIU, se entrou com "lembrar de
 * mim", continua lembrado — com um cookie novo, que carrega o token novo.
 *
 * Os aparelhos fazem login de verdade (POST /login com "lembrar de mim") e o cookie usado
 * é o que o app emitiu. O "outro aparelho" chega SEM sessão, só com o cookie: é o estado em
 * que ele fica depois que a sessão dele é apagada — e o único caminho que lhe sobra.
 */
class LembrarDeMimAntigoNaoEntraMaisTest extends TestCase
{
    use RefreshDatabase;

    /** A senha da UserFactory. */
    private const SENHA = 'password';

    private const SENHA_NOVA = 'outra-senha-bem-comprida';

    /** @return array<string, array{0: string}> */
    public static function acoes(): array
    {
        return [
            'trocar a senha' => ['trocar a senha'],
            'encerrar outras sessões' => ['encerrar outras sessões'],
        ];
    }

    #[DataProvider('acoes')]
    public function test_o_lembrar_de_mim_de_outro_aparelho_nao_entra_mais(string $acao): void
    {
        $user = User::factory()->create();
        $cookieDoInvasor = $this->entrarLembrando($user);

        // Controle: sem sessão nenhuma, o cookie sozinho abre a conta. É por aqui que o
        // aparelho do invasor volta depois que a linha da sessão dele é apagada.
        $this->abrirSoComOCookie($cookieDoInvasor)->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->agir($acao, $user)->assertSessionHasNoErrors();

        $this->abrirSoComOCookie($cookieDoInvasor)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[DataProvider('acoes')]
    public function test_o_aparelho_de_quem_agiu_continua_lembrado_com_um_cookie_novo(string $acao): void
    {
        $user = User::factory()->create();
        $cookieDoDono = $this->entrarLembrando($user);
        $tokenAntigo = $user->fresh()->getRememberToken();

        $resposta = $this->agir($acao, $user, cookieDoAparelho: $cookieDoDono)->assertSessionHasNoErrors();

        $cookieNovo = $resposta->getCookie($this->nomeDoCookie());
        $this->assertNotNull($cookieNovo, 'O aparelho de quem agiu perdeu o "lembrar de mim".');

        [$id, $token] = explode('|', $cookieNovo->getValue());
        $this->assertSame((string) $user->id, $id);
        $this->assertNotSame($tokenAntigo, $token, 'O cookie novo saiu com o token antigo.');
        // É o token que ficou no banco. Trocado DEPOIS do `logoutOtherDevices`, o cookie
        // sairia com o velho e o próprio dono perderia o "lembrar de mim".
        $this->assertSame($user->fresh()->getRememberToken(), $token);

        // E vale de fato: sem sessão, só com ele, o dono entra.
        $this->abrirSoComOCookie($cookieNovo->getValue())->assertOk();
        $this->assertAuthenticatedAs($user);

        // O que ele tinha antes, não — é o mesmo que os outros aparelhos guardam.
        $this->abrirSoComOCookie($cookieDoDono)->assertRedirect(route('login'));
    }

    /**
     * A troca do token vem DEPOIS da conferência da senha. Se viesse antes, quem tivesse
     * só a sessão (sem a senha) derrubaria o "lembrar de mim" de todos os aparelhos do dono
     * a cada chute errado.
     */
    #[DataProvider('acoes')]
    public function test_senha_errada_nao_mexe_no_lembrar_de_mim_de_ninguem(string $acao): void
    {
        $user = User::factory()->create();
        $cookie = $this->entrarLembrando($user);
        $token = $user->fresh()->getRememberToken();

        [$campo, $bag] = match ($acao) {
            'trocar a senha' => ['current_password', 'updatePassword'],
            'encerrar outras sessões' => ['password', 'logoutOtherSessions'],
        };

        $this->agir($acao, $user, senha: 'senha-errada')->assertSessionHasErrors($campo, errorBag: $bag);

        $this->assertSame($token, $user->fresh()->getRememberToken());
        $this->abrirSoComOCookie($cookie)->assertOk();
    }

    // ══════════════════════════════════════════════════ aparelhos

    /** Nome do cookie de "lembrar de mim" do guard `web`. */
    private function nomeDoCookie(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    /**
     * Passa a agir de OUTRO aparelho.
     *
     * Entre requisições o app de teste não é recriado: o guard guarda o usuário da
     * requisição anterior, a sessão acumula os atributos e os cookies de `withCookie`
     * seguem indo. Sem zerar os três, o "outro aparelho" herdaria o login de quem acabou
     * de agir — e o teste passaria sem provar nada.
     */
    private function outroAparelho(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];

        return $this;
    }

    /** Login de verdade, com "lembrar de mim"; devolve o cookie que o app emitiu. */
    private function entrarLembrando(User $user): string
    {
        $cookie = $this->outroAparelho()
            ->post('/login', ['email' => $user->email, 'password' => self::SENHA, 'remember' => 'on'])
            ->assertRedirect(route('dashboard', absolute: false))
            ->getCookie($this->nomeDoCookie());

        $this->assertNotNull($cookie, 'O login com "lembrar de mim" não emitiu o cookie.');

        return $cookie->getValue();
    }

    /** Um aparelho SEM sessão, só com o cookie de "lembrar de mim", tenta abrir o app. */
    private function abrirSoComOCookie(string $cookie): TestResponse
    {
        return $this->outroAparelho()
            ->withCookie($this->nomeDoCookie(), $cookie)
            ->get(route('dashboard'));
    }

    /**
     * O dono age no aparelho DELE: logado e, quando `$cookieDoAparelho` vem, com o
     * cookie de "lembrar de mim" que esse aparelho guarda.
     */
    private function agir(string $acao, User $user, ?string $cookieDoAparelho = null, string $senha = self::SENHA): TestResponse
    {
        $this->outroAparelho()->actingAs($user->fresh());

        if ($cookieDoAparelho !== null) {
            $this->withCookie($this->nomeDoCookie(), $cookieDoAparelho);
        }

        return match ($acao) {
            'trocar a senha' => $this->put(route('password.update'), [
                'current_password' => $senha,
                'password' => self::SENHA_NOVA,
                'password_confirmation' => self::SENHA_NOVA,
            ]),
            'encerrar outras sessões' => $this->delete(route('settings.sessions.destroy'), ['password' => $senha]),
        };
    }
}
