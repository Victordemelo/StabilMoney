<?php

namespace Tests\Unit;

use App\Http\Controllers\CategoryController;
use App\Support\NomeDaCor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O nome FALADO das cores dos pickers (`App\Support\NomeDaCor`).
 *
 * As bolinhas de cor não têm texto: o radio de cada uma não tinha nome acessível (achado
 * crítico do axe no modal "Nova meta"), e o `title` com o hex, onde havia, era soletrado
 * pelo leitor de tela. O que se confere aqui: toda cor das paletas tem nome escolhido à
 * mão e distinto dentro da paleta; uma cor fora dela ganha nome pela aparência; e nada
 * que não seja cor vira hex falado.
 */
class NomeDaCorTest extends TestCase
{
    /** Paleta de metas, a mesma do `metas/index.blade.php`. */
    private const PALETA_DE_METAS = ['#1FA06E', '#18B6BE', '#F0A93B', '#9078D8', '#0F6B47', '#E5604D', '#59C497', '#3E84D8', '#C77F2A', '#7C8C84'];

    public function test_toda_cor_da_paleta_de_categorias_tem_nome_proprio_e_distinto(): void
    {
        $this->assertPaletaComNomesDistintos(CategoryController::CORES);
    }

    public function test_toda_cor_da_paleta_de_metas_tem_nome_proprio_e_distinto(): void
    {
        $this->assertPaletaComNomesDistintos(self::PALETA_DE_METAS);
    }

    public function test_o_hex_e_lido_sem_diferenca_de_caixa_nem_espacos(): void
    {
        $this->assertSame('Verde-escuro', NomeDaCor::de('#0f6b47'));
        $this->assertSame('Verde-escuro', NomeDaCor::de('  #0F6B47 '));
    }

    /** @return array<string, array{string, string}> */
    public static function coresForaDaPaleta(): array
    {
        return [
            'vermelho puro' => ['#FF0000', 'Vermelho'],
            'vermelho escuro' => ['#8B0000', 'Vermelho-escuro'],
            'laranja' => ['#FFA500', 'Laranja'],
            'verde puro' => ['#00FF00', 'Verde'],
            'azul puro' => ['#0000FF', 'Azul'],
            'azul-marinho antigo' => ['#123456', 'Azul-escuro'],
            'cinza médio' => ['#808080', 'Cinza'],
            'cinza claro' => ['#D3D3D3', 'Cinza-claro'],
            'cinza escuro' => ['#333333', 'Cinza-escuro'],
            'preto' => ['#000000', 'Preto'],
            'branco' => ['#FFFFFF', 'Branco'],
            'magenta' => ['#FF00AA', 'Rosa'],
        ];
    }

    #[DataProvider('coresForaDaPaleta')]
    public function test_cor_fora_da_paleta_ganha_nome_pela_aparencia(string $hex, string $esperado): void
    {
        $this->assertSame($esperado, NomeDaCor::de($hex));
    }

    public function test_o_que_nao_e_cor_vira_cor_personalizada_nunca_o_texto_cru(): void
    {
        foreach ([null, '', 'red', '#12', '#GGGGGG', '0F6B47', 'javascript:alert(1)'] as $entrada) {
            $this->assertSame(NomeDaCor::DESCONHECIDA, NomeDaCor::de($entrada), var_export($entrada, true));
        }
    }

    /** @param list<string> $paleta */
    private function assertPaletaComNomesDistintos(array $paleta): void
    {
        $nomes = array_map([NomeDaCor::class, 'de'], $paleta);

        foreach ($nomes as $i => $nome) {
            $this->assertNotSame(NomeDaCor::DESCONHECIDA, $nome, $paleta[$i].' sem nome');
            $this->assertDoesNotMatchRegularExpression('/#?[0-9A-F]{6}/i', $nome);
        }
        // Duas bolinhas com o mesmo nome seriam indistinguíveis para quem ouve.
        $this->assertSame($nomes, array_values(array_unique($nomes)), 'nomes repetidos na paleta: '.implode(', ', $nomes));
    }
}
