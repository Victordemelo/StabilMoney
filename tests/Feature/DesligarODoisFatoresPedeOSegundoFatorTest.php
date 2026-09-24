<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Com o 2FA ligado, desligá-lo e trocar os códigos de recuperação pedem a senha E o segundo
 * fator (varredura de autenticação de 24/09/2026).
 *
 * A exclusão da conta já exigia o código, com o argumento de que "desligar o 2FA já exigia
 * senha + 2FA" (CLAUDE.md) — só que o `TwoFactorController` pedia apenas a senha. Com uma
 * sessão aberta e a senha, sem o celular: desligava-se o 2FA e a exclusão passava sem código
 * nenhum; ou geravam-se códigos de recuperação próprios, sem alerta, para voltar à conta
 * quando quisesse.
 */
class DesligarODoisFatoresPedeOSegundoFatorTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'password';

    private function comDoisFatores(): User
    {
        $user = User::factory()->create();

        $user->forceFill([
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_recovery_codes' => RecoveryCodes::gerar(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    private function codigo(User $user, int $deslocamento = 0): string
    {
        return Totp::codigo($user->two_factor_secret, Totp::passoAtual() + $deslocamento);
    }

    // =========================================================== o atalho que existia

    public function test_sem_o_codigo_a_conta_com_2fa_nao_e_excluida_nem_desligando_o_2fa_antes(): void
    {
        $user = $this->comDoisFatores();

        $this->actingAs($user)->delete(route('settings.2fa.desativar'), ['password' => self::SENHA]);
        $this->actingAs($user->fresh())->delete(route('profile.destroy'), ['password' => self::SENHA]);

        $this->assertNotNull($user->fresh(), 'A conta com 2FA foi excluída sem o segundo fator.');
        $this->assertTrue($user->fresh()->temDoisFatores());
    }

    // =========================================================== desligar

    public function test_desligar_sem_o_codigo_e_recusado(): void
    {
        $user = $this->comDoisFatores();

        $this->actingAs($user)
            ->from('/configuracoes/2fa')
            ->delete(route('settings.2fa.desativar'), ['password' => self::SENHA])
            ->assertSessionHasErrors('codigo', errorBag: 'twoFactorDesligar');

        $this->assertTrue($user->fresh()->temDoisFatores());
    }

    public function test_desligar_com_codigo_errado_e_recusado(): void
    {
        $user = $this->comDoisFatores();
        $errado = $this->codigo($user) === '000000' ? '111111' : '000000';

        $this->actingAs($user)
            ->delete(route('settings.2fa.desativar'), ['password' => self::SENHA, 'codigo' => $errado])
            ->assertSessionHasErrors('codigo', errorBag: 'twoFactorDesligar');

        $this->assertTrue($user->fresh()->temDoisFatores());
    }

    public function test_desligar_com_o_codigo_do_autenticador_funciona(): void
    {
        $user = $this->comDoisFatores();

        $this->actingAs($user)
            ->delete(route('settings.2fa.desativar'), ['password' => self::SENHA, 'codigo' => $this->codigo($user)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'two-factor-disabled');

        $this->assertFalse($user->fresh()->temDoisFatores());
    }

    public function test_desligar_com_codigo_de_recuperacao_funciona_e_o_gasta(): void
    {
        $user = $this->comDoisFatores();
        $recuperacao = $user->two_factor_recovery_codes[0];

        $this->actingAs($user)
            ->delete(route('settings.2fa.desativar'), [
                'password' => self::SENHA,
                'codigo' => $recuperacao,
                'recuperacao' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->temDoisFatores());
    }

    /** A senha é conferida ANTES: um código de recuperação não pode morrer numa senha errada. */
    public function test_senha_errada_nao_gasta_o_codigo_de_recuperacao(): void
    {
        $user = $this->comDoisFatores();
        $recuperacao = $user->two_factor_recovery_codes[0];

        $this->actingAs($user)
            ->delete(route('settings.2fa.desativar'), [
                'password' => 'senha-errada',
                'codigo' => $recuperacao,
                'recuperacao' => '1',
            ])
            ->assertSessionHasErrors('password', errorBag: 'twoFactorDesligar');

        $this->assertContains($recuperacao, $user->fresh()->two_factor_recovery_codes);
        $this->assertTrue($user->fresh()->temDoisFatores());
    }

    /** Cancelar um setup pela metade continua sem pedir nada: não baixa proteção nenhuma. */
    public function test_cancelar_configuracao_pendente_segue_sem_pedir_codigo(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('settings.2fa.ativar'), ['password' => self::SENHA]);

        $this->actingAs($user->fresh())
            ->delete(route('settings.2fa.desativar'))
            ->assertSessionHas('status', 'two-factor-cancelled');
    }

    // =========================================================== códigos de recuperação

    public function test_gerar_codigos_novos_sem_o_codigo_e_recusado(): void
    {
        $user = $this->comDoisFatores();
        $antes = $user->two_factor_recovery_codes;

        $this->actingAs($user)
            ->post(route('settings.2fa.codigos'), ['password' => self::SENHA])
            ->assertSessionHasErrors('codigo', errorBag: 'twoFactorCodigos');

        $this->assertSame($antes, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_gerar_codigos_novos_com_o_codigo_troca_a_lista_e_avisa(): void
    {
        Mail::fake();
        $user = $this->comDoisFatores();
        $antes = $user->two_factor_recovery_codes;

        $this->actingAs($user)
            ->post(route('settings.2fa.codigos'), ['password' => self::SENHA, 'codigo' => $this->codigo($user)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('codigosDeRecuperacao');

        $this->assertEmpty(array_intersect($antes, $user->fresh()->two_factor_recovery_codes));
    }

    // =========================================================== a tela e os limites

    public function test_os_dois_formularios_pedem_o_codigo_e_aceitam_o_de_recuperacao(): void
    {
        $user = $this->comDoisFatores();

        $html = $this->actingAs($user)->get('/configuracoes/2fa')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'name="recuperacao"'));
        $this->assertStringContainsString('id="codigo_desativar_2fa"', $html);
        $this->assertStringContainsString('id="codigo_codigos_2fa"', $html);
    }

    public function test_as_duas_rotas_levam_o_limite_de_codigo(): void
    {
        foreach (['settings.2fa.desativar', 'settings.2fa.codigos'] as $nome) {
            $this->assertContains('throttle:dois-fatores', Route::getRoutes()->getByName($nome)->gatherMiddleware(), $nome);
            $this->assertContains('throttle:senha', Route::getRoutes()->getByName($nome)->gatherMiddleware(), $nome);
        }
    }
}
