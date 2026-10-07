<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirmar o 2FA (e trocar os códigos de recuperação) grava sob trava (out/2026 —
 * auditoria de concorrência, pendência 7) — o equivalente, no app, do
 * `PainelAdminCodigoDeUsoUnicoSobTravaTest`.
 *
 * Dois "Confirmar" simultâneos com o mesmo código liam o mesmo estado e passavam os dois,
 * cada um devolvendo uma lista NOVA de códigos de recuperação; só a gravada por último
 * valia. A corrida é montada como no teste do painel: duas cópias do usuário carregadas
 * antes de qualquer uma gravar — o que cada requisição simultânea tem em mãos.
 */
class DoisFatoresConfirmadoSobTravaTest extends TestCase
{
    use RefreshDatabase;

    private function servico(): TwoFactorService
    {
        return app(TwoFactorService::class);
    }

    public function test_dois_confirmar_simultaneos_so_devolvem_uma_lista_e_ela_e_a_gravada(): void
    {
        $user = User::factory()->create();
        $this->servico()->iniciar($user);
        $codigo = Totp::codigo($user->fresh()->two_factor_secret, Totp::passoAtual());

        $requisicaoA = User::find($user->id);
        $requisicaoB = User::find($user->id);

        $lista = $this->servico()->confirmar($requisicaoA, $codigo);
        $this->assertNotNull($lista);
        $this->assertNull($this->servico()->confirmar($requisicaoB, $codigo), 'A segunda confirmação gerou outra lista.');

        $this->assertSame($lista, $user->fresh()->two_factor_recovery_codes, 'A lista mostrada não é a que ficou gravada.');
    }

    public function test_quem_chamou_ve_o_estado_gravado(): void
    {
        $user = User::factory()->create();
        $this->servico()->iniciar($user);
        $codigo = Totp::codigo($user->fresh()->two_factor_secret, Totp::passoAtual());

        $daRequisicao = User::find($user->id);
        $lista = $this->servico()->confirmar($daRequisicao, $codigo);

        // O controller segue usando o model da requisição (login, alerta, atividade).
        $this->assertTrue($daRequisicao->temDoisFatores());
        $this->assertSame($lista, $daRequisicao->two_factor_recovery_codes);
        $this->assertFalse($daRequisicao->isDirty());
    }

    public function test_trocar_os_codigos_devolve_a_lista_gravada(): void
    {
        $user = User::factory()->create();
        $this->servico()->iniciar($user);
        $this->servico()->confirmar($user, Totp::codigo($user->fresh()->two_factor_secret, Totp::passoAtual()));

        $daRequisicao = User::find($user->id);
        $nova = $this->servico()->regerarCodigosDeRecuperacao($daRequisicao);

        $this->assertSame($nova, $user->fresh()->two_factor_recovery_codes);
        $this->assertSame($nova, $daRequisicao->two_factor_recovery_codes);
    }
}
