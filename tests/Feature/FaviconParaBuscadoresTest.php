<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O site aparecia SEM ÍCONE na Busca do Google (15/10/2026 — achado da sessão do servidor, a pedido
 * do Victor): o /favicon.ico era o arquivo vazio do esqueleto do Laravel, e o único ícone em PNG tinha
 * 64 px, que não é múltiplo de 48. Agora há um .ico de verdade (16/32/48), PNGs de 48/96/192 e o
 * apple-touch-icon na raiz, e todas as páginas apontam para eles (`partials/favicons`).
 */
class FaviconParaBuscadoresTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_favicon_ico_e_um_icone_de_verdade_com_16_32_e_48(): void
    {
        $arquivo = public_path('favicon.ico');
        $this->assertGreaterThan(1000, filesize($arquivo), 'O /favicon.ico não pode ser o arquivo vazio do esqueleto.');

        // Cabeçalho ICO: reservado 0, tipo 1, N imagens; cada entrada de 16 bytes começa com largura/altura.
        $bytes = file_get_contents($arquivo);
        $this->assertSame([0, 1], array_values(unpack('v2', substr($bytes, 0, 4))));
        $quantas = unpack('v', substr($bytes, 4, 2))[1];
        $tamanhos = [];
        for ($i = 0; $i < $quantas; $i++) {
            $tamanhos[] = ord($bytes[6 + $i * 16]);
        }
        sort($tamanhos);
        $this->assertSame([16, 32, 48], $tamanhos);
    }

    public function test_os_pngs_sao_quadrados_em_multiplo_de_48(): void
    {
        foreach (['assets/icons/favicon-48.png' => 48, 'assets/icons/favicon-96.png' => 96, 'assets/icons/icon-192.png' => 192, 'apple-touch-icon.png' => 180] as $caminho => $lado) {
            $this->assertFileExists(public_path($caminho));
            [$largura, $altura, $tipo] = getimagesize(public_path($caminho));
            $this->assertSame([$lado, $lado, IMAGETYPE_PNG], [$largura, $altura, $tipo], $caminho);
        }
    }

    public function test_todas_as_paginas_apontam_para_os_mesmos_icones(): void
    {
        $paginas = [
            $this->get('/')->assertOk()->getContent(),
            $this->get(route('login'))->assertOk()->getContent(),
            $this->get(route('register'))->assertOk()->getContent(),
            $this->get(route('termos'))->assertOk()->getContent(),
            $this->actingAs(User::factory()->create())->get(route('transactions.index'))->assertOk()->getContent(),
        ];

        foreach ($paginas as $i => $html) {
            $this->assertMatchesRegularExpression('#<link rel="icon" href="[^"]*/favicon\.ico\?v=\d+" sizes="48x48" />#', $html, "Página {$i} sem o favicon.ico.");
            $this->assertMatchesRegularExpression('#<link rel="icon" type="image/png" sizes="96x96" href="[^"]*/assets/icons/favicon-96\.png\?v=\d+" />#', $html, "Página {$i} sem o PNG de 96.");
            $this->assertStringNotContainsString('assets/favicon.png', $html, "Página {$i} ainda aponta o PNG de 64 px.");
        }
    }

    public function test_a_pagina_de_erro_tambem(): void
    {
        $html = $this->get('/nao_existe_esta_pagina')->assertNotFound()->getContent();
        $this->assertStringContainsString('/favicon.ico?v=2" sizes="48x48">', $html);
    }
}
