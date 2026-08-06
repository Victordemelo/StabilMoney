<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O modal "Lançar" trata 419 (revisão de 06/08/2026).
 *
 * Era o único caminho de escrita de despesa sem ramo para token CSRF vencido: o 419
 * caía no `else` genérico e mostrava "Não foi possível salvar. Confira os campos e
 * tente de novo." — numa tela onde campo nenhum está errado. O usuário reenviava
 * para sempre e o lançamento não era gravado nem enfileirado.
 *
 * Não é problema exclusivo de PWA: trocar a senha derruba as outras sessões
 * (`PasswordController` → `logoutOtherDevices` + purga de `sessions`), e qualquer
 * aba deixada aberta passa a ter token morto. A página servida do cache do service
 * worker é só o caso mais frequente.
 *
 * ⚠️ Asserções de código-fonte, deliberadamente: o projeto não tem runner de teste
 * unitário de JS (só Playwright, e `tests/e2e/offline-lancamento.spec.js` dirige
 * apenas o formulário cheio, nunca `#launchModal`). O que estas asserções guardam é
 * a existência do ramo; a cobertura de comportamento do modal segue como dívida
 * conhecida — ver o achado sobre `FilaOfflineNaTrocaDeUsuarioTest`.
 */
class ModalLancarTrata419Test extends TestCase
{
    use RefreshDatabase;

    private function launchJs(): string
    {
        $caminho = base_path('resources/js/sm/launch.js');
        $this->assertFileExists($caminho);

        return (string) file_get_contents($caminho);
    }

    public function test_o_modal_tem_ramo_para_419(): void
    {
        $this->assertMatchesRegularExpression(
            '/resp\.status === 419/',
            $this->launchJs(),
            'Sem ramo de 419, o token vencido cai na mensagem de validação e o lançamento se perde.',
        );
    }

    /** O retry precisa de um token NOVO — reenviar o mesmo daria 419 de novo. */
    public function test_o_modal_busca_um_token_fresco_e_refaz_o_envio(): void
    {
        $js = $this->launchJs();

        $this->assertStringContainsString('refreshCsrfToken', $js);
        $this->assertStringContainsString(
            "payload.set('_token'",
            $js,
            'O payload já tinha sido montado com o token velho: sem reescrevê-lo, o retry repete o 419.',
        );
    }

    /** Sessão morta de vez: o lançamento vai para a fila, nunca some. */
    public function test_419_definitivo_enfileira_em_vez_de_perder(): void
    {
        $js = $this->launchJs();

        $this->assertMatchesRegularExpression(
            '/if \(resp\.status === 419\) \{\s*\n\s*await enfileirar\(/',
            $js,
            'Depois do retry falhar, o lançamento precisa ir para a fila — descartá-lo era o defeito.',
        );
        $this->assertStringContainsString(
            'Sua sessão expirou',
            $js,
            'O toast tem de dizer a verdade: sessão expirou, não "sem conexão".',
        );
    }

    /** O helper de token vive no offline-queue e precisa continuar exportado. */
    public function test_o_helper_de_token_e_compartilhado(): void
    {
        $this->assertStringContainsString(
            'export async function refreshCsrfToken',
            (string) file_get_contents(base_path('resources/js/sm/offline-queue.js')),
            'launch.js importa este helper: sem o export, o build quebra.',
        );
    }

    /** A rota que serve o token fresco existe e está atrás de auth. */
    public function test_a_rota_do_token_existe_e_exige_login(): void
    {
        $this->get('/csrf-token')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->getJson('/csrf-token')
            ->assertOk()
            ->assertJsonStructure(['token']);
    }
}
