<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Respostas de arquivo (download/stream) não têm headers manipuláveis do mesmo jeito.
        if (! method_exists($response, 'header')) {
            return $response;
        }

        $response->headers->set('Content-Security-Policy', $this->csp());
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
     * ## Por que `script-src` ainda tem `'unsafe-inline'` (e não nonce)
     *
     * Avaliado em 02/08/2026 e adiado com motivo concreto, não por preguiça: **com o
     * pjax atual, nonce quebra o app ou vira teatro**. O `nav.js::runScripts()` recria os
     * `<script>` inline do HTML carregado por fetch, copiando os atributos. Só que:
     *
     *  - o HTML novo chega com o nonce da requisição DELE (N2), enquanto a CSP que vale
     *    é a do documento aberto (N1) → o script recriado é **bloqueado**, e toda tela
     *    navegada por pjax perde seus scripts inline;
     *  - "resolver" fazendo o `nav.js` carimbar o nonce atual (N1) seria pior que hoje:
     *    ele daria nonce VÁLIDO a qualquer `<script>` presente no HTML recebido —
     *    inclusive a um XSS armazenado, que hoje não executa justamente por não ter
     *    nonce. Trocaríamos proteção real por uma sensação de proteção.
     *
     * O caminho certo é **eliminar os scripts inline do conteúdo** (hoje 12 blocos),
     * migrando-os para módulos que o `initContent()` do `app.js` já reinicia após o pjax.
     * O anti-flash de tema é a única exceção legítima — roda antes do primeiro paint —,
     * e para ele um hash SHA-256 na política resolve, já que o conteúdo é fixo.
     *
     * Enquanto isso, a política entrega o que importa contra exfiltração:
     * `connect-src`/`img-src` restritos à própria origem, `frame-ancestors 'none'`
     * (clickjacking), `object-src 'none'`, `base-uri` e `form-action` travados.
     */
    protected function csp(): string
    {
        $self = "'self'";

        // Em dev os assets vêm do servidor do Vite (localhost:5173, mais o websocket
        // de hot reload). Sem isto, `npm run dev` quebraria com a CSP ligada.
        $vite = $this->emDesenvolvimento() ? ' http://localhost:5173 http://127.0.0.1:5173' : '';
        $viteWs = $this->emDesenvolvimento() ? ' ws://localhost:5173 ws://127.0.0.1:5173' : '';

        return implode('; ', [
            "default-src {$self}",
            "script-src {$self} 'unsafe-inline'{$vite}",
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

    protected function emDesenvolvimento(): bool
    {
        return app()->environment('local');
    }
}
