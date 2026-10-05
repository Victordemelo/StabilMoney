<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Todas as telas do app exigem login (Fase 1): visitante não autenticado
 * deve ser redirecionado para /login em qualquer rota protegida.
 */
class GuestAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function protectedRoutes(): array
    {
        return [
            // A raiz ('/') saiu da lista em out/2026: para quem não entrou ela é a página
            // inicial pública (PaginaInicialPublicaTest).
            'transações' => ['/transactions'],
            'contas' => ['/accounts'],
            'categorias' => ['/categories'],
            'configurações' => ['/configuracoes'],
            // Placeholders "em breve" (shell v2) também exigem login
            'faturas / despesas' => ['/faturas'],
            'metas' => ['/metas'],
            'investimentos' => ['/investimentos'],
            'dependentes' => ['/dependentes'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_guests_are_redirected_to_login(string $uri): void
    {
        $response = $this->get($uri);

        $response->assertRedirect(route('login'));
    }
}
