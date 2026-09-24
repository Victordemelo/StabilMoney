<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * A Política de Privacidade descreve o código REAL, não um texto genérico
 * (CLAUDE.md, "Ao mexer no que o app coleta/compartilha, atualizar essas tabelas").
 *
 * Até 24/09/2026 a versão 2.0 dizia que a verificação em duas etapas estava "prevista, ainda
 * não disponível" (existe desde 05/08), que não havia rotina de backup, que o Google Fonts era a
 * "única transferência internacional" (a checagem de senha vazada já consultava um serviço de
 * fora, e a publicação põe a Cloudflare na frente de todo acesso), listava o cookie de sessão
 * com um nome que o navegador nunca recebe e omitia o de "lembrar de mim".
 */
class PoliticaDescreveOCodigoRealTest extends TestCase
{
    /**
     * Serviço que RECEBE dado a cada host externo que o código contata — o nome que tem de
     * aparecer na Política. Host novo no código sem entrada aqui faz o teste falhar.
     */
    private const SERVICO_DO_HOST = [
        'fonts.googleapis.com' => 'Google Fonts',
        'fonts.gstatic.com' => 'Google Fonts',
        'api.pwnedpasswords.com' => 'Have I Been Pwned',
    ];

    /** Hosts que aparecem no código sem receber dado de ninguém (namespaces e links). */
    private const HOSTS_SEM_DADO = [
        'schema.org',           // @context do JSON-LD
        'www.sitemaps.org',     // namespace do sitemap.xml
        'www.w3.org',           // namespace de SVG/XML
        'www.gov.br',           // link para a ANPD na própria Política
        'victordemelo.com.br',  // link do autor no JSON-LD
    ];

    public function test_o_cookie_de_sessao_listado_e_o_que_o_navegador_recebe(): void
    {
        $this->get('/privacidade')->assertOk()->assertSee(config('session.cookie'));

        // Vem da config, não de um texto fixo: o nome muda com APP_NAME/SESSION_COOKIE.
        config(['session.cookie' => 'cookie-de-conferencia-session']);
        $this->get('/privacidade')->assertSee('cookie-de-conferencia-session');
    }

    public function test_o_cookie_de_lembrar_de_mim_esta_na_tabela(): void
    {
        $nome = Auth::guard('web')->getRecallerName();
        $this->assertStringStartsWith('remember_web_', $nome);

        $this->get('/privacidade')->assertSee('remember_web_…')->assertSee('Lembrar de mim');
    }

    public function test_os_operadores_da_publicacao_estao_nomeados(): void
    {
        $this->get('/privacidade')
            ->assertSee('Oracle Cloud Infrastructure')
            // A VPS fica na região de São Paulo (confirmado em 24/09/2026): os dados ficam no
            // Brasil. Mudou de região? Mude as seções 6 e 13 — e, se sair do país, a 13 diz isso.
            ->assertSee('região de São Paulo')
            ->assertSee('Cloudflare')
            ->assertSee('Provedor de envio de e-mail')
            ->assertSee('Have I Been Pwned')
            ->assertSee('Google Fonts');
    }

    public function test_todo_servico_externo_que_o_codigo_contata_aparece_na_politica(): void
    {
        $politica = $this->get('/privacidade')->assertOk()->getContent();

        $arquivos = Finder::create()->files()
            ->in([app_path(), resource_path('views'), resource_path('js')])
            ->exclude('legal')
            ->name(['*.php', '*.js']);

        $hosts = [];
        foreach ($arquivos as $arquivo) {
            preg_match_all('#https?://([a-z0-9.-]+\.[a-z]{2,})#i', $arquivo->getContents(), $m);
            foreach ($m[1] as $host) {
                $hosts[strtolower($host)][] = $arquivo->getRelativePathname();
            }
        }

        $this->assertNotEmpty($hosts, 'A varredura não encontrou host nenhum: o Finder mudou de lugar?');

        foreach ($hosts as $host => $onde) {
            if (in_array($host, self::HOSTS_SEM_DADO, true)) {
                continue;
            }

            $this->assertArrayHasKey($host, self::SERVICO_DO_HOST,
                "O código fala com {$host} (".implode(', ', array_unique($onde)).'), e este teste não sabe '
                .'quem é. Se o host recebe dado de alguém, nomeie o serviço nas seções 6 e 13 da Política '
                .'(suba a legal.version) e acrescente-o em SERVICO_DO_HOST; se não recebe, em HOSTS_SEM_DADO.');

            $this->assertStringContainsString(self::SERVICO_DO_HOST[$host], $politica,
                "O código fala com {$host}, e a Política não cita ".self::SERVICO_DO_HOST[$host].'.');
        }
    }

    public function test_nao_restam_afirmacoes_que_o_codigo_desmente(): void
    {
        $this->get('/privacidade')
            ->assertDontSee('ainda não disponível')             // o 2FA existe desde 05/08/2026
            ->assertDontSee('garantia de rotina de backup')     // scripts/backup-db.sh + cron do guia
            ->assertDontSee('única transferência internacional')
            ->assertDontSee('stabilmoney_session')              // o nome real vem da config
            ->assertDontSee('nome e, se preciso, e-mail');      // o login de dependente exige e-mail e senha
    }

    public function test_os_termos_dizem_o_alcance_da_suspensao_e_o_canal_de_contestacao(): void
    {
        $this->get('/termos')
            ->assertSee('vale também para os')
            ->assertSee('remove a família inteira')
            ->assertSee(config('legal.contact_email'));
    }
}
