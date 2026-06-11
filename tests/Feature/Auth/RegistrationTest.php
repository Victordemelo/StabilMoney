<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'terms' => '1',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * O aceite dos Termos de Uso é obrigatório no cadastro (design v2).
     */
    public function test_registration_requires_terms_acceptance(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('terms');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    /**
     * Mesma regra via JSON: sem terms o servidor responde 422
     * (Unprocessable Entity) com o erro de validação no campo terms.
     */
    public function test_registration_without_terms_fails_with_422_on_json(): void
    {
        $response = $this->postJson('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('terms');
        $this->assertGuest();
    }

    /**
     * O design v2 removeu o campo de confirmação de senha do cadastro:
     * registrar sem password_confirmation deve funcionar normalmente.
     */
    public function test_registration_does_not_require_password_confirmation(): void
    {
        $response = $this->post('/register', [
            'name' => 'Sem Confirmação',
            'email' => 'sem-confirmacao@example.com',
            'password' => 'password',
            'terms' => '1',
            // sem password_confirmation de propósito
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'sem-confirmacao@example.com']);
    }

    /**
     * O listener do evento Registered (SeedDefaultCategoriesForNewUser)
     * cria as categorias padrão de receita e despesa para o usuário novo.
     */
    public function test_registration_seeds_default_categories(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'terms' => '1',
        ]);

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertGreaterThan(0, $user->categories()->where('type', 'income')->count());
        $this->assertGreaterThan(0, $user->categories()->where('type', 'expense')->count());
    }
}
