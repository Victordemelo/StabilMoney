<?php

namespace App\Mail\Concerns;

/**
 * A parte TEXTO de um e-mail, a partir do HTML confiável dos parágrafos.
 *
 * Os parágrafos dos Mailables são HTML confiável: marcação do próprio app (`<strong>`,
 * `<a>`) com o dado do usuário já passado por `e()` — é assim que o layout HTML os imprime
 * com `{!! !!}` sem virar injeção. Para o texto puro, a marcação sai e as entidades voltam a
 * ser os caracteres de verdade: `&quot;Esqueci a senha&quot;` vira `"Esqueci a senha"`, e
 * `Ana &lt;Teste&gt; &amp; Cia` volta a ser `Ana <Teste> & Cia`.
 *
 * A ORDEM importa: `strip_tags` antes de decodificar. Ao contrário, o `&lt;b&gt;` que a
 * pessoa digitou no nome viraria `<b>`, e o `strip_tags` o apagaria — o texto perderia um
 * pedaço do nome dela.
 *
 * Uma função só, para todos os e-mails (achado E-1 da auditoria de 07/09/2026): cada
 * Mailable tinha a sua cópia desta conversão, e a do alerta do painel esquecera de
 * decodificar — no texto puro saía `&amp;lt;email@x&amp;gt;`, com escape duplo.
 */
trait TextoSemMarcacao
{
    protected static function semMarcacao(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
