<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A fila offline NUNCA escolhe a fonte do dinheiro (revisão de 06/08/2026).
 *
 * Tanto o replay da página (`offline-queue.js`) quanto o Background Sync
 * (`service-worker.blade.php`) reenviavam sozinhos com `funding_source:
 * 'cheque_especial'` ao receber 409, argumentando que "a compra já aconteceu no
 * mundo real". O argumento vale para REGISTRAR a despesa, não para escolher a
 * fonte: cheque especial cobra juros de verdade, e o sucesso do drain é silencioso
 * — o usuário só descobria olhando o extrato. Furava o invariante do modelo v3:
 * "o app NUNCA usa o cheque especial sozinho".
 *
 * ⚠️ Estes testes leem o CÓDIGO-FONTE, e isso é deliberado: o que eles guardam é a
 * AUSÊNCIA de uma linha específica, que nenhum teste de comportamento pega tão bem
 * quanto uma asserção de que ela não voltou. O comportamento do teto do resgate
 * (a outra metade desta correção) tem teste de verdade em `TetoDoResgateAprovadoTest`.
 */
class FilaNaoEscolheFonteTest extends TestCase
{
    private function fonte(string $caminho): string
    {
        $absoluto = base_path($caminho);
        $this->assertFileExists($absoluto);

        return (string) file_get_contents($absoluto);
    }

    /** @return list<array{0: string}> */
    public static function caminhosDaFila(): array
    {
        return [
            'replay da página' => ['resources/js/sm/offline-queue.js'],
            'background sync' => ['resources/views/pwa/service-worker.blade.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('caminhosDaFila')]
    public function test_nenhum_caminho_da_fila_escolhe_cheque_especial(string $caminho): void
    {
        $codigo = $this->fonte($caminho);

        $this->assertStringNotContainsString(
            "funding_source: 'cheque_especial'",
            $codigo,
            "{$caminho} voltou a escolher o cheque especial sozinho. A escolha da fonte é "
                .'do usuário — retenha o item com `needsFunding` e deixe a página perguntar.',
        );
        $this->assertStringNotContainsString(
            'funding_source: "cheque_especial"',
            $codigo,
            "{$caminho} voltou a escolher o cheque especial sozinho (aspas duplas).",
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('caminhosDaFila')]
    public function test_o_409_retem_o_item_para_o_usuario_decidir(string $caminho): void
    {
        $codigo = $this->fonte($caminho);

        $this->assertStringContainsString(
            'needsFunding',
            $codigo,
            "{$caminho} precisa marcar o item como `needsFunding` no 409, senão o "
                .'lançamento fica em loop de reenvio ou some sem tela.',
        );
    }

    /** Item retido não pode ser reenviado às cegas: os dois laços têm de pulá-lo. */
    #[\PHPUnit\Framework\Attributes\DataProvider('caminhosDaFila')]
    public function test_item_retido_e_pulado_pelo_reenvio_automatico(string $caminho): void
    {
        $codigo = $this->fonte($caminho);

        $this->assertMatchesRegularExpression(
            '/(!i\.needsFunding|item\.needsFunding\)?\s*\)?\s*continue|\|\|\s*item\.needsFunding)/',
            $codigo,
            "{$caminho} não pula o item retido — ele seria reenviado sozinho e levaria outro 409.",
        );
    }

    /** A tela de revisão é o que faz o item retido ter saída (fecha o buraco do selo travado). */
    public function test_a_pagina_tem_tela_de_revisao_para_o_item_retido(): void
    {
        $codigo = $this->fonte('resources/js/sm/offline-queue.js');

        $this->assertStringContainsString('renderRevisao', $codigo);
        $this->assertStringContainsString('pedirFonte', $codigo, 'A revisão precisa abrir o modal de escolha de fonte.');
        $this->assertStringContainsString(
            'resolverFonte',
            $codigo,
            'Sem o reenvio com a escolha do usuário, a revisão só mostraria o problema.',
        );
    }

    /**
     * O fallback sem JS também manda o teto — senão o caminho server-rendered ficaria
     * com o defeito que o `funding_max_amount` veio fechar.
     */
    public function test_fallback_sem_js_manda_o_teto_aprovado(): void
    {
        $this->assertStringContainsString(
            'name="funding_max_amount"',
            $this->fonte('resources/views/partials/funding-modal.blade.php'),
        );
    }

    /** O modal com JS resolve a escolha JÁ COM o teto que ele prometeu na tela. */
    public function test_modal_de_fonte_devolve_o_teto_aprovado(): void
    {
        $codigo = $this->fonte('resources/js/sm/funding.js');

        $this->assertStringContainsString('funding_max_amount', $codigo);
        $this->assertStringContainsString(
            'faltanteAprovado',
            $codigo,
            'O teto tem de ser o faltante que a tela mostrou, não um número recalculado.',
        );
    }
}
