<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CSP com nonce — `script-src` deixou de aceitar `'unsafe-inline'`.
 *
 * Com `'unsafe-inline'`, qualquer `<script>` que um atacante conseguisse injetar
 * executava; a CSP não servia de nada contra XSS armazenado, que foi exatamente o
 * achado do pentest de jul/2026 (nome de categoria em `innerHTML`). Agora só executa
 * script que carregue o nonce sorteado para aquela requisição.
 *
 * O teste que mais protege é o `test_nenhum_script_servido_fica_sem_nonce`: ele varre
 * o HTML de verdade de cada tela. Um bloco inline novo sem `nonce=` faz a tela morrer
 * em silêncio no navegador (o script é bloqueado, e só o console avisa) — a suíte
 * precisa gritar antes.
 */
class CspComNonceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Dados suficientes para as telas renderizarem seus blocos condicionais.
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 1000,
        ]);
        Account::factory()->for($this->user)->create([
            'type' => 'credit_card', 'name' => 'Nubank',
            'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
        Category::factory()->for($this->user)->create(['type' => 'expense']);
        Goal::create([
            'user_id' => $this->user->id, 'name' => 'Viagem', 'emoji' => '✈️',
            'color' => '#0F6B47', 'target_amount' => 5000, 'target_date' => '2027-01-01',
        ]);
        Investment::create([
            'user_id' => $this->user->id, 'name' => 'CDB',
            'classe' => 'renda_fixa', 'indexador' => 'cdi', 'taxa' => 100,
        ]);
        $this->conta = $conta;
    }

    private Account $conta;

    /** Extrai o valor do `nonce-...` do header CSP. */
    private function nonceDoHeader(string $csp): ?string
    {
        preg_match("/'nonce-([A-Za-z0-9+\/=_-]+)'/", $csp, $m);

        return $m[1] ?? null;
    }

    // ================= A política =================

    public function test_script_src_usa_nonce_e_nao_aceita_mais_unsafe_inline(): void
    {
        $csp = $this->actingAs($this->user)->get('/')->assertOk()
            ->headers->get('Content-Security-Policy');

        preg_match('/script-src ([^;]+)/', $csp, $m);
        $scriptSrc = $m[1] ?? '';

        $this->assertStringContainsString("'nonce-", $scriptSrc);
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc,
            "script-src voltou a aceitar 'unsafe-inline' — a CSP deixa de valer contra XSS.");
    }

    public function test_o_nonce_muda_a_cada_requisicao(): void
    {
        $um = $this->nonceDoHeader(
            $this->actingAs($this->user)->get('/')->headers->get('Content-Security-Policy')
        );
        $dois = $this->nonceDoHeader(
            $this->actingAs($this->user)->get('/')->headers->get('Content-Security-Policy')
        );

        $this->assertNotNull($um);
        $this->assertNotSame($um, $dois, 'nonce reaproveitado deixa de ser imprevisível');
        // Entropia: um nonce curto é adivinhável por força bruta.
        $this->assertGreaterThanOrEqual(16, strlen($um));
    }

    public function test_o_header_publica_o_nonce_para_o_pjax(): void
    {
        $resposta = $this->actingAs($this->user)->get('/')->assertOk();

        $doHeader = $resposta->headers->get('X-Csp-Nonce');
        $daCsp = $this->nonceDoHeader($resposta->headers->get('Content-Security-Policy'));

        // É por este header que o nav.js distingue script legítimo de injetado.
        $this->assertNotEmpty($doHeader);
        $this->assertSame($daCsp, $doHeader);
    }

    // ================= A varredura que realmente protege =================

    public static function telasComScript(): array
    {
        return [
            'dashboard' => ['/'],
            'histórico' => ['/transactions'],
            'nova transação' => ['/transactions/create'],
            'pagar despesas' => ['/faturas'],
            'métodos de pagamento' => ['/accounts'],
            'nova conta' => ['/accounts/create'],
            'categorias' => ['/categories'],
            'dependentes' => ['/dependentes'],
            'meu perfil' => ['/meu-perfil'],
            'configurações' => ['/configuracoes'],
            'configurações › conta' => ['/configuracoes/conta'],
            'metas' => ['/metas'],
            'investimentos' => ['/investimentos'],
        ];
    }

    #[DataProvider('telasComScript')]
    public function test_nenhum_script_servido_fica_sem_nonce(string $rota): void
    {
        $resposta = $this->actingAs($this->user)->get($rota)->assertOk();
        $nonce = $resposta->headers->get('X-Csp-Nonce');
        $html = $resposta->getContent();

        // Varre TODAS as tags <script> do HTML, não uma escolhida a dedo — é isso
        // que faz o teste pegar um bloco inline novo que alguém esquecer de carimbar.
        preg_match_all('/<script\b[^>]*>/i', $html, $tags);

        $this->assertNotEmpty($tags[0], "A tela {$rota} não tem script nenhum — o teste não está checando nada.");

        $semNonce = array_values(array_filter(
            $tags[0],
            fn (string $tag) => ! str_contains($tag, 'nonce="'.$nonce.'"'),
        ));

        $this->assertSame([], $semNonce,
            "Script sem o nonce da requisição em {$rota} — o navegador vai bloquear:\n"
            .implode("\n", $semNonce));
    }

    public function test_telas_publicas_tambem_carimbam(): void
    {
        // O cookie-consent é incluído em TODOS os layouts, inclusive fora do login.
        foreach (['/login', '/register', '/termos', '/privacidade', '/offline'] as $rota) {
            $resposta = $this->get($rota)->assertOk();
            $nonce = $resposta->headers->get('X-Csp-Nonce');

            preg_match_all('/<script\b[^>]*>/i', $resposta->getContent(), $tags);

            foreach ($tags[0] as $tag) {
                $this->assertStringContainsString('nonce="'.$nonce.'"', $tag,
                    "Script sem nonce em {$rota}: {$tag}");
            }
        }
    }

    /**
     * Manipulador inline (`onclick="..."`) é bloqueado pela CSP com nonce SEMPRE: o nonce
     * vale para `<script>`, não para atributo. O "Tentar de novo" da página offline era um
     * `onclick` e não fazia nada — em silêncio, só o console avisava. É a tela que aparece
     * justamente quando a pessoa está sem rede e quer tentar de novo.
     */
    public function test_a_pagina_offline_nao_depende_de_manipulador_inline(): void
    {
        $html = $this->get('/offline')->assertOk()->getContent();

        preg_match_all('/<[a-z][^>]*\son[a-z]+\s*=/i', $html, $achados);

        $this->assertSame([], $achados[0],
            "Manipulador inline na página offline — a CSP o bloqueia:\n".implode("\n", $achados[0]));
        // E o botão continua ligado, agora pelo script com nonce.
        $this->assertStringContainsString("getElementById('tentarDeNovo').addEventListener('click'", $html);
    }

    // ================= Não regrediu =================

    public function test_ambiente_local_continua_liberando_o_vite(): void
    {
        // Sem isto, `npm run dev` quebra: em dev os assets vêm de localhost:5173.
        // A suíte roda em `testing`, então o ambiente precisa ser forçado — e é bom
        // que seja assim: a liberação NÃO pode vazar para produção.
        $this->app->detectEnvironment(fn () => 'local');

        $csp = $this->actingAs($this->user)->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('localhost:5173', $csp);
        $this->assertStringContainsString("'nonce-", $csp, 'o nonce vale também em dev');
    }

    public function test_fora_do_local_o_vite_nao_e_liberado(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $csp = $this->actingAs($this->user)->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('5173', $csp);
    }

    public function test_style_src_mantem_unsafe_inline_de_proposito(): void
    {
        // São 71 atributos `style="..."` nas views, e nonce NÃO existe para atributo
        // de estilo. Style inline não executa código — o ganho está em script-src.
        $csp = $this->actingAs($this->user)->get('/')->headers->get('Content-Security-Policy');

        preg_match('/style-src ([^;]+)/', $csp, $m);
        $this->assertStringContainsString("'unsafe-inline'", $m[1] ?? '');
    }

    public function test_as_demais_travas_da_csp_continuam(): void
    {
        $csp = $this->actingAs($this->user)->get('/')->headers->get('Content-Security-Policy');

        foreach (["object-src 'none'", "frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'"] as $trava) {
            $this->assertStringContainsString($trava, $csp);
        }
    }

    public function test_hsts_continua_so_sobre_https(): void
    {
        $this->assertNull(
            $this->actingAs($this->user)->get('/')->headers->get('Strict-Transport-Security'),
        );
    }

    public function test_a_csp_nunca_emite_endereco_ipv6_que_o_navegador_descarta(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        // Um `hot` apontando para IPv6 é possível: o Vite escolhe o host sozinho se
        // `server.host` não estiver fixo. Mas a gramática de `host-source` da CSP NÃO
        // aceita literal IPv6 — o navegador marca a fonte como inválida, ignora, e
        // bloqueia o recurso. O app abria SEM CSS NENHUM, com o aviso só no console.
        $hot = public_path('hot');
        $original = is_file($hot) ? file_get_contents($hot) : null;
        file_put_contents($hot, 'http://[::1]:5173');

        try {
            $csp = $this->actingAs($this->user)->get('/')->headers->get('Content-Security-Policy');

            $this->assertStringNotContainsString('[::1]', $csp,
                'endereço IPv6 na CSP é fonte inválida — o navegador descarta e bloqueia o recurso');
            // E a rede de segurança continua lá, para o dev não ficar sem nada.
            $this->assertStringContainsString('http://127.0.0.1:5173', $csp);
        } finally {
            $original === null ? @unlink($hot) : file_put_contents($hot, $original);
        }
    }

    public function test_a_origem_do_vite_vem_do_arquivo_hot(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $hot = public_path('hot');
        $original = is_file($hot) ? file_get_contents($hot) : null;
        file_put_contents($hot, 'http://127.0.0.1:5199');

        try {
            $csp = $this->actingAs($this->user)->get('/')->headers->get('Content-Security-Policy');

            // Porta diferente da padrão: só passa se a origem for LIDA, não chutada.
            $this->assertStringContainsString('http://127.0.0.1:5199', $csp);
            $this->assertStringContainsString('ws://127.0.0.1:5199', $csp, 'o HMR usa o mesmo host');
        } finally {
            $original === null ? @unlink($hot) : file_put_contents($hot, $original);
        }
    }
}
