<?php

namespace Tests\Feature;

use App\Http\Middleware\BloqueiaUsuarioBanido;
use App\Models\User;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Banido com 2FA é barrado ANTES de gastar o código (observação da auditoria de 05/09/2026).
 *
 * O defeito: o desafio do login (`TwoFactorChallengeController`) conferia o código e abria a
 * sessão sem olhar o banimento; quem barrava era o `BloqueiaUsuarioBanido`, na requisição
 * SEGUINTE. Até lá o código já tinha sido gasto: o passo do TOTP gravado, ou um código de
 * recuperação riscado da lista — este não volta, e faria falta se o banimento fosse desfeito.
 *
 * O comportamento certo: o banimento é conferido junto com a pendência, depois da senha e
 * antes do código, e a pessoa recebe o mesmo recado de quem entra sem 2FA — sem o motivo.
 *
 * Desde 23/09/2026 o banimento também é conferido na PRIMEIRA etapa (`LoginRequest`, ver
 * BanidoNaoRecebeSessaoNoLoginTest): quem já está banido nem chega ao desafio. Por isso os
 * testes daqui banem a conta com o login JÁ PENDENTE — a senha conferida, o código ainda
 * não —, que é o caso que só o `pendente()` do desafio alcança.
 */
class BanidoNaoGastaCodigoDoDoisFatoresTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'password';

    private const MOTIVO = 'Fraude confirmada pela moderação';

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

    private function banir(User $user): void
    {
        $user->forceFill(['banned_at' => now(), 'banned_reason' => self::MOTIVO])->save();
    }

    private function erroDoLogin(): ?string
    {
        return session('errors')?->first('email');
    }

    /**
     * Senha certa (conta ainda sem banimento), o banimento chega com o login pendente, e o
     * código é mandado direto — sem passar pela tela, como faria um script. `$quemBanir` é a
     * conta cujo banimento alcança `$user` (ela mesma, ou o titular de um dependente).
     */
    private function entrarComCodigo(User $user, array $dados, ?User $quemBanir = null): TestResponse
    {
        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('two-factor.login'));

        $this->banir($quemBanir ?? $user);

        return $this->post(route('two-factor.login'), $dados);
    }

    public function test_banido_para_antes_do_codigo_e_o_passo_do_totp_nao_e_gasto(): void
    {
        $user = $this->comDoisFatores();

        $this->entrarComCodigo($user, ['codigo' => Totp::codigo($user->two_factor_secret, Totp::passoAtual())])
            ->assertRedirect(route('login'));

        $this->assertStringContainsString('suspensa', (string) $this->erroDoLogin());
        $this->assertGuest();
        $this->assertNull($user->fresh()->two_factor_last_step, 'O banido gastou o passo do TOTP antes de ser barrado.');
    }

    public function test_o_codigo_de_recuperacao_do_banido_nao_e_riscado(): void
    {
        $user = $this->comDoisFatores();
        $codigos = $user->two_factor_recovery_codes;

        $this->entrarComCodigo($user, ['codigo' => $codigos[0], 'recuperacao' => 1])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame($codigos, $user->fresh()->two_factor_recovery_codes, 'Um código de recuperação foi queimado num login barrado.');
    }

    /** Nem a tela do código aparece: a pendência some, e voltar a ela pede a senha de novo. */
    public function test_banido_no_meio_do_login_nao_chega_a_ver_a_tela_do_codigo(): void
    {
        $user = $this->comDoisFatores();

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('two-factor.login'));
        $this->banir($user);

        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
        $this->assertStringContainsString('suspensa', (string) $this->erroDoLogin());

        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
        $this->assertStringContainsString('expirou', (string) $this->erroDoLogin());
    }

    /**
     * O mesmo recado, palavra por palavra, em toda porta — e nenhuma com o motivo: banido
     * antes da senha (barrado no login, com ou sem 2FA) e banido com o login pendente
     * (barrado no desafio). A fonte é uma só, `BloqueiaUsuarioBanido::mensagem()`.
     */
    public function test_o_banido_recebe_o_mesmo_recado_sem_o_motivo_em_toda_porta(): void
    {
        $semDoisFatores = User::factory()->create();
        $this->banir($semDoisFatores);
        $this->post('/login', ['email' => $semDoisFatores->email, 'password' => self::SENHA]);
        $noLoginSem2fa = $this->erroDoLogin();
        $this->flushSession();

        $comDoisFatores = $this->comDoisFatores();
        $this->banir($comDoisFatores);
        $this->post('/login', ['email' => $comDoisFatores->email, 'password' => self::SENHA]);
        $noLoginCom2fa = $this->erroDoLogin();
        $this->flushSession();

        $pendente = $this->comDoisFatores();
        $this->post('/login', ['email' => $pendente->email, 'password' => self::SENHA])
            ->assertRedirect(route('two-factor.login'));
        $this->banir($pendente);
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
        $noDesafio = $this->erroDoLogin();

        foreach ([$noLoginSem2fa, $noLoginCom2fa, $noDesafio] as $recado) {
            $this->assertSame(BloqueiaUsuarioBanido::mensagem(), $recado);
            $this->assertStringNotContainsString(self::MOTIVO, (string) $recado);
        }
    }

    /** Banir o titular alcança o dependente — no desafio também. */
    public function test_dependente_de_titular_banido_tambem_para_antes_do_codigo(): void
    {
        $titular = User::factory()->create(['is_admin' => true]);
        $dependente = $this->comDoisFatores(['account_owner_id' => $titular->id, 'is_admin' => false]);

        $this->entrarComCodigo($dependente, ['codigo' => Totp::codigo($dependente->two_factor_secret, Totp::passoAtual())], $titular)
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($dependente->fresh()->two_factor_last_step);
    }
}
