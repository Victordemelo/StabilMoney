<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O aviso verde do topo não mostra código interno (achado da rodada de 23/09/2026).
 *
 * O defeito: várias telas mandam um CÓDIGO no `status` ("two-factor-enabled",
 * "sessions-cleared"…) e transformam esse código em aviso no lugar certo — o card da aba 2FA,
 * o card de sessões. Mas o `partials/flash` do layout também lia o `status` e, sem tradução
 * para o código, o imprimia CRU no topo: quem ligava o 2FA via "two-factor-enabled" em cima
 * do aviso de verdade.
 *
 * O comportamento certo: código sem tradução não aparece no topo; a frase de gente e os
 * códigos traduzidos (ex.: "profile-updated") continuam aparecendo.
 */
class AvisoDoTopoSemCodigoInternoTest extends TestCase
{
    use RefreshDatabase;

    private const VERDE = '/<div class="flash" data-flash role="status">(.*?)<\/div>/s';

    /** @return array<string, array{0: string, 1: string, 2: string}> código, tela, aviso que a tela mostra */
    public static function codigos(): array
    {
        return [
            '2FA ligado' => ['two-factor-enabled', '/configuracoes/2fa', 'Verificação em duas etapas ativada'],
            '2FA desligado' => ['two-factor-disabled', '/configuracoes/2fa', 'A verificação em duas etapas foi desativada'],
            'setup cancelado' => ['two-factor-cancelled', '/configuracoes/2fa', ''],
            'códigos novos' => ['two-factor-recovery-codes', '/configuracoes/2fa', ''],
            'sessões encerradas' => ['sessions-cleared', '/configuracoes/seguranca', 'As outras sessões foram encerradas.'],
        ];
    }

    #[DataProvider('codigos')]
    public function test_codigo_interno_nao_aparece_cru_no_topo(string $codigo, string $tela, string $avisoDaTela): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->withSession(['status' => $codigo])
            ->get($tela)
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(self::VERDE, $html, "O topo mostrou o código \"{$codigo}\" cru.");
        $this->assertStringNotContainsString('>'.$codigo, $html);

        if ($avisoDaTela !== '') {
            // O aviso de verdade continua no card.
            $this->assertStringContainsString($avisoDaTela, $html);
        }
    }

    public function test_frase_de_gente_continua_no_topo(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->withSession(['status' => 'Transação removida.'])
            ->get('/')
            ->getContent();

        $this->assertMatchesRegularExpression(self::VERDE, $html);
        preg_match(self::VERDE, $html, $aviso);
        $this->assertStringContainsString('Transação removida.', $aviso[1]);
    }

    public function test_codigo_traduzido_continua_no_topo_ja_traduzido(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->withSession(['status' => 'profile-updated'])
            ->get('/')
            ->getContent();

        preg_match(self::VERDE, $html, $aviso);
        $this->assertNotEmpty($aviso, 'O "profile-updated" traduzido sumiu do topo.');
        $this->assertStringContainsString('Perfil atualizado com sucesso.', $aviso[1]);
    }
}
