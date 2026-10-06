<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rodapé de autoria em toda tela do app (out/2026 — pedido do Victor: "Desenvolvido por
 * Victor de Melo da Rosa", a logo e o © 2026).
 */
class RodapeDeAutoriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_toda_tela_do_app_termina_com_a_assinatura(): void
    {
        $user = User::factory()->create();

        foreach (['dashboard', 'transactions.index', 'accounts.index', 'categories.index', 'faturas.index', 'metas.index', 'investimentos.index', 'settings', 'sistema'] as $rota) {
            $html = $this->actingAs($user)->get(route($rota))->assertOk()->getContent();

            $this->assertStringContainsString('<footer class="rodape-autoria"', $html, "sem rodapé em {$rota}");
            $this->assertStringContainsString('href="'.config('sistema.autor.site').'"', $html);
            $this->assertStringContainsString(config('sistema.autor.nome'), $html);
            $this->assertStringContainsString('© 2026', $html);
            $this->assertStringContainsString('assets/stabilmoney-mark.png', $html);
            // Enxuto (pedido do Victor): sem a fileira de links, que deixava o rodapé grande.
            $this->assertStringNotContainsString('ra-links', $html);
            // Dentro do #content: rola com a tela e o pjax o traz junto.
            $this->assertLessThan(strpos($html, '</main>'), strpos($html, '<footer class="rodape-autoria"'));
        }
    }

    public function test_o_ano_vira_intervalo_depois_de_2026(): void
    {
        $this->travelTo(now()->setDate(2028, 3, 1));

        $this->actingAs(User::factory()->create())->get(route('transactions.index'))->assertOk()
            ->assertSee('© 2026–2028');
    }

    public function test_paginas_fora_do_app_nao_ganham_o_rodape_do_app(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('rodape-autoria', false);
    }
}
