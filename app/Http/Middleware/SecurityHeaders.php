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
     * Reavaliado em 02/08/2026. O bloqueio **não é técnico — é de alcance**: o nonce só
     * funciona se TODO `<script>` inline servido carregar o `nonce=` da requisição, e hoje
     * existem **12 blocos inline**, dos quais **9 executáveis fora dos layouts**:
     *
     *   partials/cookie-consent · transactions/_form · transactions/index · faturas/index
     *   accounts/_form · dependents/index · profile/edit
     *   profile/partials/delete-user-form · pwa/offline
     *
     * O `cookie-consent` é incluído pelos **quatro** layouts, ou seja, está em 100% das
     * páginas: uma CSP com nonce sem carimbar esses arquivos quebraria o app inteiro.
     * (O 12º bloco, o `sm-dashboard-data` do dashboard, é `type="application/json"` —
     * bloco de dados, não executa, e `script-src` não se aplica a ele.)
     *
     * **Hash SHA-256 não substitui o nonce aqui:** dois desses blocos têm interpolação
     * Blade que muda por requisição/ambiente (`dependents/index` injeta o id do form que
     * falhou na validação; `accounts/_form` interpola `asset()`), e um hash fixo faria a
     * tela morrer em silêncio a cada edição de um inline. Só o anti-flash de tema, de
     * conteúdo realmente fixo, seria hasheável.
     *
     * ## O pjax NÃO é o obstáculo (diagnóstico anterior corrigido)
     *
     * A versão antiga desta nota dizia que fazer o `nav.js` carimbar o nonce atual seria
     * "pior que hoje", por dar nonce válido a um XSS armazenado vindo no HTML do fetch.
     * **Isso não se sustenta** — verificado no navegador em 02/08/2026:
     *
     *  - o `DOMParser` **preserva** o `nonce` do HTML buscado: `getAttribute('nonce')`
     *    devolve o valor nos scripts legítimos e `null` num script sem nonce. Logo o
     *    `nav.js` consegue re-carimbar **seletivamente** — só nos scripts cujo nonce
     *    bate com o do documento buscado (publicado nele como `<meta name="csp-nonce">`).
     *    Um XSS armazenado não tem nonce, não casa, e continua sem executar. O nonce da
     *    requisição é imprevisível, então o payload não tem como forjá-lo;
     *  - **armadilha para quem for implementar:** no documento já ativo o navegador
     *    esconde o nonce do atributo (`getAttribute('nonce')` → `""`), mas ele sobrevive
     *    na propriedade (`elemento.nonce` → valor). Leia sempre `.nonce` /
     *    `document.currentScript.nonce`, **nunca** `getAttribute('nonce')`.
     *
     * Então a rodada que fizer isto precisa de escopo sobre as 9 views acima + `nav.js`,
     * e pode manter os inlines onde estão (não é obrigatório migrá-los para módulos).
     *
     * ## `style-src` continua com `'unsafe-inline'` de propósito
     *
     * São 71 atributos `style="..."` nas views e 3 blocos `<style>`; nonce **não existe
     * para atributo de estilo**, só para `<style>`/`<link>`. Tirar o `'unsafe-inline'`
     * daqui exigiria varrer os 71 para classes. O ganho de segurança que importa é em
     * `script-src` — style inline não executa código.
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
