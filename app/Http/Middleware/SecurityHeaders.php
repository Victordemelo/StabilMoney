<?php

namespace App\Http\Middleware;

use App\Support\Seo;
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

    /**
     * CSP das respostas que NUNCA executam script: as de erro e o /up (ver
     * `completarRespostaSemScript`).
     *
     * Sem nonce, de propósito: nonce sorteado mudaria o header a cada requisição, e o
     * 404 do prefixo do painel desligado tem de ser idêntico, header a header, ao de
     * uma URL que não existe (`PainelAdminDesligadoNaoSeRevelaTest`). Página de erro
     * não tem `<script>` nenhum, então não há nonce a carimbar: `default-src 'none'`
     * bloqueia tudo, e só o que a página usa é liberado — o `<style>` embutido e a
     * logo/favicon servidos pela própria origem.
     */
    public const CSP_SEM_SCRIPT = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; "
        ."base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

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

        // `set` SUBSTITUI: uma resposta de erro que já veio do handler com a CSP sem
        // nonce (ver `completarRespostaSemScript`) sai daqui com UMA CSP só, esta.
        $response->headers->set('Content-Security-Policy', $this->csp(
            $nonce,
            paraServiceWorker: $request->routeIs('pwa.sw'),
        ));
        $response->headers->set(self::HEADER_NONCE, $nonce);

        foreach (self::cabecalhosBasicos($request) as $nome => $valor) {
            $response->headers->set($nome, $valor);
        }

        // Os buscadores: fora por padrão (ver `cabecalhosBasicos`). Só as páginas públicas
        // da lista, em produção, abrem — e o robots.txt e o sitemap.xml, que existem para
        // o robô e não são páginas.
        if (Seo::indexavel($request, $response) || $request->routeIs('seo.*')) {
            $response->headers->remove('X-Robots-Tag');
        }

        return $response;
    }

    /**
     * Cabeçalhos de segurança para a resposta que NÃO passa por este middleware — ou
     * ainda não passou quando nasce.
     *
     * São as respostas de ERRO, montadas pelo handler de exceções
     * (`$exceptions->respond(...)` no `bootstrap/app.php`), e o /up. Este middleware é
     * do grupo `web`, e muito erro nasce fora do alcance dele:
     *  - fora do grupo, antes de qualquer rota: 404 e 405 do roteador, 503 da
     *    manutenção, 413 do POST grande demais;
     *  - dentro do grupo, mas ANTES dele na fila: 419 do CSRF, 429 do limite de
     *    tentativas, 404 do route model binding. A resposta volta pelas camadas de
     *    fora e nunca atravessa esta.
     * Sem isto, todos esses saíam sem CSP, sem `nosniff` e sem anti-clickjacking.
     *
     * Só entra o que FALTA. O erro que nasce DENTRO do alcance deste middleware (um
     * `abort(403)` no controller) ainda passa pelo `handle()` na volta, e ali a CSP com
     * nonce substitui esta — uma CSP por resposta, nunca duas.
     */
    public static function completarRespostaSemScript(Response $response, Request $request): Response
    {
        $cabecalhos = ['Content-Security-Policy' => self::CSP_SEM_SCRIPT] + self::cabecalhosBasicos($request);

        foreach ($cabecalhos as $nome => $valor) {
            if (! $response->headers->has($nome)) {
                $response->headers->set($nome, $valor);
            }
        }

        return $response;
    }

    /**
     * Os cabeçalhos que não dependem do conteúdo da resposta — iguais para página,
     * JSON, erro e /up.
     *
     * @return array<string, string>
     */
    private static function cabecalhosBasicos(Request $request): array
    {
        $cabecalhos = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), camera=(), microphone=(), payment=()',
            // Fora dos buscadores POR PADRÃO (23/09/2026): o app é privado, e página nova
            // tem de nascer fora do Google sem ninguém lembrar. Vale também para as
            // respostas de erro e para o painel administrativo — que assim nunca precisa
            // ser citado num robots.txt. O `handle()` tira o cabeçalho só das páginas
            // públicas da lista (config/seo.php), e só em produção.
            'X-Robots-Tag' => Seo::NOINDEX,
        ];

        // HSTS só faz sentido (e só é honrado) sobre HTTPS. Enviar em http é inócuo,
        // mas em dev poderia travar o navegador no https de localhost.
        if ($request->secure()) {
            $cabecalhos['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $cabecalhos;
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
    protected function csp(string $nonce, bool $paraServiceWorker = false): string
    {
        $self = "'self'";

        // Em dev os assets vêm do servidor do Vite, mais o websocket de hot reload.
        [$vite, $viteWs] = $this->origensDoVite();

        // O SERVICE WORKER obedece à CSP do próprio script — esta resposta, quando ela é o
        // /sw.js — e todo `fetch()` que ele faz conta como connect-src. Ele repassa e guarda
        // as fontes do Google (stale-while-revalidate, para o app manter a tipografia
        // offline), então precisa dos dois hosts aqui: a folha de estilo vem do
        // googleapis e os arquivos .woff2, do gstatic. Sem eles o fetch era bloqueado, o SW
        // devolvia erro de rede e TODO o app pós-login caía na fonte do sistema assim que o
        // SW assumia a página (P-1 da auditoria de 06/09/2026) — passava despercebido
        // porque a pilha de fallback é boa.
        //
        // Só no /sw.js: as páginas carregam as fontes por <link>, que é style-src/font-src
        // (já liberados abaixo). Dar connect-src a elas abriria um canal de saída para JS
        // da página sem necessidade nenhuma.
        $fontesDoServiceWorker = $paraServiceWorker
            ? ' https://fonts.googleapis.com https://fonts.gstatic.com'
            : '';

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
            "connect-src {$self}{$fontesDoServiceWorker}{$vite}{$viteWs}",
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
