<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O HTML autenticado que o service worker guarda para abrir offline sai do aparelho quando
 * a sessão acaba — achado P-2 da auditoria de PWA (docs/auditoria-pwa-e-painel-admin-2026-09-06.md).
 *
 * A premissa antiga era que o `Clear-Site-Data: "cache"` do logout limpava o cache do SW.
 * Não limpa: "cache" é o cache HTTP. O Cache Storage (`caches.*`) só cai com "storage", que o
 * app evita de propósito, porque levaria junto o IndexedDB da fila offline. O formulário
 * `/transactions/create` — com as contas, as categorias e a família — continuava no aparelho
 * depois do "Sair", e só o JS da tela de login o apagava, se rodasse.
 *
 * A correção mora no próprio service worker, que enxerga as navegações de todas as abas sem
 * depender do JS da página. O COMPORTAMENTO dele está provado em `tests/js/service-worker.test.js`,
 * que executa o código servido em /sw.js. O que só o lado PHP prova está aqui: que os caminhos
 * em que o SW confia são os das rotas do Laravel, e que o servidor responde do jeito que o SW
 * supõe. Se qualquer um dos dois lados mudar sozinho, a limpeza desliga em SILÊNCIO — nenhuma
 * tela quebra, o formulário simplesmente volta a sobreviver ao logout.
 */
class ServiceWorkerApagaHtmlAutenticadoTest extends TestCase
{
    use RefreshDatabase;

    /** O gatilho 1 do SW é o POST do "Sair": o caminho tem de ser o da rota `logout`. */
    public function test_o_gatilho_do_sair_e_o_caminho_da_rota_de_logout(): void
    {
        $caminho = $this->constante('ROTA_LOGOUT');

        $this->assertSame(route('logout', absolute: false), $caminho);

        // E é mesmo um POST que encerra a sessão — é o método que o SW observa.
        $this->actingAs(User::factory()->create())->post($caminho)->assertRedirect();
        $this->assertGuest();
    }

    /**
     * O gatilho 2 do SW trata "200 nesta página" como prova de que o navegador está sem
     * sessão. Isso só é verdade para página que REDIRECIONA quem tem sessão (grupo `guest`).
     *
     * Uma página pública comum na lista (/termos, digamos) responderia 200 também para o dono
     * logado, e o SW apagaria o formulário offline dele a cada visita — sem erro nenhum, só o
     * lançamento offline parando de abrir.
     */
    public function test_paginas_sem_sessao_so_respondem_200_para_quem_nao_tem_sessao(): void
    {
        $caminhos = $this->lista('ROTAS_SEM_SESSAO');

        // /login é a peça que não pode faltar: é onde termina todo fim de sessão (ver abaixo).
        $this->assertContains(route('login', absolute: false), $caminhos);

        foreach ($caminhos as $caminho) {
            $this->get($caminho)->assertOk();
        }

        $dono = User::factory()->create();

        foreach ($caminhos as $caminho) {
            $status = $this->actingAs($dono)->get($caminho)->getStatusCode();

            $this->assertNotSame(200, $status, "{$caminho} respondeu 200 para quem TEM sessão: o SW apagaria o formulário offline do dono.");
        }
    }

    /**
     * Sessão expirada, conta banida, sessões derrubadas por troca de senha em outro aparelho:
     * nenhum desses casos passa pelo POST do "Sair". O que eles têm em comum é a primeira
     * navegação online a uma tela do app cair no /login — e é lá que o gatilho 2 pega.
     */
    public function test_tela_do_app_sem_sessao_termina_numa_pagina_que_o_sw_reconhece(): void
    {
        $this->assertContains(
            $this->destinoFinal(route('dashboard', absolute: false)),
            $this->lista('ROTAS_SEM_SESSAO'),
        );
    }

    /**
     * O próprio "Sair" também termina lá (/logout → / → /login). Não é redundância à toa: se o
     * gatilho 1 não rodar por qualquer motivo, a mesma cadeia de redirects dispara o 2.
     */
    public function test_a_cadeia_do_sair_termina_numa_pagina_que_o_sw_reconhece(): void
    {
        $saida = $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertGuest();

        $this->assertContains(
            $this->destinoFinal($this->caminhoDe($saida->headers->get('Location'))),
            $this->lista('ROTAS_SEM_SESSAO'),
        );
    }

    /** O formulário que o SW guarda offline é o da rota de criar lançamento — e está na lista que a limpeza apaga. */
    public function test_o_formulario_guardado_offline_esta_na_lista_que_a_limpeza_apaga(): void
    {
        $this->assertContains(route('transactions.create', absolute: false), $this->lista('HTML_AUTENTICADO'));
    }

    /**
     * A correção não pode ter mexido no header: "storage" limparia o Cache Storage, sim, mas
     * apagaria a fila offline e desregistraria o SW (e com ele o Background Sync).
     */
    public function test_o_logout_segue_sem_storage_no_clear_site_data(): void
    {
        $header = (string) $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->headers->get('Clear-Site-Data');

        $this->assertSame('"cache"', $header);
    }

    // ---- Leitura do /sw.js ------------------------------------------------

    private function sw(): string
    {
        return $this->get('/sw.js')->assertOk()->getContent();
    }

    /** `const NOME = '...';` */
    private function constante(string $nome): string
    {
        $achou = preg_match("/const {$nome} = '([^']*)';/", $this->sw(), $m);

        $this->assertSame(1, $achou, "O service worker não declara {$nome}.");

        return $m[1];
    }

    /** `const NOME = ['...', '...'];` */
    private function lista(string $nome): array
    {
        $achou = preg_match("/const {$nome} = \[([^\]]*)\];/", $this->sw(), $m);

        $this->assertSame(1, $achou, "O service worker não declara {$nome}.");

        preg_match_all("/'([^']*)'/", $m[1], $itens);

        $this->assertNotEmpty($itens[1], "{$nome} está vazia no service worker.");

        return $itens[1];
    }

    /**
     * Segue os redirects como o navegador faria, SEM sessão, e devolve o caminho que respondeu 200.
     * Cada salto é uma navegação nova — e cada uma passa pelo service worker.
     */
    private function destinoFinal(string $caminho): string
    {
        for ($saltos = 0; $saltos < 5; $saltos++) {
            $resposta = $this->get($caminho);

            if (! $resposta->isRedirect()) {
                $resposta->assertOk();

                return $caminho;
            }

            $caminho = $this->caminhoDe($resposta->headers->get('Location'));
        }

        $this->fail("Redirects demais a partir de {$caminho}.");
    }

    private function caminhoDe(?string $url): string
    {
        return parse_url((string) $url, PHP_URL_PATH) ?: '/';
    }
}
