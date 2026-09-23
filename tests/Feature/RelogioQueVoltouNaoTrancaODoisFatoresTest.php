<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O 2FA não pode trancar ninguém fora porque o relógio do servidor voltou (cobertura de
 * auditoria de 07/09/2026, "modos de falha").
 *
 * O defeito: a proteção de replay recusa todo passo <= `two_factor_last_step`, e esse valor
 * não tinha teto. Se o relógio do servidor pulasse para a frente e alguém entrasse nesse
 * intervalo, o passo gravado ficava no futuro; corrigido o relógio, TODO código da pessoa
 * passava a ser "anterior ao último usado" — até o relógio alcançar o passo gravado. Um dia
 * de salto, um dia sem conseguir entrar, sem nada que ela pudesse fazer além dos códigos de
 * recuperação (e no painel, sem eles, só o `tinker`).
 *
 * O comportamento certo: um passo gravado além de agora + janela é impossível com um relógio
 * que só anda para a frente, e é ignorado (`Totp::verificar`). O login de agora grava o passo
 * de agora no lugar, e o replay volta a ser barrado dali em diante. A regra mora no `Totp`,
 * então vale igual para o app (`TwoFactorService`) e para o painel (`Admin`).
 *
 * O estado "depois do salto" é montado direto no banco: o `Totp` lê o relógio do sistema
 * (`time()`), que o `travel()` não move.
 */
class RelogioQueVoltouNaoTrancaODoisFatoresTest extends TestCase
{
    use RefreshDatabase;

    /** Um dia de salto, em passos de 30 s. */
    private const UM_DIA = 2880;

    public function test_no_app_o_passo_gravado_no_futuro_nao_tranca_o_login(): void
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_recovery_codes' => RecoveryCodes::gerar(),
            'two_factor_confirmed_at' => now(),
            // O que sobrou de um login feito com o relógio um dia adiantado.
            'two_factor_last_step' => Totp::passoAtual() + self::UM_DIA,
        ])->save();

        $codigo = Totp::codigo($user->two_factor_secret, Totp::passoAtual());

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.login'), ['codigo' => $codigo])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);

        // O passo impossível deu lugar ao de agora…
        $this->assertLessThanOrEqual(Totp::passoAtual() + Totp::JANELA, $user->fresh()->two_factor_last_step);

        // …e o mesmo código não entra de novo: o replay segue barrado.
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->from(route('two-factor.login'))
            ->post(route('two-factor.login'), ['codigo' => $codigo])
            ->assertSessionHasErrors('codigo');

        $this->assertGuest();
    }

    public function test_no_painel_o_passo_gravado_no_futuro_nao_tranca_o_admin(): void
    {
        config(['admin.enabled' => true]);

        $admin = Admin::factory()->comDoisFatores()->create();
        $admin->forceFill(['two_factor_last_step' => Totp::passoAtual() + self::UM_DIA])->save();

        $codigo = Totp::codigo($admin->two_factor_secret, Totp::passoAtual());

        $this->actingAs($admin, 'admin')
            ->post(route('painel.2fa.verificar'), ['codigo' => $codigo])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('painel.home'));

        $this->assertLessThanOrEqual(Totp::passoAtual() + Totp::JANELA, $admin->fresh()->two_factor_last_step);

        // Replay continua recusado no painel também.
        $this->actingAs($admin->fresh(), 'admin')
            ->post(route('painel.2fa.verificar'), ['codigo' => $codigo])
            ->assertSessionHasErrors('codigo');
    }
}
