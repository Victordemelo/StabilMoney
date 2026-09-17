<?php

namespace Tests\Unit;

use App\Support\Texto;
use PHPUnit\Framework\TestCase;

/**
 * `Texto::paraColuna` — o texto que o app MONTA (parte fixa + dado do usuário) cabendo
 * num `varchar(255)`.
 *
 * A régua é a do MySQL numa coluna utf8mb4: CARACTERES (code points), não bytes. A suíte
 * roda em sqlite, que não aplica tamanho de coluna nenhum — então quem garante o limite
 * são estas medições, não um erro do banco.
 */
class TextoTest extends TestCase
{
    private const FAMILIA = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"; // 👨‍👩‍👧: 5 code points, 1 grafema

    private const BANDEIRA = "\u{1F1E7}\u{1F1F7}"; // 🇧🇷: 2 code points, 1 grafema

    private const CEDILHA_DECOMPOSTA = "c\u{0327}"; // "ç" colado do macOS: 2 code points, 1 grafema

    public function test_texto_que_cabe_volta_intacto(): void
    {
        $this->assertSame('Pagamento da fatura — Nubank', Texto::paraColuna('Nubank', antes: 'Pagamento da fatura — '));

        $noLimite = str_repeat('a', 255);
        $this->assertSame($noLimite, Texto::paraColuna($noLimite));
    }

    /**
     * 255 "ç" são 510 bytes e 255 "💳" são 1.020 — e os dois cabem num `varchar(255)`
     * utf8mb4. Medir em bytes cortaria à toa um nome que o banco aceitaria inteiro.
     */
    public function test_mede_em_caracteres_como_o_mysql_e_nao_em_bytes(): void
    {
        foreach (['ç', '💳'] as $caractere) {
            $texto = str_repeat($caractere, 255);

            $this->assertSame($texto, Texto::paraColuna($texto), "255 × {$caractere} cabe e não pode ser cortado");
        }
    }

    public function test_encurta_so_o_nome_e_preserva_o_prefixo(): void
    {
        $nome = mb_substr(str_repeat('Cartão da Família 💳 ', 20), 0, 255);

        $resultado = Texto::paraColuna($nome, antes: 'Pagamento da fatura — ');

        $this->assertSame(255, mb_strlen($resultado));
        $this->assertStringStartsWith('Pagamento da fatura — Cartão da Família 💳', $resultado);
        $this->assertStringEndsWith(Texto::RETICENCIAS, $resultado);
    }

    /** O sufixo é o que diz QUAL competência foi paga — ele não pode ser o que some. */
    public function test_encurta_so_o_nome_e_preserva_o_sufixo(): void
    {
        $nome = str_repeat('Condomínio ', 30);

        $resultado = Texto::paraColuna($nome, depois: ' — setembro/2026');

        $this->assertLessThanOrEqual(255, mb_strlen($resultado));
        $this->assertStringStartsWith('Condomínio Condomínio', $resultado);
        $this->assertStringEndsWith(Texto::RETICENCIAS.' — setembro/2026', $resultado);
    }

    /**
     * Com 250 caracteres antes, sobra espaço para 254 (um vai para as reticências). O
     * grafema seguinte tem mais de um code point e não cabe INTEIRO — então sai inteiro.
     * Cortar por code point deixaria um ZWJ solto, meia bandeira ou um "c" sem cedilha.
     */
    public function test_nunca_parte_um_grafema_ao_meio(): void
    {
        $casos = [
            'família (5 code points)' => [250, self::FAMILIA],
            'bandeira (2 code points)' => [253, self::BANDEIRA],
            'cedilha decomposta (2 code points)' => [253, self::CEDILHA_DECOMPOSTA],
        ];

        foreach ($casos as $nome => [$antes, $grafema]) {
            $texto = str_repeat('a', $antes).$grafema.'bbbb';

            $this->assertSame(
                str_repeat('a', $antes).Texto::RETICENCIAS,
                Texto::paraColuna($texto),
                "o grafema {$nome} que não cabe inteiro sai inteiro",
            );
        }

        // E o que cabe inteiro fica inteiro: 249 + 5 = 254.
        $this->assertSame(
            str_repeat('a', 249).self::FAMILIA.Texto::RETICENCIAS,
            Texto::paraColuna(str_repeat('a', 249).self::FAMILIA.'bbbb'),
        );
    }

    public function test_nao_deixa_espaco_antes_das_reticencias(): void
    {
        $this->assertSame(
            str_repeat('a', 253).Texto::RETICENCIAS,
            Texto::paraColuna(str_repeat('a', 253).' bbbb'),
        );
    }

    /**
     * Varredura: nome, prefixo e sufixo de vários tamanhos, com acento, emoji de vários
     * code points e bandeira. O resultado cabe SEMPRE e é SEMPRE UTF-8 válido — UTF-8
     * inválido seria outro erro do MySQL (1366), igualmente HTTP 500.
     */
    public function test_resultado_sempre_cabe_e_e_utf8_valido(): void
    {
        $pedaco = 'Conceição '.self::FAMILIA.' São João '.self::BANDEIRA.' ';

        foreach ([0, 1, 22, 100, 254, 300] as $tamanhoFixo) {
            foreach ([0, 1, 200, 255, 400] as $tamanhoNome) {
                $fixo = mb_substr(str_repeat('Prefixo — ', 40), 0, $tamanhoFixo);
                $nome = mb_substr(str_repeat($pedaco, 30), 0, $tamanhoNome);

                foreach ([['antes' => $fixo], ['depois' => $fixo]] as $partes) {
                    $resultado = Texto::paraColuna($nome, ...$partes);
                    $contexto = "fixo={$tamanhoFixo} nome={$tamanhoNome} ".array_key_first($partes);

                    $this->assertLessThanOrEqual(255, mb_strlen($resultado), $contexto);
                    $this->assertTrue(mb_check_encoding($resultado, 'UTF-8'), $contexto);
                }
            }
        }
    }

    /** Parte fixa maior que a coluna (um e-mail absurdo): corta o conjunto, mas cabe. */
    public function test_parte_fixa_que_sozinha_nao_cabe_corta_o_conjunto_pelo_fim(): void
    {
        $resultado = Texto::paraColuna('Maria', depois: ' <'.str_repeat('m', 300).'@exemplo.com>');

        $this->assertSame(255, mb_strlen($resultado));
        $this->assertStringStartsWith('Maria <mmm', $resultado);
        $this->assertStringEndsWith(Texto::RETICENCIAS, $resultado);
    }

    public function test_byte_invalido_nao_derruba_e_sai_utf8_valido(): void
    {
        $resultado = Texto::paraColuna("Cart\xE3o ".str_repeat('x', 300), antes: 'Pagamento da fatura — ');

        $this->assertTrue(mb_check_encoding($resultado, 'UTF-8'));
        $this->assertLessThanOrEqual(255, mb_strlen($resultado));
        $this->assertStringStartsWith('Pagamento da fatura — Cart', $resultado);
    }
}
