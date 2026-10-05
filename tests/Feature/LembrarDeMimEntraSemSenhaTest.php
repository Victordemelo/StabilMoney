<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "O lembrar de mim funciona? Ele entra sem pedir senha?" — pergunta do dono, out/2026.
 *
 * Funciona, e é isto que este teste prova de ponta a ponta, com o driver de sessão
 * `database` (o de produção): login de verdade com a caixa marcada; depois a sessão some —
 * a linha apagada de `sessions`, que é o que acontece quando ela expira (lifetime / limpeza do
 * `sessoes:limpar`) — e o navegador volta só com o que guardou. Entra pelo cookie
 * `remember_web_…` sem senha.
 *
 * Com o 2FA ligado, o "lembrar de mim" escolhido no login vale depois do código, e na volta
 * também não pede o código: é a semântica documentada (seção do 2FA no CLAUDE.md) — o código
 * protege o LOGIN em aparelho novo; o cookie de "lembrar de mim" re-autentica sem login.
 *
 * Sem a caixa, a sessão perdida manda de volta para o login.
 */
class LembrarDeMimEntraSemSenhaTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'password';

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    public function test_com_a_caixa_marcada_a_sessao_expira_e_o_aparelho_entra_sem_senha(): void
    {
        $user = User::factory()->create();

        $navegador = $this->cookiesDe(
            $this->aparelhoNovo()->post('/login', ['email' => $user->email, 'password' => self::SENHA, 'remember' => 'on'])
                ->assertRedirect(route('dashboard', absolute: false))
        );
        $this->assertArrayHasKey('lembrar', $navegador, 'O login com a caixa marcada não emitiu o "lembrar de mim".');

        $this->sessaoExpira();

        $this->voltar($navegador)->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_sem_a_caixa_a_sessao_expirada_pede_a_senha_de_novo(): void
    {
        $user = User::factory()->create();

        $navegador = $this->cookiesDe(
            $this->aparelhoNovo()->post('/login', ['email' => $user->email, 'password' => self::SENHA])
                ->assertRedirect(route('dashboard', absolute: false))
        );
        $this->assertArrayNotHasKey('lembrar', $navegador);

        // Controle: com a sessão viva, o navegador está dentro.
        $this->voltar($navegador)->get(route('dashboard'))->assertOk();

        $this->sessaoExpira();

        $this->voltar($navegador)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_com_2fa_o_lembrar_de_mim_vale_depois_do_codigo_e_nao_pede_o_codigo_na_volta(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_recovery_codes' => RecoveryCodes::gerar(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        // A escolha da caixa é guardada no login pendente e aplicada só depois do código.
        $this->aparelhoNovo()->post('/login', ['email' => $user->email, 'password' => self::SENHA, 'remember' => 'on'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $navegador = $this->cookiesDe(
            $this->post(route('two-factor.login'), [
                'codigo' => Totp::codigo($user->fresh()->two_factor_secret, Totp::passoAtual()),
            ])->assertRedirect(route('dashboard', absolute: false))
        );
        $this->assertArrayHasKey('lembrar', $navegador, 'O "lembrar de mim" marcado na senha se perdeu no desafio do código.');

        $this->sessaoExpira();

        $this->voltar($navegador)->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    // ══════════════════════════════════════════════════ navegador

    private function aparelhoNovo(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['cookie']->flushQueuedCookies();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];

        return $this;
    }

    /** A sessão some do servidor, como quando expira ou é limpa. */
    private function sessaoExpira(): void
    {
        DB::table('sessions')->delete();
    }

    /** @return array{sessao?: string, lembrar?: string} */
    private function cookiesDe(TestResponse $resposta): array
    {
        $cookies = [];

        if ($sessao = $resposta->getCookie(config('session.cookie'))) {
            $cookies['sessao'] = $sessao->getValue();
        }

        if ($lembrar = $resposta->getCookie(Auth::guard('web')->getRecallerName())) {
            $cookies['lembrar'] = $lembrar->getValue();
        }

        return $cookies;
    }

    /** O navegador volta com os cookies que guardou (e nada na memória do app). */
    private function voltar(array $navegador): static
    {
        $this->aparelhoNovo();

        if (isset($navegador['sessao'])) {
            $this->withCookie(config('session.cookie'), $navegador['sessao']);
        }

        if (isset($navegador['lembrar'])) {
            $this->withCookie(Auth::guard('web')->getRecallerName(), $navegador['lembrar']);
        }

        return $this;
    }
}
