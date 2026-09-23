<?php

namespace Tests\Feature;

use App\Mail\AlertaDoPainel;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * O primeiro acesso do admin é um LOGIN — com histórico, alerta e último acesso (achado A-2 da
 * auditoria de 06/09/2026).
 *
 * O defeito: o admin nasce sem 2FA (`admin:criar`) e o configura no primeiro acesso. O
 * `TwoFactorController::confirmar` só marcava a sessão como provada; histórico (`LOGIN`),
 * alerta por e-mail e `last_login_at` existiam só no `verificar`, o desafio das visitas
 * seguintes. Justamente o acesso que amarra um autenticador à conta passava em silêncio — quem
 * descobrisse a senha de um admin recém-criado cadastraria o PRÓPRIO celular sem aviso. E o
 * código errado durante a configuração também não virava `TOTP_FALHOU`.
 *
 * De quebra, o POST da configuração não barrava quem JÁ tinha o 2FA confirmado (a tela, GET, já
 * barrava): com a senha e um código, ele devolvia a lista inteira de códigos de recuperação.
 */
class PainelAdminPrimeiroAcessoContaComoLoginTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-bem-comprida';

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.enabled' => true]);
        Mail::fake();
    }

    /** Admin como o `admin:criar` o deixa: senha, e nada de 2FA. */
    private function adminNovo(): Admin
    {
        return Admin::factory()->create(['email' => 'chefe@exemplo.com', 'password' => Hash::make(self::SENHA)]);
    }

    /** Senha certa e a tela de configuração aberta — é ela que gera o segredo. */
    private function ateAConfiguracao(Admin $admin): Admin
    {
        $this->post(route('painel.autenticar'), ['email' => $admin->email, 'password' => self::SENHA])
            ->assertRedirect(route('painel.home'));

        $this->get(route('painel.2fa.setup'))->assertOk();

        return $admin->fresh();
    }

    /** Um número de 6 dígitos que o autenticador NÃO mostraria agora (nem 30 s antes ou depois). */
    private function codigoErrado(Admin $admin): string
    {
        $validos = array_map(
            fn (int $delta) => Totp::codigo($admin->two_factor_secret, Totp::passoAtual() + $delta),
            range(-Totp::JANELA - 1, Totp::JANELA + 1),
        );

        $n = 0;
        while (in_array(sprintf('%06d', $n), $validos, true)) {
            $n++;
        }

        return sprintf('%06d', $n);
    }

    public function test_configurar_o_2fa_no_primeiro_acesso_fica_no_historico_avisa_e_grava_o_ultimo_acesso(): void
    {
        $admin = $this->ateAConfiguracao($this->adminNovo());

        $this->post(route('painel.2fa.confirmar'), ['codigo' => Totp::codigo($admin->two_factor_secret, Totp::passoAtual())])
            ->assertRedirect(route('painel.2fa.recuperacao'))
            ->assertSessionHas('admin_2fa_ok', true);

        $login = AdminAuditLog::where('acao', AdminAuditLog::LOGIN)->sole();
        $this->assertSame($admin->id, $login->admin_id);
        $this->assertStringContainsString('Primeiro acesso', (string) $login->motivo);

        $admin->refresh();
        $this->assertNotNull($admin->last_login_at, 'O primeiro acesso não gravou o último acesso.');
        $this->assertSame('127.0.0.1', $admin->last_login_ip);

        Mail::assertSent(AlertaDoPainel::class, fn (AlertaDoPainel $mail) => $mail->acao === AdminAuditLog::LOGIN);
    }

    /** Subir de privilégio troca o id da sessão, no primeiro acesso como no desafio. */
    public function test_configurar_o_2fa_troca_o_id_da_sessao(): void
    {
        $admin = $this->ateAConfiguracao($this->adminNovo());
        $idAntes = Str::random(40);

        $this->withCookie(config('session.cookie'), $idAntes)
            ->post(route('painel.2fa.confirmar'), ['codigo' => Totp::codigo($admin->two_factor_secret, Totp::passoAtual())])
            ->assertRedirect(route('painel.2fa.recuperacao'));

        $this->assertNotSame($idAntes, session()->getId(), 'A sessão de antes do segundo fator continua valendo depois dele.');
    }

    public function test_codigo_errado_na_configuracao_fica_no_historico_e_avisa(): void
    {
        $admin = $this->ateAConfiguracao($this->adminNovo());

        $this->post(route('painel.2fa.confirmar'), ['codigo' => $this->codigoErrado($admin)])
            ->assertSessionHasErrors('codigo');

        $falha = AdminAuditLog::where('acao', AdminAuditLog::TOTP_FALHOU)->sole();
        $this->assertSame($admin->id, $falha->admin_id);
        Mail::assertSent(AlertaDoPainel::class, fn (AlertaDoPainel $mail) => $mail->acao === AdminAuditLog::TOTP_FALHOU);

        // E não virou login.
        $this->assertDatabaseMissing('admin_audit_logs', ['acao' => AdminAuditLog::LOGIN]);
        $this->assertNull($admin->fresh()->last_login_at);
        $this->assertFalse($admin->fresh()->temDoisFatores());
    }

    /**
     * Quem já tem o 2FA confirmado não usa a configuração como atalho: a senha e UM código
     * davam, por aqui, a lista inteira de códigos de recuperação — oito entradas futuras que
     * não dependem do celular.
     */
    public function test_quem_ja_configurou_nao_reve_os_codigos_de_recuperacao_pela_configuracao(): void
    {
        $admin = Admin::factory()->comDoisFatores()->create(['password' => Hash::make(self::SENHA)])->fresh();
        $confirmadoEm = $admin->two_factor_confirmed_at;

        // Só a senha: o desafio desta sessão ainda não foi feito.
        $this->actingAs($admin, 'admin')
            ->post(route('painel.2fa.confirmar'), ['codigo' => Totp::codigo($admin->two_factor_secret, Totp::passoAtual())])
            ->assertRedirect(route('painel.home'))
            ->assertSessionMissing('codigosDeRecuperacao')
            ->assertSessionMissing('admin_2fa_ok');

        $depois = $admin->fresh();
        $this->assertEquals($confirmadoEm, $depois->two_factor_confirmed_at);
        $this->assertNull($depois->two_factor_last_step, 'O código foi gasto por uma porta que não deveria existir.');
        $this->assertDatabaseMissing('admin_audit_logs', ['acao' => AdminAuditLog::LOGIN]);
    }
}
