<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Páginas legais (Termos de Uso / Política de Privacidade) — públicas, exigidas
 * para o aceite no cadastro deixar de ser um link morto (LGPD).
 */
class LegalPagesTest extends TestCase
{
    public function test_terms_page_is_public(): void
    {
        $this->get('/termos')->assertOk()->assertSee('Termos de Uso');
    }

    public function test_privacy_page_is_public(): void
    {
        $this->get('/privacidade')->assertOk()->assertSee('Política de Privacidade');
    }

    public function test_register_links_to_legal_pages_instead_of_dead_anchors(): void
    {
        $response = $this->get('/register');

        $response->assertSee(route('termos'), false);
        $response->assertSee(route('privacidade'), false);
    }
}
