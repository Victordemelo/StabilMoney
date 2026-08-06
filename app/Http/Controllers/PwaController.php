<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * PWA: serve o manifest, o service worker e a página offline.
 *
 * Tudo aqui é PÚBLICO de propósito (rotas fora do middleware 'auth'):
 * o navegador lê o manifest para oferecer "Instalar" — inclusive na tela
 * de login — e registra/atualiza o service worker por conta própria, às
 * vezes sem sessão ativa. Nenhum destes arquivos expõe dado do usuário:
 * manifest = nome+cores+ícones; SW = código genérico de cache; /offline =
 * aviso "sem conexão". O app em si continua 100% atrás de login.
 */
class PwaController extends Controller
{
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
     */
    public function serviceWorker(): Response
    {
        return response(view('pwa.service-worker')->render(), 200, [
            'Content-Type' => 'application/javascript',
            'Service-Worker-Allowed' => '/',
        ]);
    }
}
