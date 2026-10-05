<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Visitante não logado: a raiz é a página inicial pública (out/2026), e as telas do app
     * continuam exigindo login (desde a Fase 1).
     */
    public function test_guests_see_the_home_page_and_app_screens_ask_for_login(): void
    {
        $this->get('/')->assertOk()->assertSee('Criar conta grátis');

        $this->get(route('transactions.index'))->assertRedirect(route('login'));
    }
}
