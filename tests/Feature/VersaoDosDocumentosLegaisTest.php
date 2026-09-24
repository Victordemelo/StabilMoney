<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * "Ao alterar o texto dos documentos, suba a `version`" (CLAUDE.md) — agora conferido.
 *
 * O cadastro grava `legal.version` em users.terms_version como prova do aceite: se o texto muda
 * e a versão não, o registro passa a apontar para um texto que mudou por baixo. Foi o que
 * aconteceu em 02/09/2026 (auditoria de 07/09): a Política foi editada e continuou "Versão 2.0".
 *
 * A impressão é do TEXTO VISÍVEL (sem tags, comentários Blade nem espaços repetidos): trocar
 * uma classe CSS não pede versão nova; trocar uma palavra, pede.
 *
 * Mudou o texto de propósito? Suba `version` e `updated_at` em config/legal.php e acrescente
 * a linha da versão nova em IMPRESSOES com o valor que a falha mostra.
 */
class VersaoDosDocumentosLegaisTest extends TestCase
{
    private const IMPRESSOES = [
        '3.0' => '6e3f53764749972259a7045f17a20c38be802f2212980fd8e40675947e8d4bc6',
    ];

    public function test_o_texto_dos_documentos_so_muda_com_a_versao(): void
    {
        $versao = (string) config('legal.version');
        $atual = self::impressao();

        $this->assertArrayHasKey($versao, self::IMPRESSOES,
            "A legal.version {$versao} não tem impressão registrada. Acrescente em IMPRESSOES: '{$versao}' => '{$atual}'.");

        $this->assertSame(self::IMPRESSOES[$versao], $atual,
            "O texto dos Termos/Política mudou e a legal.version continua {$versao}. Suba a versão e a data "
            ."em config/legal.php e registre a impressão nova: '<versão nova>' => '{$atual}'.");
    }

    private static function impressao(): string
    {
        $texto = '';
        foreach (['privacidade', 'termos'] as $documento) {
            $fonte = file_get_contents(resource_path("views/legal/{$documento}.blade.php"));
            $fonte = preg_replace('/\{\{--.*?--\}\}/s', ' ', $fonte);
            $fonte = strip_tags($fonte);
            $texto .= trim(preg_replace('/\s+/u', ' ', $fonte))."\n";
        }

        return hash('sha256', $texto);
    }
}
