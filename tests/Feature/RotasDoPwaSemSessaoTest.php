<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * As três rotas do PWA não abrem sessão nem gravam cookie (observação da auditoria de PWA
 * de 06/09/2026).
 *
 * Ninguém clica nelas: o navegador as busca sozinho — o /sw.js a cada checagem de versão
 * (e o `sm/pwa.js` agora pede uma a cada volta do app para a frente), o manifest quando
 * ele avalia a instalação do app, o /offline no precache do service worker. No grupo `web`
 * inteiro, cada busca abria sessão: uma linha em `sessions` (com IP e user-agent) e um
 * Set-Cookie, de um "visitante" que nunca faz nada.
 *
 * O `SecurityHeaders` continua nelas — a CSP especial do /sw.js e o nonce da página
 * offline dependem dele (FontesPeloServiceWorkerTest e CspComNonceTest cobrem o conteúdo;
 * aqui se confere que ele não saiu junto com a sessão).
 */
class RotasDoPwaSemSessaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O driver dos testes é `array`, que não deixa rastro no banco. Com `database`, cada
        // sessão aberta é uma linha em `sessions` — é o que a produção sofre.
        config(['session.driver' => 'database']);
    }

    /** @return array<string, array{string}> */
    public static function rotasDoPwa(): array
    {
        return [
            'service worker' => ['/sw.js'],
            'manifest' => ['/site.webmanifest'],
            'página offline' => ['/offline'],
        ];
    }

    #[DataProvider('rotasDoPwa')]
    public function test_nao_abre_sessao_nem_grava_cookie(string $rota): void
    {
        $resposta = $this->get($rota)->assertOk();

        $this->assertSame([], $resposta->headers->getCookies(), "{$rota} gravou cookie.");
        $this->assertDatabaseCount('sessions', 0);
    }

    #[DataProvider('rotasDoPwa')]
    public function test_quem_ja_tem_sessao_tambem_nao_ganha_cookie_novo(string $rota): void
    {
        // O navegador de quem está logado manda o cookie da sessão junto. A rota não o lê,
        // não o renova e não cria outra sessão por causa dele.
        $resposta = $this->withCookie(config('session.cookie'), 'sessao-de-quem-esta-logado')
            ->get($rota)
            ->assertOk();

        $this->assertSame([], $resposta->headers->getCookies(), "{$rota} regravou o cookie da sessão.");
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_o_teste_enxerga_o_problema_uma_tela_do_grupo_web_abre_sessao(): void
    {
        // Controle: sem ele, "nenhuma linha em sessions" passaria também com o driver
        // errado ou com a sessão desligada no teste inteiro.
        $resposta = $this->get('/login')->assertOk();

        $this->assertNotSame([], $resposta->headers->getCookies());
        $this->assertDatabaseCount('sessions', 1);
    }

    #[DataProvider('rotasDoPwa')]
    public function test_os_cabecalhos_de_seguranca_continuam(string $rota): void
    {
        $resposta = $this->get($rota)->assertOk();

        $this->assertStringContainsString("'nonce-", (string) $resposta->headers->get('Content-Security-Policy'));
        $this->assertNotEmpty($resposta->headers->get('X-Csp-Nonce'));
        $resposta->assertHeader('X-Content-Type-Options', 'nosniff');
        $resposta->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_a_csp_do_service_worker_continua_liberando_as_fontes(): void
    {
        // Era por causa dela que estas rotas não podiam simplesmente sair do grupo `web`.
        $csp = (string) $this->get('/sw.js')->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression('/connect-src [^;]*https:\/\/fonts\.gstatic\.com/', $csp);
    }

    public function test_a_pagina_offline_carimba_o_nonce_que_a_csp_dela_declara(): void
    {
        $resposta = $this->get('/offline')->assertOk();
        $nonce = $resposta->headers->get('X-Csp-Nonce');

        $this->assertStringContainsString('<script nonce="'.$nonce.'">', $resposta->getContent());
    }
}
