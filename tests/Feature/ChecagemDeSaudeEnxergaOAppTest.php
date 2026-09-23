<?php

namespace Tests\Feature;

use App\Http\Controllers\SaudeController;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /up — a checagem de saúde que o monitor da VPS vai consultar — enxerga o app de
 * verdade e não carrega nada de fora (item 6 da rodada de pré-publicação).
 *
 * A rota `health: '/up'` do framework tinha quatro defeitos para esse papel:
 *  - servia HTML com um script de CDN de TERCEIRO (cdn.jsdelivr.net) na origem do app;
 *  - respondia 200 com o banco fora do ar (ninguém escutava `DiagnosingHealth`);
 *  - respondia 200 em modo de manutenção (o framework a exclui da manutenção);
 *  - saía sem cabeçalho de segurança nenhum.
 *
 * ⚠️ Manutenção aqui é simulada com o driver `cache` num store `array`, NUNCA com o
 * arquivo `storage/framework/down`: a pasta é a mesma do app rodando e dos outros testes,
 * que passariam todos a receber 503.
 */
class ChecagemDeSaudeEnxergaOAppTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $registros = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Nada vai para o arquivo de log de verdade; cada registro fica capturado aqui.
        config(['logging.default' => 'null']);
        Log::listen(function (MessageLogged $registro) {
            $this->registros[] = $registro;
        });
    }

    private function assertRespostaCurtaSemHtml(TestResponse $resposta, string $corpo): void
    {
        $this->assertSame($corpo, $resposta->getContent());
        $this->assertStringStartsWith('text/plain', (string) $resposta->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
    }

    // ── O app no ar ───────────────────────────────────────────────────────────

    public function test_app_no_ar_responde_ok_em_texto_curto_sem_nada_de_fora(): void
    {
        $resposta = $this->get('/up')->assertOk();

        $this->assertRespostaCurtaSemHtml($resposta, SaudeController::OK);
        $this->assertStringNotContainsString('<', $resposta->getContent());
        $this->assertStringNotContainsString('jsdelivr', $resposta->getContent());
    }

    public function test_sai_com_os_cabecalhos_de_seguranca(): void
    {
        $this->get('/up')
            ->assertHeader('Content-Security-Policy', SecurityHeaders::CSP_SEM_SCRIPT)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()');
    }

    /** Monitor costuma usar HEAD: mesma resposta, sem corpo. */
    public function test_head_tambem_responde(): void
    {
        $this->call('HEAD', '/up')->assertOk();
    }

    /**
     * Com o monitor batendo a cada minuto, cada checagem que abrisse sessão seria uma
     * linha nova na tabela `sessions` — 1.440 por dia.
     */
    public function test_nao_abre_sessao_nem_grava_cookie(): void
    {
        config(['session.driver' => 'database']);

        $resposta = $this->get('/up')->assertOk();

        $this->assertSame([], $resposta->headers->getCookies(), 'O /up gravou cookie.');
        $this->assertDatabaseCount('sessions', 0);

        // Prova de que o teste enxerga o problema: uma tela do grupo `web` abre sessão.
        $this->get('/login')->assertOk();
        $this->assertDatabaseCount('sessions', 1);
    }

    // ── O app com problema ────────────────────────────────────────────────────

    public function test_banco_fora_do_ar_responde_503_sem_detalhe_interno(): void
    {
        $padrao = config('database.default');
        config([
            'database.connections.quebrada' => [
                'driver' => 'sqlite',
                'database' => '/caminho/que/nao/existe/banco.sqlite',
                'prefix' => '',
            ],
            'database.default' => 'quebrada',
        ]);

        try {
            $resposta = $this->get('/up')->assertStatus(503);
        } finally {
            // O RefreshDatabase desfaz a transação na conexão padrão ao terminar.
            config(['database.default' => $padrao]);
        }

        $this->assertRespostaCurtaSemHtml($resposta, SaudeController::INDISPONIVEL);
        $resposta->assertHeader('X-Content-Type-Options', 'nosniff');

        // O motivo vai para o log do servidor, não para a URL pública.
        $erros = array_values(array_filter($this->registros, fn (MessageLogged $r) => $r->level === 'error'));
        $this->assertCount(1, $erros);
        $this->assertStringContainsString('/caminho/que/nao/existe/banco.sqlite', $erros[0]->context['erro']);
    }

    /**
     * Log que não grava derruba requisição: o Monolog LANÇA ao não conseguir abrir o
     * arquivo. E o canal diário cria um arquivo novo por dia — é a PASTA que conta.
     */
    public function test_pasta_do_log_sem_escrita_responde_503(): void
    {
        config([
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['daily'],
            'logging.channels.daily.path' => '/caminho/que/nao/existe/laravel.log',
        ]);

        $this->assertRespostaCurtaSemHtml($this->get('/up')->assertStatus(503), SaudeController::INDISPONIVEL);
    }

    /** Log em `stderr` não tem pasta: nada a conferir, e nada de alarme falso. */
    public function test_log_sem_arquivo_nao_conta_como_problema(): void
    {
        config(['logging.default' => 'stderr']);

        $this->get('/up')->assertOk();
    }

    public function test_em_manutencao_responde_503_em_texto(): void
    {
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate(['retry' => 60, 'status' => 503]);

        $this->assertRespostaCurtaSemHtml($this->get('/up')->assertStatus(503), SaudeController::EM_MANUTENCAO);

        // O resto do app também está fora — é isso que o 503 do /up avisa.
        $this->get('/login')->assertStatus(503);
    }
}
