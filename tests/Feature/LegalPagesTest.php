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

    /**
     * A LGPD exige que o controlador e um canal de contato estejam identificados
     * nos dois documentos — sem isso a política não cumpre o art. 9º/41.
     */
    public function test_legal_pages_identify_controller_and_contact(): void
    {
        foreach (['/termos', '/privacidade'] as $url) {
            $this->get($url)
                ->assertSee('Victor de Melo da Rosa')
                ->assertSee('victor.rosa.faculdade@gmail.com');
        }
    }

    /** A política precisa citar as bases legais, os direitos do titular e a ANPD. */
    public function test_privacy_page_covers_lgpd_essentials(): void
    {
        $this->get('/privacidade')
            ->assertSee('Lei nº 13.709/2018')
            ->assertSee('Execução de contrato')
            ->assertSee('Encarregado')
            ->assertSee('ANPD');
    }

    /** Os termos precisam deixar claro que o app não é banco nem aconselhamento financeiro. */
    public function test_terms_page_carries_key_disclaimers(): void
    {
        $this->get('/termos')
            ->assertSee('não é instituição financeira', false)
            ->assertSee('não é aconselhamento financeiro', false)
            ->assertSee('foro do domicílio do consumidor');
    }

    /**
     * O design system aplica `body { overflow: hidden }` (no app a rolagem mora no
     * `.content`). Sem a classe `legal-body` no body, estes documentos longos ficam
     * cortados no desktop — regressão silenciosa e fácil de reintroduzir.
     */
    public function test_legal_pages_body_can_scroll(): void
    {
        foreach (['/termos', '/privacidade'] as $url) {
            $this->get($url)->assertSee('class="legal-body"', false);
        }
    }

    /** Os dois documentos linkam um para o outro. */
    public function test_legal_pages_cross_link(): void
    {
        $this->get('/termos')->assertSee(route('privacidade'), false);
        $this->get('/privacidade')->assertSee(route('termos'), false);
    }
}
