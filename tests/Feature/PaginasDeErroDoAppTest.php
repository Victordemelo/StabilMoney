<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * As páginas de erro são do app: PT-BR, com a marca, texto que explica e caminho de volta
 * — e continuam de pé quando o resto quebrou (item 22 da rodada de pré-publicação).
 *
 * O defeito: não existia `resources/views/errors/`. 404, 419, 429, 500 e 503 caíam na
 * página mínima do framework (sem a cara do app, sem explicação, sem link de volta), o
 * 429 não dizia quanto esperar, o 405 caía na página do Symfony, em inglês — e boa parte
 * dos erros saía SEM cabeçalho de segurança: os que nascem fora do grupo `web` (roteador,
 * manutenção) e os que nascem nele antes do `SecurityHeaders` (CSRF, limite, binding).
 *
 * Os requisitos que os testes travam, na ordem do enunciado:
 *  (a) funciona com o app quebrado — sem Vite, sem banco, zero `<script>`;
 *  (b) não varia por caminho nem por quem está logado (o 404 do painel desligado);
 *  (c) nunca mostra a mensagem da exceção;
 *  (d) requisição JSON continua recebendo JSON;
 *  (e) toda resposta de erro sai com os cabeçalhos, sem duplicar a CSP com nonce.
 *
 * Sempre com `app.debug` DESLIGADO: é assim em produção, e com ele ligado o 500 é a
 * página de depuração do framework, não a nossa.
 */
class PaginasDeErroDoAppTest extends TestCase
{
    use RefreshDatabase;

    private const SEGREDO = 'segredo-interno-que-nao-pode-vazar';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        // Os 500 daqui são de propósito: o relatório deles não vai para o log de verdade.
        config(['logging.default' => 'null']);

        // Rotas de teste: um erro de cada jeito, dentro e fora do grupo `web`.
        Route::get('/_teste/abortar/{codigo}', fn (string $codigo) => abort((int) $codigo, self::SEGREDO));
        Route::get('/_teste/explodir', fn () => throw new RuntimeException(self::SEGREDO.' SQLSTATE[HY000]'));
        Route::post('/_teste/so-post', fn () => 'ok');
        Route::middleware('web')->group(function () {
            Route::get('/_teste/web/proibido', fn () => abort(403, self::SEGREDO));
            Route::get('/_teste/web/explodir', fn () => throw new RuntimeException(self::SEGREDO));
            Route::get('/_teste/web/limite', fn () => 'ok')->middleware('throttle:1,1');
        });
    }

    // ── Apoio ────────────────────────────────────────────────────────────────

    /** A página é do app: marca, sem script, sem nada de fora, com link de volta. */
    private function assertPaginaDoApp(TestResponse $resposta, int $status, string $titulo): void
    {
        $resposta->assertStatus($status);
        $this->assertStringStartsWith('text/html', (string) $resposta->headers->get('Content-Type'));

        $html = (string) $resposta->getContent();
        $this->assertStringContainsString('<html lang="pt-BR">', $html);
        $this->assertStringContainsString('Stabil<b>Money</b>', $html);
        $this->assertStringContainsString($titulo, $html);
        $this->assertStringContainsString('href="'.url('/').'"', $html, 'Sem caminho de volta.');

        // (a) Nada que dependa de script, do build ou de terceiro.
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('/build/', $html);
        $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://(?!localhost)#', $html, 'A página carrega algo de fora.');

        // (c) Nunca a mensagem da exceção nem pedaço de stack trace.
        $this->assertStringNotContainsString(self::SEGREDO, $html);
        $this->assertStringNotContainsString('SQLSTATE', $html);
        $this->assertStringNotContainsString('Stack trace', $html);
        $this->assertStringNotContainsString('.php', $html);

        $this->assertCabecalhosDeSeguranca($resposta);
    }

    /** (e) Os cabeçalhos de segurança, com UMA CSP só. */
    private function assertCabecalhosDeSeguranca(TestResponse $resposta): void
    {
        $this->assertCount(1, $resposta->headers->all('content-security-policy'), 'CSP ausente ou duplicada.');
        $resposta->assertHeader('X-Content-Type-Options', 'nosniff');
        $resposta->assertHeader('X-Frame-Options', 'DENY');
        $resposta->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $resposta->assertHeader('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()');
    }

    /**
     * Devolve ao CSRF o comportamento de produção — o Laravel o pula em teste, e sem isto
     * o 419 nunca aconteceria aqui. (Mesma técnica do PainelAdminDesligadoNaoSeRevelaTest.)
     */
    private function exigirCsrfDeVerdade(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function emManutencao(int $retry): void
    {
        // Driver `cache` num store `array`: NUNCA o arquivo storage/framework/down, que é
        // compartilhado com o app rodando e com os outros testes.
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate(['retry' => $retry, 'status' => 503]);
    }

    private function bancoForaDoAr(): string
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

        return $padrao;
    }

    // ── Cada código, com a cara do app ───────────────────────────────────────

    public function test_404_do_roteador(): void
    {
        $this->assertPaginaDoApp($this->get('/nao_existe'), 404, 'Não encontramos esta página');
    }

    /** Model binding: nasce DENTRO do grupo `web`, mas antes do SecurityHeaders. */
    public function test_404_do_model_binding(): void
    {
        $this->assertPaginaDoApp(
            $this->actingAs(User::factory()->create())->get('/transactions/999999/edit'),
            404,
            'Não encontramos esta página',
        );
    }

    public function test_403_nao_mostra_a_mensagem_do_abort(): void
    {
        $this->assertPaginaDoApp($this->get('/_teste/web/proibido'), 403, 'Você não tem acesso a esta página');
    }

    /** Antes caía na página do Symfony, em inglês. */
    public function test_405_do_roteador(): void
    {
        $resposta = $this->get('/_teste/so-post');

        $this->assertPaginaDoApp($resposta, 405, 'Este endereço não abre direto no navegador');
        $resposta->assertHeader('Allow', 'POST');
    }

    public function test_413_do_post_grande_demais(): void
    {
        $limite = ini_parse_quantity((string) ini_get('post_max_size'));
        if ($limite <= 0) {
            $this->markTestSkipped('Sem post_max_size, não há 413.');
        }

        $this->assertPaginaDoApp(
            $this->call('POST', '/login', [], [], [], ['CONTENT_LENGTH' => (string) ($limite + 1)]),
            413,
            'O envio passou do tamanho permitido',
        );
    }

    /** O erro mais comum do uso real: formulário aberto tempo demais. */
    public function test_419_diz_que_nada_foi_salvo(): void
    {
        $this->exigirCsrfDeVerdade();

        $resposta = $this->post('/login', ['email' => 'a@b.c', 'password' => 'x']);

        $this->assertPaginaDoApp($resposta, 419, 'Esta página ficou aberta tempo demais');
        $resposta->assertSee('nada foi salvo');
    }

    /** O 429 diz QUANTO esperar — vem do `Retry-After` do limite de tentativas. */
    public function test_429_diz_quanto_esperar(): void
    {
        $this->freezeTime();

        $this->get('/_teste/web/limite')->assertOk();
        $resposta = $this->get('/_teste/web/limite');

        $this->assertPaginaDoApp($resposta, 429, 'Muitas tentativas seguidas');
        $resposta->assertHeader('Retry-After', '60');
        $resposta->assertSee('Tente de novo em <strong>1 minuto</strong>', false);
    }

    public function test_500_fora_e_dentro_do_grupo_web(): void
    {
        $this->assertPaginaDoApp($this->get('/_teste/explodir'), 500, 'Algo deu errado do nosso lado');
        $this->assertPaginaDoApp($this->get('/_teste/web/explodir'), 500, 'Algo deu errado do nosso lado');
    }

    public function test_503_da_manutencao_diz_quanto_esperar(): void
    {
        $this->emManutencao(retry: 60);

        $resposta = $this->get('/login');

        $this->assertPaginaDoApp($resposta, 503, 'Voltamos em instantes');
        $resposta->assertHeader('Retry-After', '60');
        $resposta->assertSee('Tente de novo em <strong>1 minuto</strong>', false);
    }

    /** Todo código que o app pode devolver cai numa página do app, com ou sem página própria. */
    #[DataProvider('codigosDeErro')]
    public function test_todo_codigo_tem_pagina_do_app(int $codigo): void
    {
        $resposta = $this->get('/_teste/abortar/'.$codigo);

        $this->assertPaginaDoApp($resposta, $codigo, 'Erro '.$codigo);
    }

    public static function codigosDeErro(): array
    {
        $codigos = [400, 401, 402, 403, 404, 408, 410, 414, 422, 500, 502, 503, 504];

        return array_combine(
            array_map(fn (int $codigo) => 'status '.$codigo, $codigos),
            array_map(fn (int $codigo) => [$codigo], $codigos),
        );
    }

    // ── (a) De pé com o app quebrado ──────────────────────────────────────────

    /**
     * Sem o build do Vite a tela comum quebra (o `@vite` lança) — e a página de erro que
     * o usuário vê nesse momento continua de pé, porque não depende dele.
     */
    public function test_renderiza_sem_o_build_do_vite(): void
    {
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null)
            {
                throw new RuntimeException('O build do Vite não existe.');
            }
        });

        $this->assertPaginaDoApp($this->get('/login'), 500, 'Algo deu errado do nosso lado');
        $this->assertPaginaDoApp($this->get('/nao_existe'), 404, 'Não encontramos esta página');
    }

    /** O 500 de "banco fora do ar" não pode precisar do banco para ser desenhado. */
    public function test_renderiza_com_o_banco_fora_do_ar(): void
    {
        config(['session.driver' => 'database']);
        $padrao = $this->bancoForaDoAr();

        try {
            // A sessão em banco é lida no começo de toda tela: é ela que explode.
            $telaDoApp = $this->get('/login');
            $inexistente = $this->get('/nao_existe');
        } finally {
            config(['database.default' => $padrao]);
        }

        $this->assertPaginaDoApp($telaDoApp, 500, 'Algo deu errado do nosso lado');
        $this->assertPaginaDoApp($inexistente, 404, 'Não encontramos esta página');
    }

    /** `php artisan down --render=errors::503` desenha a view ANTES, sem exceção nenhuma. */
    public function test_503_prerenderizado_pelo_down_tambem_funciona(): void
    {
        (new RegisterErrorViewPaths)();

        $html = view('errors::503', ['retryAfter' => '120'])->render();

        $this->assertStringContainsString('Voltamos em instantes', $html);
        $this->assertStringContainsString('Tente de novo em <strong>2 minutos</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /** A espera sai em palavras, e o arredondamento é sempre para cima. */
    #[DataProvider('esperas')]
    public function test_a_espera_sai_em_palavras(int $segundos, string $texto): void
    {
        (new RegisterErrorViewPaths)();

        $html = view('errors::429', [
            'exception' => new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => $segundos]),
        ])->render();

        $this->assertStringContainsString("Tente de novo em <strong>{$texto}</strong>", $html);
    }

    public static function esperas(): array
    {
        return [
            '1 s' => [1, '1 segundo'],
            '42 s' => [42, '42 segundos'],
            '60 s' => [60, '1 minuto'],
            '61 s' => [61, '2 minutos'],
            '1 h' => [3600, '1 hora'],
            '1 h e 1 s' => [3601, '2 horas'],
        ];
    }

    // ── (b) Não varia por caminho nem por quem está logado ───────────────────

    public function test_404_e_identico_para_qualquer_caminho_e_qualquer_pessoa(): void
    {
        $referencia = $this->get('/nao_existe')->getContent();

        $this->assertSame($referencia, $this->get('/outra/pagina/que/nao/existe')->getContent());
        $this->assertSame($referencia, $this->get('/painel_admin/qualquer-coisa')->getContent());
        $this->assertSame($referencia, $this->actingAs(User::factory()->create())->get('/nao_existe')->getContent());
    }

    // ── (d) JSON continua JSON ────────────────────────────────────────────────

    public function test_requisicao_json_continua_recebendo_json(): void
    {
        $this->exigirCsrfDeVerdade();

        $naoExiste = $this->getJson('/nao_existe')->assertNotFound()->assertJsonStructure(['message']);
        $expirado = $this->postJson('/login', [])->assertStatus(419)->assertJsonPath('message', 'CSRF token mismatch.');
        $quebrado = $this->getJson('/_teste/explodir')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);

        foreach ([$naoExiste, $expirado, $quebrado] as $resposta) {
            $this->assertStringStartsWith('application/json', (string) $resposta->headers->get('Content-Type'));
            $this->assertStringNotContainsString(self::SEGREDO, (string) $resposta->getContent());
            $this->assertCabecalhosDeSeguranca($resposta);
        }
    }

    // ── (e) Cabeçalhos: completa o que falta, nunca duplica ───────────────────

    /**
     * O erro que nasce ao alcance do SecurityHeaders (um `abort` no controller) sai com a
     * CSP COM nonce dele — a do handler é substituída, não somada.
     */
    public function test_erro_dentro_do_grupo_web_fica_com_a_csp_com_nonce(): void
    {
        $resposta = $this->get('/_teste/web/proibido')->assertForbidden();

        $this->assertCount(1, $resposta->headers->all('content-security-policy'));
        $this->assertStringContainsString("'nonce-", (string) $resposta->headers->get('Content-Security-Policy'));
        $this->assertNotNull($resposta->headers->get(SecurityHeaders::HEADER_NONCE));
    }

    /** Fora do alcance dele, a CSP sem nonce — igual em toda resposta, como o 404 exige. */
    public function test_erro_fora_do_grupo_web_fica_com_a_csp_sem_script(): void
    {
        $this->get('/nao_existe')->assertHeader('Content-Security-Policy', SecurityHeaders::CSP_SEM_SCRIPT);
        $this->get('/_teste/so-post')->assertHeader('Content-Security-Policy', SecurityHeaders::CSP_SEM_SCRIPT);
    }

    public function test_so_completa_o_que_falta(): void
    {
        $resposta = new Response('x', 500, ['Content-Security-Policy' => "default-src 'self'"]);

        SecurityHeaders::completarRespostaSemScript($resposta, Request::create('/'));

        $this->assertSame("default-src 'self'", $resposta->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));
    }
}
