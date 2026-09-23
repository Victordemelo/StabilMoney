<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Nenhuma view tem handler de evento inline (`onsubmit=`, `onclick=`…) — 23/09/2026.
 *
 * A CSP com nonce (05/08/2026) bloqueia TODO atributo `on*=`: atributo de evento não aceita
 * nonce. O navegador descarta em silêncio — só o console avisa —, e cada
 * `onsubmit="return confirm(...)"` do app virou um "Excluir" que apaga sem perguntar: conta,
 * categoria, lançamento, dependente. Achado pelos agentes da rodada de 22/09 e confirmado num
 * Chromium real ("Executing inline event handler violates… CSP").
 *
 * Confirmação passa por `data-confirmar` (sm/confirmar.js); qualquer outro comportamento, por
 * um ouvinte registrado num script que leve o nonce.
 *
 * ⚠️ `faturas/index.blade.php` está na lista de pendências: tem quatro `onsubmit` (excluir conta
 * fixa, estornar pagamento, remover estorno, remover despesa) e está com trabalho não commitado
 * de outra sessão (o redesenho). Sai da lista junto com a correção — o segundo teste avisa
 * quando ela já não for necessária.
 */
class NenhumHandlerInlineNasViewsTest extends TestCase
{
    /**
     * Atributo `on…=` com valor entre aspas, precedido de espaço (ou no começo da linha) como
     * todo atributo HTML — assim não pega `data-on…`, parte de outra palavra, nem a menção entre
     * crases que os comentários do projeto fazem ao explicar por que não usar handler inline.
     */
    private const HANDLER_INLINE = '/(?:^|(?<=\s))on[a-z]+\s*=\s*["\']/i';

    /** @var list<string> */
    private const PENDENTES = [
        'faturas/index.blade.php',
    ];

    public function test_nenhuma_view_usa_handler_de_evento_inline(): void
    {
        $achados = [];

        foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $arquivo) {
            $relativo = str_replace('\\', '/', $arquivo->getRelativePathname());

            if (in_array($relativo, self::PENDENTES, true)) {
                continue;
            }

            foreach (preg_split('/\R/', $arquivo->getContents()) as $i => $linha) {
                if (preg_match(self::HANDLER_INLINE, $linha)) {
                    $achados[] = $relativo.':'.($i + 1).'  '.trim($linha);
                }
            }
        }

        $this->assertSame(
            [],
            $achados,
            'Handler inline é bloqueado pela CSP com nonce e some sem erro — use `data-confirmar` '
                ."ou um ouvinte num script com nonce:\n".implode("\n", $achados),
        );
    }

    public function test_a_lista_de_pendencias_so_tem_view_que_ainda_precisa_dela(): void
    {
        foreach (self::PENDENTES as $relativo) {
            $this->assertMatchesRegularExpression(
                self::HANDLER_INLINE,
                (string) file_get_contents(resource_path('views/'.$relativo)),
                "{$relativo} já não tem handler inline: tire-o da lista de pendências deste teste.",
            );
        }
    }
}
