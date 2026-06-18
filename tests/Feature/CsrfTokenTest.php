<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rota /csrf-token: devolve um token CSRF fresco da sessão atual.
 *
 * Usada pelo submit AJAX do lançamento para se recuperar de um 419 — quando o
 * form foi aberto OFFLINE (servido do cache do service worker, com _token velho)
 * e enviado depois que a conexão voltou. Fica atrás de 'auth' (GET não precisa
 * de proteção CSRF) e só expõe o token da própria sessão.
 */
class CsrfTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_gets_fresh_csrf_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/csrf-token');

        $response->assertOk();
        $response->assertJsonStructure(['token']);

        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/csrf-token');

        $response->assertRedirect(route('login'));
    }
}
