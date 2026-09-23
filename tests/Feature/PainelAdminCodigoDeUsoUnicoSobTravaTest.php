<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No painel, o código do 2FA vale UMA vez mesmo com dois envios simultâneos (achado da
 * rodada de 22-23/09/2026).
 *
 * O defeito: `Admin::verificarTotp` e `consumirCodigoDeRecuperacao` conferiam o código no
 * model que o guard carregou no começo da requisição e só depois gravavam. Dois envios
 * simultâneos do MESMO código liam o mesmo último passo (ou a mesma lista de códigos de
 * recuperação) e passavam os dois. O app já fazia certo (`TwoFactorService`, sob
 * `lockForUpdate`); o painel, que guarda o acesso mais sensível, não.
 *
 * Como a suíte roda num processo só, a corrida é montada com DUAS cópias do admin carregadas
 * antes de qualquer uma gravar — exatamente o que cada requisição simultânea tem em mãos. A
 * segunda precisa ler o banco de novo (sob a trava) e ver o passo já queimado.
 */
class PainelAdminCodigoDeUsoUnicoSobTravaTest extends TestCase
{
    use RefreshDatabase;

    private function codigoAgora(Admin $admin): string
    {
        return Totp::codigo($admin->two_factor_secret, Totp::passoAtual());
    }

    public function test_o_mesmo_codigo_do_autenticador_nao_passa_em_duas_requisicoes_simultaneas(): void
    {
        $admin = Admin::factory()->comDoisFatores()->create();

        $requisicaoA = Admin::find($admin->id);
        $requisicaoB = Admin::find($admin->id);
        $codigo = $this->codigoAgora($admin);

        $this->assertTrue($requisicaoA->verificarTotp($codigo));
        $this->assertFalse($requisicaoB->verificarTotp($codigo), 'O mesmo código do autenticador valeu duas vezes.');

        $this->assertSame(Totp::passoAtual(), (int) $admin->fresh()->two_factor_last_step);
    }

    public function test_o_mesmo_codigo_de_recuperacao_nao_passa_em_duas_requisicoes_simultaneas(): void
    {
        $admin = Admin::factory()->comDoisFatores()->create();
        $codigos = $admin->two_factor_recovery_codes;

        $requisicaoA = Admin::find($admin->id);
        $requisicaoB = Admin::find($admin->id);

        $this->assertTrue($requisicaoA->consumirCodigoDeRecuperacao($codigos[0]));
        $this->assertFalse(
            $requisicaoB->consumirCodigoDeRecuperacao($codigos[0]),
            'O mesmo código de recuperação valeu duas vezes.',
        );

        // E a segunda gravação não ressuscitou a lista antiga por cima da nova.
        $this->assertSame(array_slice($codigos, 1), $admin->fresh()->two_factor_recovery_codes);
    }

    /** Dois "confirmar" simultâneos do setup: só um recebe a lista de códigos. */
    public function test_a_confirmacao_do_setup_so_devolve_os_codigos_uma_vez(): void
    {
        $admin = Admin::factory()->create();
        $admin->iniciarDoisFatores();

        $requisicaoA = Admin::find($admin->id);
        $requisicaoB = Admin::find($admin->id);
        $codigo = $this->codigoAgora($admin->fresh());

        $this->assertNotNull($requisicaoA->confirmarDoisFatores($codigo));
        $this->assertNull($requisicaoB->confirmarDoisFatores($codigo), 'A lista de códigos saiu duas vezes.');
    }

    /** Quem já confirmou não "confirma" de novo: um código não troca a lista inteira. */
    public function test_admin_ja_confirmado_nao_recebe_os_codigos_pelo_setup(): void
    {
        $admin = Admin::factory()->comDoisFatores()->create();

        $this->assertNull($admin->confirmarDoisFatores($this->codigoAgora($admin)));
    }

    /** O model da requisição acompanha o que foi gravado: nada fica "alterado" à toa. */
    public function test_o_model_da_requisicao_fica_com_o_estado_gravado(): void
    {
        $admin = Admin::factory()->comDoisFatores()->create();

        $this->assertTrue($admin->verificarTotp($this->codigoAgora($admin)));

        $this->assertSame(Totp::passoAtual(), (int) $admin->two_factor_last_step);
        $this->assertFalse($admin->isDirty('two_factor_last_step'));
    }
}
