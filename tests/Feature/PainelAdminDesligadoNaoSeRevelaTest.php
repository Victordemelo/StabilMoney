<?php

namespace Tests\Feature;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Painel DESLIGADO é indistinguível de uma URL que nunca existiu, em QUALQUER verbo e em
 * QUALQUER caminho sob o prefixo (achado A-1 da auditoria de 06/09/2026).
 *
 * `PainelAdminAcessoTest` prende o 404 nos casos óbvios. O que escapava: um 404 só esconde
 * alguma coisa se a resposta INTEIRA for igual à de `/nao_existe`. Com o interruptor só na
 * rota, um scanner distinguia o prefixo por respostas que uma URL inexistente nunca dá —
 * 405 no verbo errado, 200 com `Allow` no OPTIONS, 419 no POST sem token, `X-RateLimit-*`
 * com token — e até o 404 "certo" saía com cookie de sessão e CSP, porque atravessava o
 * grupo `web` antes de chegar ao interruptor.
 *
 * Por isso a comparação aqui é da resposta inteira (status, headers e corpo) contra a de
 * `/nao_existe` no mesmo verbo, e não só do status.
 */
class PainelAdminDesligadoNaoSeRevelaTest extends TestCase
{
    use RefreshDatabase;

    /** Todos os verbos que o roteador conhece (`Router::$verbs`). */
    private const VERBOS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** Os que passam pelo CSRF — os únicos em que ter token muda alguma coisa. */
    private const VERBOS_DE_ESCRITA = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const URL_INEXISTENTE = '/nao_existe';

    private const TOKEN = 'token-valido-do-scanner';

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.enabled' => false]);

        // Produção roda sem debug, e é lá que o scanner bate. Com debug ligado a página de
        // erro mostra arquivo e linha — aí nada é indistinguível de nada.
        config(['app.debug' => false]);

        $this->exigirCsrfDeVerdade();
    }

    // ── Desligado: a resposta inteira igual à de uma URL inexistente ─────────

    public function test_desligado_sem_token_nenhum_verbo_distingue_o_prefixo_de_uma_url_inexistente(): void
    {
        $denuncias = [];

        foreach ($this->caminhosDoPainel() as $caminho) {
            foreach (self::VERBOS as $verbo) {
                foreach (['html' => false, 'json' => true] as $formato => $json) {
                    $controle = $this->requisitar($verbo, self::URL_INEXISTENTE, $json);
                    $painel = $this->requisitar($verbo, $caminho, $json);

                    $this->assertSame(404, $controle->getStatusCode(), 'O controle precisa ser um 404 de verdade.');

                    if ($diferenca = $this->diferenca($painel, $caminho, $controle)) {
                        $denuncias[] = "{$verbo} {$caminho} [{$formato}] → {$diferenca}";
                    }
                }
            }
        }

        $this->assertEmpty($denuncias, "Painel desligado, e estas respostas denunciam o prefixo:\n".implode("\n", $denuncias));
    }

    /**
     * Token não é barreira: qualquer um pega um na tela de login do app. Com token, o CSRF
     * deixava passar e a requisição chegava ao `throttle` do painel, que carimba
     * `X-RateLimit-*` na resposta (e depois responde 429) — antes do interruptor.
     */
    public function test_desligado_com_token_valido_tambem_nao_distingue(): void
    {
        // Prova de que o token montado aqui é aceito — senão este teste repetiria o de cima.
        $login = $this->comToken()->post('/login', ['_token' => self::TOKEN, 'email' => 'a@b.c', 'password' => 'x']);
        $this->assertNotSame(419, $login->getStatusCode(), 'O token do teste precisa passar pelo CSRF.');

        $denuncias = [];

        foreach ($this->caminhosDoPainel() as $caminho) {
            foreach (self::VERBOS_DE_ESCRITA as $verbo) {
                $controle = $this->comToken()->call($verbo, self::URL_INEXISTENTE, ['_token' => self::TOKEN]);
                $painel = $this->comToken()->call($verbo, $caminho, ['_token' => self::TOKEN]);

                if ($diferenca = $this->diferenca($painel, $caminho, $controle)) {
                    $denuncias[] = "{$verbo} {$caminho} [com token] → {$diferenca}";
                }
            }
        }

        $this->assertEmpty($denuncias, "Painel desligado, e estas respostas denunciam o prefixo:\n".implode("\n", $denuncias));
    }

    /**
     * O scanner mais comum: POST cego em caminho conhecido. 419 diz "aqui tem formulário
     * protegido" — e uma URL inexistente nunca responde 419.
     */
    public function test_desligado_post_sem_token_nunca_da_419(): void
    {
        // Prova de que este teste enxerga o CSRF: no app, POST sem token é 419.
        $this->post('/login', ['email' => 'a@b.c', 'password' => 'x'])->assertStatus(419);

        $this->post(route('painel.autenticar'), ['email' => 'a@b.c', 'password' => 'x'])->assertNotFound();
        $this->post(route('painel.2fa.verificar'), ['codigo' => '000000'])->assertNotFound();
        $this->post(route('painel.logout'))->assertNotFound();
        $this->post(route('painel.banir', ['user' => 1]))->assertNotFound();
        $this->delete(route('painel.excluir', ['user' => 1]))->assertNotFound();
    }

    /**
     * O interruptor roda no FIM da pilha global, onde o roteador rodaria — não no começo.
     *
     * O que uma URL qualquer responde ANTES do roteador, o prefixo tem de responder igual.
     * POST maior que o `post_max_size` é o caso barato de provar: 413 para `/nao_existe`.
     * Com o interruptor na frente da pilha, o prefixo daria 404 e se entregaria.
     */
    public function test_desligado_o_que_vem_antes_do_roteador_trata_o_prefixo_como_qualquer_url(): void
    {
        $limite = ini_parse_quantity((string) ini_get('post_max_size'));
        if ($limite <= 0) {
            $this->markTestSkipped('Sem post_max_size, não há 413 para comparar.');
        }

        $grandeDemais = ['CONTENT_LENGTH' => (string) ($limite + 1)];

        $controle = $this->call('POST', self::URL_INEXISTENTE, [], [], [], $grandeDemais);
        $painel = $this->call('POST', route('painel.autenticar', [], false), [], [], [], $grandeDemais);

        $controle->assertStatus(413);
        $painel->assertStatus(413);
    }

    // ── O que a correção NÃO pode levar junto ────────────────────────────────

    /** A correção mexe na pilha GLOBAL: o resto do app não pode perder CSRF nem cabeçalhos. */
    public function test_desligado_o_resto_do_app_segue_com_csrf_e_cabecalhos(): void
    {
        $this->post('/login', ['email' => 'a@b.c', 'password' => 'x'])->assertStatus(419);

        $pagina = $this->get('/login')->assertOk()->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString("'nonce-", (string) $pagina->headers->get('Content-Security-Policy'));
    }

    /**
     * O prefixo é escondido como SEGMENTO: `painel_admin` e o que vem abaixo dele. Uma
     * rota que só começa com as mesmas letras continua no ar — é o erro de quem troca a
     * checagem por um `str_starts_with`.
     */
    public function test_desligado_so_o_prefixo_inteiro_e_escondido(): void
    {
        $vizinha = '/'.trim(config('admin.path'), '/').'-publico';
        Route::get($vizinha, fn () => 'rota vizinha no ar');

        $this->get($vizinha)->assertOk()->assertSee('rota vizinha no ar');
    }

    /** Ligado, o interruptor sai do caminho: o painel responde e o CSRF segue valendo nele. */
    public function test_ligado_o_painel_responde_e_continua_exigindo_token(): void
    {
        config(['admin.enabled' => true]);

        $this->get(route('painel.login'))->assertOk();
        $this->post(route('painel.autenticar'), ['email' => 'a@b.c', 'password' => 'x'])->assertStatus(419);
    }

    /**
     * Rede de segurança: rota do painel FORA do prefixo configurado continua 404.
     *
     * Acontece com cache de rotas velho: `ADMIN_PANEL_PATH` muda, o config passa a apontar
     * um caminho e as rotas seguem registradas no outro. A checagem pelo caminho deixa
     * passar; a que roda na própria rota não olha o caminho e fecha a porta.
     */
    public function test_rota_do_painel_fora_do_prefixo_configurado_continua_404(): void
    {
        config(['admin.path' => 'outro_caminho']); // as rotas seguem em /painel_admin

        $this->get(route('painel.login'))->assertNotFound();
        $this->get(route('painel.home'))->assertNotFound();
    }

    // ── Apoio ────────────────────────────────────────────────────────────────

    /**
     * Um caminho de cada jeito que uma rota se entrega, mais dois inventados.
     *
     * @return list<string>
     */
    private function caminhosDoPainel(): array
    {
        $prefixo = '/'.trim(config('admin.path'), '/');

        return [
            route('painel.login', [], false),               // GET + POST, na raiz do prefixo
            route('painel.logout', [], false),              // só POST
            route('painel.home', [], false),                // só GET
            route('painel.excluir', ['user' => 1], false),  // DELETE + GET (a ficha), com parâmetro
            $prefixo.'/wp-login.php',                       // inventado
            $prefixo.'/nao/existe/mesmo',                   // inventado, fundo
        ];
    }

    private function requisitar(string $verbo, string $caminho, bool $json): TestResponse
    {
        return $json ? $this->json($verbo, $caminho) : $this->call($verbo, $caminho);
    }

    private function comToken(): static
    {
        return $this->withSession(['_token' => self::TOKEN]);
    }

    /**
     * O que difere entre a resposta do painel e a da URL inexistente, ou null se nada.
     *
     * Só o `Date` fica de fora (é relógio, não rota). E o corpo é comparado com o caminho
     * trocado por um marcador: o 404 em JSON cita o caminho pedido — para QUALQUER URL
     * inexistente —, e fora isso tem de bater byte a byte.
     */
    private function diferenca(TestResponse $painel, string $caminho, TestResponse $controle): ?string
    {
        $partes = [];

        if ($painel->getStatusCode() !== $controle->getStatusCode()) {
            $partes[] = "status {$painel->getStatusCode()} (URL inexistente: {$controle->getStatusCode()})";
        }

        $headersDoPainel = $painel->headers->all();
        $headersDoControle = $controle->headers->all();
        unset($headersDoPainel['date'], $headersDoControle['date']);

        $diferentes = array_filter(
            array_keys($headersDoPainel + $headersDoControle),
            fn (string $nome) => ($headersDoPainel[$nome] ?? null) !== ($headersDoControle[$nome] ?? null),
        );

        if ($diferentes !== []) {
            $partes[] = 'headers '.implode(', ', $diferentes);
        }

        $corpoDoPainel = str_replace(ltrim($caminho, '/'), '{caminho}', (string) $painel->getContent());
        $corpoDoControle = str_replace(ltrim(self::URL_INEXISTENTE, '/'), '{caminho}', (string) $controle->getContent());

        if ($corpoDoPainel !== $corpoDoControle) {
            $partes[] = 'corpo';
        }

        return $partes === [] ? null : implode(' · ', $partes);
    }

    /**
     * Devolve ao CSRF o comportamento de produção.
     *
     * O Laravel PULA a checagem em teste (`VerifyCsrfToken::runningUnitTests()`). Sem isto,
     * POST sem token nunca daria 419 aqui, e o teste do 419 passaria verde sem provar nada —
     * inclusive antes da correção.
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
}
