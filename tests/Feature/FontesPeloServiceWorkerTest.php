<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * O service worker consegue buscar as fontes do Google (P-1 da auditoria de 06/09/2026).
 *
 * O defeito: um service worker obedece à CSP da PRÓPRIA resposta do /sw.js, e cada
 * `fetch()` que ele faz conta como connect-src. O SW repassa e guarda as fontes do Google
 * (para o app manter a tipografia offline), mas a CSP liberava só `'self'` em connect-src:
 * o fetch era bloqueado, o SW devolvia erro de rede e todo o app pós-login caía na fonte
 * do sistema assim que o SW assumia a página. Reproduzido num Chromium de verdade.
 *
 * A correção é estreita de propósito: os dois hosts entram no connect-src SÓ da resposta
 * do /sw.js. As páginas carregam as fontes por <link> (style-src/font-src) e não precisam
 * de um canal de saída a mais para o JavaScript delas.
 */
class FontesPeloServiceWorkerTest extends TestCase
{
    /** @return array<string, list<string>> diretiva => fontes */
    private function diretivas(string $url): array
    {
        $csp = (string) $this->get($url)->assertOk()->headers->get('Content-Security-Policy');
        $this->assertNotSame('', $csp, "{$url} saiu sem CSP.");

        $mapa = [];
        foreach (array_filter(array_map('trim', explode(';', $csp))) as $diretiva) {
            $partes = preg_split('/\s+/', $diretiva);
            $mapa[array_shift($partes)] = $partes;
        }

        return $mapa;
    }

    /**
     * Hosts externos que o código do SW busca.
     *
     * Lidos do próprio script servido, e não de uma lista escrita aqui: se o SW passar a
     * buscar um host novo e ninguém lembrar da CSP, este teste é quem avisa.
     *
     * @return list<string>
     */
    private function hostsExternosDoServiceWorker(): array
    {
        $codigo = $this->get('/sw.js')->assertOk()->getContent();

        preg_match_all('/\b((?:[a-z0-9-]+\.)+(?:com|net|org|io|br))\b/i', $codigo, $achados);

        return array_values(array_unique(array_map('strtolower', $achados[1])));
    }

    public function test_o_service_worker_referencia_as_fontes_do_google(): void
    {
        // A exceção na CSP só existe porque o SW guarda as fontes. Se um dia ele deixar
        // de fazer isso, a exceção deve sair junto — e este teste falha para lembrar.
        $hosts = $this->hostsExternosDoServiceWorker();

        $this->assertContains('fonts.googleapis.com', $hosts);
        $this->assertContains('fonts.gstatic.com', $hosts);
    }

    public function test_todo_host_externo_do_service_worker_esta_liberado_no_connect_src_dele(): void
    {
        $connect = $this->diretivas('/sw.js')['connect-src'] ?? [];

        foreach ($this->hostsExternosDoServiceWorker() as $host) {
            $this->assertContains(
                "https://{$host}",
                $connect,
                "O SW busca {$host}, mas o connect-src do /sw.js não libera: o fetch seria bloqueado em silêncio."
            );
        }
    }

    public function test_as_paginas_nao_ganham_os_hosts_de_fonte_no_connect_src(): void
    {
        foreach (['/login', '/register', '/termos'] as $pagina) {
            $connect = $this->diretivas($pagina)['connect-src'] ?? [];

            $this->assertNotContains('https://fonts.googleapis.com', $connect, "{$pagina} ganhou connect-src a mais.");
            $this->assertNotContains('https://fonts.gstatic.com', $connect, "{$pagina} ganhou connect-src a mais.");
        }
    }

    public function test_as_paginas_continuam_carregando_as_fontes_pelo_link(): void
    {
        // O caminho das páginas não mudou: <link> para o googleapis (style-src) e os
        // arquivos do gstatic (font-src).
        $diretivas = $this->diretivas('/login');

        $this->assertContains('https://fonts.googleapis.com', $diretivas['style-src'] ?? []);
        $this->assertContains('https://fonts.gstatic.com', $diretivas['font-src'] ?? []);
    }

    public function test_fora_o_connect_src_a_csp_do_service_worker_e_a_mesma_das_paginas(): void
    {
        // A exceção é UMA diretiva. Nenhuma outra política pode ficar mais frouxa no SW.
        $sw = $this->diretivas('/sw.js');
        $pagina = $this->diretivas('/login');

        unset($sw['connect-src'], $pagina['connect-src'], $sw['script-src'], $pagina['script-src']);

        // script-src difere só pelo nonce, que é sorteado a cada resposta.
        $this->assertSame($pagina, $sw);
    }

    public function test_o_nonce_continua_valendo_no_service_worker(): void
    {
        $scriptSrc = $this->diretivas('/sw.js')['script-src'] ?? [];

        $this->assertNotContains("'unsafe-inline'", $scriptSrc);
        $this->assertNotEmpty(array_filter($scriptSrc, fn (string $f) => str_starts_with($f, "'nonce-")));
    }
}
