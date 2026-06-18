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
            'background_color' => '#FFFFFF',
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
