<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Visitante não logado é redirecionado do dashboard para o login
     * (todas as telas do app exigem autenticação desde a Fase 1).
     */
    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
