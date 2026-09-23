<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;

/**
 * PWA: serve o manifest, o service worker e a página offline.
 *
 * Tudo aqui é PÚBLICO de propósito (rotas fora do middleware 'auth'):
 * o navegador lê o manifest para oferecer "Instalar" — inclusive na tela
 * de login — e registra/atualiza o service worker por conta própria, às
 * vezes sem sessão ativa. Nenhum destes arquivos expõe dado do usuário:
 * manifest = nome+cores+ícones; SW = código genérico de cache; /offline =
 * aviso "sem conexão". O app em si continua 100% atrás de login.
 *
 * E SEM SESSÃO (22/09/2026): ver o comentário das rotas em routes/web.php.
 */
class PwaController extends Controller
{
    /** Versão quando não há build para medir: `npm run dev` (arquivo `hot`) ou nenhum build ainda. */
    public const VERSAO_SEM_BUILD = 'dev';

    /**
     * A versão do front que está no ar — muda a cada build que gere assets diferentes.
     *
     * É o hash do `public/build/manifest.json` (o mesmo que o Laravel usa para versionar
     * assets): o manifest lista os arquivos com hash no nome, então CSS ou JS novo ⇒
     * manifest novo ⇒ versão nova. FONTE ÚNICA das duas pontas que precisam concordar:
     *
     *  - o service worker, que a leva no próprio código (`const VERSAO`). O navegador só
     *    instala SW novo quando os BYTES do /sw.js mudam, e com a versão fixa
     *    (`sm-cache-v2`) um deploy não mudava byte nenhum: a aba aberta seguia com o CSS
     *    velho e o cache do /build crescia para sempre (P-6 da auditoria de 06/09/2026);
     *  - a meta `sm-versao` do layouts/app, que o pjax e o `sm/pwa.js` comparam para
     *    saber se a página aberta ficou para trás.
     *
     * Cortado em 16 caracteres só para caber legível no nome do cache e no HTML.
     */
    public static function versaoDoBuild(): string
    {
        $hash = Vite::manifestHash();

        return $hash ? substr($hash, 0, 16) : self::VERSAO_SEM_BUILD;
    }

    /**
     * Web App Manifest — descreve o app para a instalação (nome, ícones,
     * cores, modo standalone). Servido como application/manifest+json.
     */
    public function manifest(): Response
    {
        $manifest = [
            'name' => 'Stabil Money',
            'short_name' => 'StabilMoney',
            'description' => 'Controle financeiro pessoal e da família.',
            'lang' => 'pt-BR',
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            // Fundo da splash do Android (a tela cheia que aparece entre tocar no
            // ícone e o primeiro frame do app). O manifest é lido ANTES de o app
            // abrir — sem localStorage e sem cookie —, então esta cor NÃO tem como
            // seguir o tema escolhido: é uma aposta única para os dois temas.
            //
            // Era #FFFFFF, o pior valor possível: a cada abertura fria o Android
            // pintava a tela INTEIRA de branco, um clarão para quem usa o tema
            // escuro. #0C3D2B é exatamente a cor de fundo dos PNGs de ícone
            // (medida no pixel), então o quadrado do ícone se dissolve na splash e
            // ela vira uma abertura de marca: escura o bastante para não ofuscar no
            // tema escuro e coerente com a sidebar (verde em qualquer tema) no claro.
            'background_color' => '#0C3D2B',
            // Barra de status durante a splash/instalação. Igual ao background_color
            // de propósito — splash sem emenda. Depois que a página carrega, quem
            // manda é a meta `theme-color` do layout, que aí sim segue o tema.
            'theme_color' => '#0C3D2B',
            'icons' => [
                [
                    'src' => '/assets/icons/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/assets/icons/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/assets/icons/icon-maskable-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
        ];

        return response(
            json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            200,
            ['Content-Type' => 'application/manifest+json'],
        );
    }

    /**
     * Service worker — servido na raiz (escopo "/") para controlar o app
     * inteiro. Estratégia conservadora: só intercepta GET, cache-first nos
     * assets estáticos, network-first nas navegações com fallback /offline.
     *
     * É também ele quem apaga o HTML autenticado guardado offline quando a
     * sessão acaba — o `Clear-Site-Data: "cache"` do logout não alcança o
     * Cache Storage. Ver `HTML_AUTENTICADO` na view.
     *
     * A versão do build entra pela PRIMEIRA linha do script (a única fora do
     * `@verbatim` da view): é ela que faz o navegador enxergar um SW novo a cada
     * deploy. Ver `versaoDoBuild()`.
     */
    public function serviceWorker(): Response
    {
        return response(view('pwa.service-worker', ['versao' => self::versaoDoBuild()])->render(), 200, [
            'Content-Type' => 'application/javascript',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
