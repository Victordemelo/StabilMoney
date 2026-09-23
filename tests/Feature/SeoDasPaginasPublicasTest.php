<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Seo;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SEO das páginas públicas (23/09/2026, antes da publicação em
 * stabilmoney.victordemelo.com.br).
 *
 * O app é quase todo privado: só o login, o cadastro e os documentos legais existem para
 * quem não entrou. O contrato:
 *  - essas quatro páginas (config/seo.php) são as ÚNICAS indexáveis, e só em produção;
 *    todo o resto — telas do app, erros, telas secundárias de auth, painel — sai com
 *    `X-Robots-Tag: noindex` por padrão, sem ninguém precisar lembrar;
 *  - robots.txt e sitemap.xml gerados a partir do APP_URL; fora de produção, o robots
 *    fecha tudo e o sitemap sai vazio;
 *  - as páginas públicas levam descrição, URL canônica e prévia de link (Open Graph), e
 *    login/cadastro, os dados estruturados do app — sempre com as URLs do APP_URL, nunca
 *    do Host da requisição (um Host forjado não pode virar a URL canônica);
 *  - o caminho do painel administrativo não aparece em lugar nenhum.
 */
class SeoDasPaginasPublicasTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'https://stabilmoney.victordemelo.com.br';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => self::APP_URL]);
    }

    /**
     * Em produção o app só aceita o Host do APP_URL (TrustHosts): as requisições destes testes
     * vão para o endereço verdadeiro — `endereco()` —, como o nginx entregaria.
     */
    private function emProducao(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
    }

    private function endereco(string $caminho): string
    {
        return self::APP_URL.$caminho;
    }

    private function documento(TestResponse $resposta): HTMLDocument
    {
        return HTMLDocument::createFromString($resposta->getContent(), LIBXML_NOERROR);
    }

    private function meta(HTMLDocument $doc, string $atributo, string $nome): ?string
    {
        return $doc->querySelector('meta['.$atributo.'="'.$nome.'"]')?->getAttribute('content');
    }

    // ── robots.txt e sitemap.xml ─────────────────────────────────────────────

    public function test_robots_em_producao_abre_tudo_e_aponta_o_sitemap(): void
    {
        $this->emProducao();

        $resposta = $this->get($this->endereco('/robots.txt'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeaderMissing('X-Robots-Tag');

        $texto = $resposta->getContent();
        $this->assertStringContainsString("User-agent: *\nAllow: /\n", $texto);
        $this->assertStringContainsString('Sitemap: '.self::APP_URL.'/sitemap.xml', $texto);
        $this->assertStringNotContainsString('Disallow: /', $texto);
        // O painel não se anuncia — nem pelo caminho, nem pelo nome.
        $this->assertStringNotContainsString(config('admin.path'), $texto);
        $this->assertStringNotContainsStringIgnoringCase('painel', $texto);
        // Quem busca é o robô: sem sessão, sem cookie.
        $this->assertEmpty($resposta->headers->getCookies());
    }

    public function test_robots_fora_de_producao_fecha_tudo(): void
    {
        $texto = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString("User-agent: *\nDisallow: /\n", $texto);
        $this->assertStringNotContainsString('Sitemap:', $texto);
    }

    public function test_o_robots_nao_e_mais_o_arquivo_estatico(): void
    {
        // Um public/robots.txt seria servido pelo Apache antes do Laravel, ignorando o
        // ambiente e o APP_URL.
        $this->assertFileDoesNotExist(public_path('robots.txt'));
    }

    public function test_sitemap_lista_so_as_paginas_publicas_com_a_url_do_app_url(): void
    {
        $this->emProducao();

        $resposta = $this->get($this->endereco('/sitemap.xml'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $xml = simplexml_load_string($resposta->getContent());
        $this->assertNotFalse($xml, 'O sitemap não é XML válido.');
        $this->assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $xml->getNamespaces()['']);

        $enderecos = array_map(fn ($url) => (string) $url->loc, iterator_to_array($xml->url, false));
        $this->assertSame([
            self::APP_URL.'/login',
            self::APP_URL.'/register',
            self::APP_URL.'/termos',
            self::APP_URL.'/privacidade',
        ], $enderecos);

        // Cada endereço listado abre de verdade para quem não entrou.
        foreach ($enderecos as $endereco) {
            $this->get($endereco)->assertOk();
        }
    }

    public function test_sitemap_fora_de_producao_sai_vazio(): void
    {
        $xml = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());

        $this->assertCount(0, $xml->url);
    }

    // ── Quem vai para os buscadores ──────────────────────────────────────────

    public function test_em_producao_so_as_paginas_publicas_ficam_indexaveis(): void
    {
        $this->emProducao();

        foreach (['/login', '/register', '/termos', '/privacidade'] as $publica) {
            $this->get($this->endereco($publica))->assertOk()->assertHeaderMissing('X-Robots-Tag');
        }

        // Telas secundárias de auth, a página offline e erro: fora.
        foreach (['/forgot-password', '/offline', '/nao_existe'] as $fora) {
            $this->get($this->endereco($fora))->assertHeader('X-Robots-Tag', Seo::NOINDEX);
        }

        // As telas do app, e o login de quem já entrou (que só redireciona).
        $user = User::factory()->create();
        foreach (['/', '/faturas', '/meu-perfil', '/login'] as $privada) {
            $this->actingAs($user)->get($this->endereco($privada))->assertHeader('X-Robots-Tag', Seo::NOINDEX);
        }
    }

    public function test_o_painel_administrativo_nunca_e_indexavel(): void
    {
        $this->emProducao();
        config(['admin.enabled' => true]);

        $this->get($this->endereco('/'.config('admin.path')))->assertOk()->assertHeader('X-Robots-Tag', Seo::NOINDEX);
    }

    public function test_fora_de_producao_nenhuma_pagina_e_indexavel(): void
    {
        foreach (['/login', '/register', '/termos', '/privacidade'] as $publica) {
            $this->get($publica)->assertOk()->assertHeader('X-Robots-Tag', Seo::NOINDEX);
        }
    }

    // ── Descrição, canônica e prévia de link ─────────────────────────────────

    public function test_o_login_leva_descricao_canonica_e_previa_de_link_pelo_app_url(): void
    {
        // Host FORJADO na requisição: nada do que a página publica pode apontar para ele.
        $doc = $this->documento($this->get('http://site-forjado.test/login?utm_source=zap')->assertOk());

        $descricao = config('seo.paginas.login.descricao');
        $this->assertSame($descricao, $this->meta($doc, 'name', 'description'));
        $this->assertSame(self::APP_URL.'/login', $doc->querySelector('link[rel="canonical"]')?->getAttribute('href'));

        $this->assertSame('website', $this->meta($doc, 'property', 'og:type'));
        $this->assertSame('Stabil Money', $this->meta($doc, 'property', 'og:site_name'));
        $this->assertSame('pt_BR', $this->meta($doc, 'property', 'og:locale'));
        $this->assertSame('Entrar · StabilMoney', $this->meta($doc, 'property', 'og:title'));
        $this->assertSame($descricao, $this->meta($doc, 'property', 'og:description'));
        $this->assertSame(self::APP_URL.'/login', $this->meta($doc, 'property', 'og:url'));
        $this->assertSame(self::APP_URL.'/assets/og-stabilmoney.jpg', $this->meta($doc, 'property', 'og:image'));
        $this->assertSame('1200', $this->meta($doc, 'property', 'og:image:width'));
        $this->assertSame('630', $this->meta($doc, 'property', 'og:image:height'));
        $this->assertSame('summary_large_image', $this->meta($doc, 'name', 'twitter:card'));

        // Nenhuma URL que o SEO publica vem do Host. (As dos assets vêm — em produção o
        // `forceRootUrl` e o `TrustHosts` cuidam disso; aqui o teste é do SEO.)
        $publicadas = [
            $doc->querySelector('link[rel="canonical"]')?->getAttribute('href'),
            $this->meta($doc, 'property', 'og:url'),
            $this->meta($doc, 'property', 'og:image'),
            $this->meta($doc, 'name', 'twitter:image'),
            $doc->querySelector('script[type="application/ld+json"]')?->textContent,
        ];
        foreach ($publicadas as $valor) {
            $this->assertNotNull($valor);
            $this->assertStringNotContainsString('site-forjado', $valor);
        }
    }

    public function test_os_documentos_legais_levam_o_proprio_titulo_e_descricao(): void
    {
        $doc = $this->documento($this->get('/privacidade')->assertOk());

        $this->assertSame('Política de Privacidade — StabilMoney', $this->meta($doc, 'property', 'og:title'));
        $this->assertSame(config('seo.paginas.privacidade.descricao'), $this->meta($doc, 'name', 'description'));
        $this->assertSame(self::APP_URL.'/privacidade', $doc->querySelector('link[rel="canonical"]')?->getAttribute('href'));
        // Documento legal não é o app: sem dados estruturados de aplicativo.
        $this->assertNull($doc->querySelector('script[type="application/ld+json"]'));
    }

    public function test_pagina_que_nao_e_publica_nao_ganha_previa_de_link(): void
    {
        // A redefinição de senha tem o TOKEN no caminho: uma canônica ou um og:url ali só
        // espalharia o link.
        foreach (['/forgot-password', '/reset-password/token-secreto-123?email=ana@exemplo.test'] as $caminho) {
            $html = $this->get($caminho)->assertOk()->getContent();

            $this->assertStringNotContainsString('rel="canonical"', $html);
            $this->assertStringNotContainsString('og:', $html);
            $this->assertStringNotContainsString('name="description"', $html);
        }
    }

    // ── Dados estruturados ───────────────────────────────────────────────────

    public function test_login_e_cadastro_descrevem_o_app_para_o_buscador(): void
    {
        foreach (['/login', '/register'] as $caminho) {
            $resposta = $this->get($caminho)->assertOk();
            $script = $this->documento($resposta)->querySelector('script[type="application/ld+json"]');
            $this->assertNotNull($script, "Sem dados estruturados em {$caminho}.");

            // Com o nonce da resposta, como todo <script> do app.
            $this->assertSame($resposta->headers->get('X-Csp-Nonce'), $script->getAttribute('nonce'));

            $dados = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('https://schema.org', $dados['@context']);
            $this->assertSame('WebApplication', $dados['@type']);
            $this->assertSame('Stabil Money', $dados['name']);
            $this->assertSame(self::APP_URL.'/', $dados['url']);
            $this->assertSame('FinanceApplication', $dados['applicationCategory']);
            $this->assertSame(['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'BRL'], $dados['offers']);
            $this->assertSame(config('legal.controller'), $dados['author']['name']);
        }
    }

    // ── A imagem da prévia ───────────────────────────────────────────────────

    public function test_a_imagem_da_previa_existe_no_tamanho_certo_e_e_leve(): void
    {
        $arquivo = public_path(config('seo.imagem'));

        $this->assertFileExists($arquivo);
        [$largura, $altura] = getimagesize($arquivo);
        $this->assertSame([1200, 630], [$largura, $altura]);
        // O WhatsApp costuma não mostrar prévia de imagem acima de ~300 KB.
        $this->assertLessThan(300 * 1024, filesize($arquivo));
    }
}
