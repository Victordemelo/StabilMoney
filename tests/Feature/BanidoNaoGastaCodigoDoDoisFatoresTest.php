<?php

namespace Tests\Feature;

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

    /** Senha certa, e o código mandado direto — sem passar pela tela, como faria um script. */
    private function entrarComCodigo(User $user, array $dados): TestResponse
    {
        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('two-factor.login'));

        return $this->post(route('two-factor.login'), $dados);
    }

    public function test_banido_para_antes_do_codigo_e_o_passo_do_totp_nao_e_gasto(): void
    {
        $user = $this->comDoisFatores();
        $this->banir($user);

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
        $this->banir($user);

        $this->entrarComCodigo($user, ['codigo' => $codigos[0], 'recuperacao' => 1])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame($codigos, $user->fresh()->two_factor_recovery_codes, 'Um código de recuperação foi queimado num login barrado.');
    }

    /** Nem a tela do código aparece: a pendência some, e voltar a ela pede a senha de novo. */
    public function test_banido_nao_chega_a_ver_a_tela_do_codigo(): void
    {
        $user = $this->comDoisFatores();
        $this->banir($user);

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA]);

        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
        $this->assertStringContainsString('suspensa', (string) $this->erroDoLogin());

        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
        $this->assertStringContainsString('expirou', (string) $this->erroDoLogin());
    }

    /**
     * O mesmo recado, palavra por palavra, com e sem 2FA — e nenhum dos dois com o motivo.
     * O de quem entra sem 2FA vem do `BloqueiaUsuarioBanido`; os dois textos vivem em
     * lugares diferentes, e é este teste que os mantém iguais.
     */
    public function test_com_e_sem_2fa_o_banido_recebe_a_mesma_mensagem_sem_o_motivo(): void
    {
        $semDoisFatores = User::factory()->create();
        $this->banir($semDoisFatores);

        $this->post('/login', ['email' => $semDoisFatores->email, 'password' => self::SENHA]);
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $recadoSem2fa = $this->erroDoLogin();

        $this->flushSession();

        $comDoisFatores = $this->comDoisFatores();
        $this->banir($comDoisFatores);

        $this->post('/login', ['email' => $comDoisFatores->email, 'password' => self::SENHA]);
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
        $recadoCom2fa = $this->erroDoLogin();

        $this->assertNotNull($recadoSem2fa);
        $this->assertSame($recadoSem2fa, $recadoCom2fa);
        $this->assertStringNotContainsString(self::MOTIVO, $recadoCom2fa);
    }

    /** Banir o titular alcança o dependente — no desafio também. */
    public function test_dependente_de_titular_banido_tambem_para_antes_do_codigo(): void
    {
        $titular = User::factory()->create(['is_admin' => true]);
        $dependente = $this->comDoisFatores(['account_owner_id' => $titular->id, 'is_admin' => false]);
        $this->banir($titular);

        $this->entrarComCodigo($dependente, ['codigo' => Totp::codigo($dependente->two_factor_secret, Totp::passoAtual())])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($dependente->fresh()->two_factor_last_step);
    }
}
