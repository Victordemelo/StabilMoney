<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PWA: manifest, service worker e página offline são servidos por rota
 * Laravel (testáveis) e são PÚBLICOS (sem login) — o navegador precisa
 * lê-los para oferecer "Instalar", inclusive na tela de login. Nenhum
 * deles expõe dado do usuário; o app continua atrás de auth.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_served_with_required_fields(): void
    {
        $response = $this->get('/site.webmanifest');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/manifest+json');

        $manifest = $response->json();

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertNotEmpty($manifest['name']);
        $this->assertNotEmpty($manifest['short_name']);

        // Instalabilidade exige ícones 192 e 512, mais um maskable para Android.
        $sizes = array_column($manifest['icons'], 'sizes');
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);

        $purposes = array_column($manifest['icons'], 'purpose');
        $this->assertContains('maskable', $purposes);
    }

    public function test_manifest_is_public(): void
    {
        // Sem actingAs: um visitante não logado precisa conseguir ler o manifest.
        $this->get('/site.webmanifest')->assertOk();
    }

    public function test_service_worker_is_served_as_javascript(): void
    {
        $response = $this->get('/sw.js');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript');

        // Versão de cache (para limpar caches antigos) e fallback offline.
        $response->assertSee('sm-cache-', false);
        $response->assertSee('/offline', false);
    }

    public function test_service_worker_is_public(): void
    {
        $this->get('/sw.js')->assertOk();
    }

    public function test_offline_page_is_served(): void
    {
        $response = $this->get('/offline');

        $response->assertOk();
        $response->assertSee('Você está offline');
    }

    public function test_offline_page_is_public(): void
    {
        $this->get('/offline')->assertOk();
    }

    public function test_pwa_icons_exist(): void
    {
        // Os ícones referenciados pelo manifest precisam existir de fato no disco.
        foreach ([
            'icon-192.png',
            'icon-512.png',
            'icon-maskable-512.png',
            'apple-touch-icon.png',
        ] as $file) {
            $this->assertFileExists(public_path('assets/icons/' . $file));
        }
    }

    public function test_manifest_link_in_app_layout(): void
    {
        // Layout do app (autenticado) — instalável de dentro do app.
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertSee('rel="manifest"', false);
    }

    public function test_manifest_link_in_auth_layout(): void
    {
        // Layout de login/cadastro (split com vídeo) — instalável da tela de login.
        $this->get('/login')->assertSee('rel="manifest"', false);
    }

    public function test_manifest_link_in_guest_layout(): void
    {
        // Layout guest (demais telas de auth).
        $this->get('/forgot-password')->assertSee('rel="manifest"', false);
    }

    public function test_app_layout_has_apple_pwa_meta(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertSee('apple-mobile-web-app-capable', false);
    }
}
