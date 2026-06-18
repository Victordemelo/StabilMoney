<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rotas removidas do design v2 (relatórios, ajuda) não existem mais.
 *
 * Observação: não há mais páginas "em breve" (coming-soon) — a última,
 * /faturas, virou a tela real "Faturas / Despesas". Investimentos e Metas
 * também já são telas reais.
 */
class ComingSoonTest extends TestCase
{
    use RefreshDatabase;

    public function test_removed_routes_no_longer_exist(): void
    {
        $user = User::factory()->create();

        // Relatórios e Ajuda saíram do menu (design v2) e das rotas
        $this->actingAs($user)->get('/relatorios')->assertNotFound();
        $this->actingAs($user)->get('/ajuda')->assertNotFound();
    }
}
