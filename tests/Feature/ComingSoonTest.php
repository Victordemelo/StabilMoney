<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Placeholders "em breve" do shell v2: cada rota renderiza o coming-soon
 * com o título certo; rotas removidas do design v2 (relatórios, ajuda)
 * não existem mais.
 */
class ComingSoonTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function comingSoonPages(): array
    {
        return [
            'investimentos' => ['/investimentos', 'Investimentos'],
            'metas' => ['/metas', 'Metas'],
            'faturas / despesas' => ['/faturas', 'Faturas / Despesas'],
        ];
    }

    #[DataProvider('comingSoonPages')]
    public function test_coming_soon_page_renders_with_title(string $uri, string $title): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get($uri);

        $response->assertOk();
        $response->assertSee($title);
        $response->assertSee('Voltar ao início');
    }

    public function test_removed_routes_no_longer_exist(): void
    {
        $user = User::factory()->create();

        // Relatórios e Ajuda saíram do menu (design v2) e das rotas
        $this->actingAs($user)->get('/relatorios')->assertNotFound();
        $this->actingAs($user)->get('/ajuda')->assertNotFound();
    }
}
