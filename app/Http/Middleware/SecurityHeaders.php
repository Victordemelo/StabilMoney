<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança de todas as respostas do app.
 *
 * Nenhum destes headers corrige uma falha existente — eles limitam o dano da PRÓXIMA.
 * O pentest de jul/2026 achou um XSS armazenado (nome de categoria em `innerHTML`) que,
 * sem CSP, exfiltrava dados para um domínio externo à vontade. Corrigido o XSS, a CSP
 * é a rede de proteção para o caso de outro aparecer.
 */
class SecurityHeaders
{
    /**
     * Header que publica o nonce da RESPOSTA para o pjax.
     *
     * O `nav.js` precisa saber qual nonce o servidor emitiu para o HTML que ele
     * acabou de buscar, para distinguir script legítimo de script injetado. O
     * header é a fonte confiável disso: diferente do corpo, ele não pode ser
     * forjado por conteúdo armazenado. (Lê-lo exige execução de script — quem
     * já tem isso não precisa do nonce.)
     */
    public const HEADER_NONCE = 'X-Csp-Nonce';

    public function handle(Request $request, Closure $next): Response
    {
        // ANTES do $next: o nonce precisa existir enquanto a view renderiza.
        // `Vite::useCspNonce()` gera um valor aleatório, guarda para a requisição
        // inteira E carimba sozinho as tags que o `@vite` emite — que são
        // justamente os scripts que o `nav.js` consulta para descobrir o nonce
        // do documento vivo.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        // Respostas de arquivo (download/stream) não têm headers manipuláveis do mesmo jeito.
        if (! method_exists($response, 'header')) {
            return $response;
        }

        $response->headers->set('Content-Security-Policy', $this->csp($nonce));
        $response->headers->set(self::HEADER_NONCE, $nonce);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()');

        // HSTS só faz sentido (e só é honrado) sobre HTTPS. Enviar em http é inócuo,
        // mas em dev poderia travar o navegador no https de localhost.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Monta a política.
     *
     * ## `script-src` usa NONCE, não `'unsafe-inline'` (05/08/2026)
     *
     * O nonce é sorteado por requisição (`Vite::useCspNonce()`, 40 caracteres) e só
     * executa script que o carregue. Um XSS armazenado — o achado do pentest de
     * jul/2026 foi exatamente isso — passa a ser texto inerte: não tem como
     * adivinhar o valor da requisição.
     *
     * Todo `<script>` inline das views leva `nonce="{{ Vite::cspNonce() }}"`. São 12,
     * e o `partials/cookie-consent` está em 100% das páginas — **ao criar um bloco
     * inline novo, carimbe-o**, senão a tela morre em silêncio (o navegador bloqueia
     * e só o console avisa). O `CspComNonceTest` varre o HTML servido e falha se
     * algum ficar sem, justamente para isso não passar despercebido.
     * As tags do `@vite` são carimbadas pelo próprio Laravel.
     *
     * **Hash SHA-256 não serviria:** dois blocos têm interpolação Blade que muda por
     * requisição (`dependents/index` injeta o id do form que falhou na validação;
     * `accounts/_form` interpola `asset()`), e hash fixo mataria a tela a cada edição.
     *
     * ## O pjax e o header `X-Csp-Nonce`
     *
     * O `nav.js` troca o `#content` por HTML buscado via fetch e precisa RECRIAR os
     * scripts (innerHTML não executa `<script>`). Recriar tudo carimbando o nonce
     * vivo entregaria ao atacante o que a CSP existe para negar. Por isso o servidor
     * publica o nonce da resposta no header `X-Csp-Nonce` (fonte confiável: header
     * não é forjável por conteúdo armazenado) e o `nav.js` só recria o script cujo
     * `nonce` bate com ele.
     *
     * Dois detalhes que custam horas a quem não souber:
     *  - no documento JÁ ATIVO o navegador esconde o nonce do atributo
     *    (`getAttribute('nonce')` → `""`), mas ele sobrevive na propriedade
     *    (`elemento.nonce`). No documento inerte do `DOMParser` o atributo é legível
     *    — é isso que permite validar o HTML buscado;
     *  - no elemento novo, o nonce precisa ser atribuído pela PROPRIEDADE
     *    (`s.nonce = ...`), não por `setAttribute`.
     *
     * ## `style-src` continua com `'unsafe-inline'` de propósito
     *
     * São 71 atributos `style="..."` nas views e 3 blocos `<style>`; **nonce não
     * existe para atributo de estilo**, só para `<style>`/`<link>`. Tirar o
     * `'unsafe-inline'` daqui exigiria varrer os 71 para classes, e style inline não
     * executa código — o ganho que importa é em `script-src`.
     *
     * A política ainda entrega o resto contra exfiltração: `connect-src`/`img-src`
     * restritos à própria origem, `frame-ancestors 'none'` (clickjacking),
     * `object-src 'none'`, `base-uri` e `form-action` travados.
     */
    protected function csp(string $nonce): string
    {
        $self = "'self'";

        // Em dev os assets vêm do servidor do Vite, mais o websocket de hot reload.
        [$vite, $viteWs] = $this->origensDoVite();

        return implode('; ', [
            "default-src {$self}",
            // Sem `'unsafe-inline'`: só executa script que carregue o nonce desta
            // resposta. Um XSS armazenado não tem como adivinhá-lo.
            "script-src {$self} 'nonce-{$nonce}'{$vite}",
            "style-src {$self} 'unsafe-inline' https://fonts.googleapis.com{$vite}",
            'font-src '.$self.' https://fonts.gstatic.com data:',
            // data: e blob: para o preview de foto antes do upload (FileReader).
            "img-src {$self} data: blob:",
            "media-src {$self}",                       // vídeo de fundo do login
            "connect-src {$self}{$vite}{$viteWs}",
            "worker-src {$self}",                      // service worker do PWA
            "manifest-src {$self}",
            "form-action {$self}",
            "base-uri {$self}",
            "object-src 'none'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * Origens do servidor de dev do Vite liberadas na CSP: `[http, ws]`.
     *
     * A origem é LIDA do arquivo `public/hot` (a URL real do dev server), com
     * `localhost`/`127.0.0.1` como rede de segurança para a janela entre subir o Vite
     * e o arquivo existir. Sem o arquivo — produção, ou build estático — nada é liberado.
     *
     * ⚠️ **Endereço IPv6 literal é DESCARTADO de propósito.** A gramática de
     * `host-source` da CSP não aceita `[::1]`: o navegador trata a fonte como inválida,
     * ignora e bloqueia o recurso. Já aconteceu — o Vite escolheu IPv6 sozinho e o app
     * abriu sem CSS nenhum, com o aviso só no console. A solução de verdade é o Vite
     * NÃO subir em IPv6 (`server.host` fixo no `vite.config.js`); aqui só evitamos
     * emitir uma fonte que o navegador vai jogar fora.
     */
    protected function origensDoVite(): array
    {
        if (! $this->emDesenvolvimento()) {
            return ['', ''];
        }

        $origens = ['http://localhost:5173', 'http://127.0.0.1:5173'];

        $hot = public_path('hot');
        if (is_file($hot) && ($url = trim((string) file_get_contents($hot))) !== '') {
            // Literal IPv6 (`http://[::1]:5173`) não é expressável em CSP — ver o
            // docblock. Emitir mesmo assim só suja a política com fonte inválida.
            if (! str_contains($url, '[')) {
                $origens[] = $url;
            }
        }

        $origens = array_values(array_unique($origens));
        // O websocket do HMR usa o MESMO host, trocando o esquema.
        $ws = array_map(fn (string $o) => str_replace(['http://', 'https://'], ['ws://', 'wss://'], $o), $origens);

        return [' '.implode(' ', $origens), ' '.implode(' ', $ws)];
    }

    protected function emDesenvolvimento(): bool
    {
        return app()->environment('local');
    }
}
