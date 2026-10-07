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
        '3.1' => 'c36132e44a1bde0d3085cf1cb4a88eacbf7d6d00c974d7edc47f45035f94cf1c', // região de São Paulo confirmada (24/09/2026)
        '3.2' => 'e6999aebe4eead82c12e27dd1829763ed53f184f6880a4825a805f3b45c69434', // registro de atividade, aparelho confiável do 2FA e fuso do relógio (04/10/2026)
        '3.3' => '13afb68e4b5e200dda24222c13a922cae81012c7377201af43dfb60d234b1008', // "lembrar de mim" por 7 dias, IP só para quem agiu e aceite da versão nova no próximo acesso (05/10/2026)
        '3.4' => 'bade960cc1c9891ce4e00a9e542ba5d28597f6a87b52e2f6fa4320e908cf2851', // provedor de e-mail nomeado: Oracle Email Delivery, região São Paulo (07/10/2026)
        '3.5' => 'c226fe3c08104e28a321fbfa88a3ffde2bc4b40a5f9fd38243715892724ecd87', // Entrar com o Google: o Google entre os serviços (2.1, 6 e 13) (07/10/2026)
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
