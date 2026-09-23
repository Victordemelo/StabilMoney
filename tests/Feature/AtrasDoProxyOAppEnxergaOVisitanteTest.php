<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\LeConfigComAmbiente;
use Tests\TestCase;

/**
 * Atrás do nginx do host (e da Cloudflare), o app enxerga o VISITANTE — e só o proxy
 * confiável consegue dizer quem ele é (publicação na VPS, 23/09/2026).
 *
 * Em produção toda requisição chega ao PHP vinda do nginx: para o Apache do container, o
 * endereço de todo mundo é o gateway da rede Docker, e a conexão é http. Sem os proxies
 * confiáveis configurados:
 * - os limites por IP viram um limite só para o site inteiro (o sexto cadastro do minuto,
 *   de QUALQUER pessoa, leva 429);
 * - a prova do aceite dos termos e a tela de sessões gravam o IP do gateway;
 * - o HSTS nunca sai, porque o app se acha em http.
 *
 * E a lista não pode ser generosa: quem se conecta a partir de um endereço confiável escolhe
 * o próprio IP e o próprio "https" pelos cabeçalhos X-Forwarded-*.
 *
 * Endereços de documentação (RFC 5737), para nenhum teste esbarrar em IP de verdade.
 */
class AtrasDoProxyOAppEnxergaOVisitanteTest extends TestCase
{
    use LeConfigComAmbiente;
    use RefreshDatabase;

    /** O gateway fixo da rede do docker-compose.prod.yml — por onde o nginx chega. */
    private const PROXY = '172.16.80.1';

    private const VISITANTE = '203.0.113.7';

    private const OUTRO_VISITANTE = '198.51.100.23';

    /** Alguém que fala direto com o container, sem ser o proxy. */
    private const INTRUSO = '192.0.2.50';

    private const HOST = 'stabilmoney.victordemelo.com.br';

    protected function setUp(): void
    {
        parent::setUp();

        // O que o app enxerga da requisição, sem depender de tela nenhuma.
        Route::get('/_teste/quem-sou-eu', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'https' => $request->secure(),
            'host' => $request->getHost(),
            'link' => url('/destino'),
        ]));
    }

    private function confiarNoProxy(): void
    {
        config(['trustedproxy.proxies' => [self::PROXY]]);
    }

    /**
     * Os cabeçalhos exatamente como o nginx do host os monta (deploy/nginx/): o IP do
     * visitante SOZINHO no X-Forwarded-For, https, o host e a porta pública.
     */
    private function cabecalhosDoNginx(string $visitante): array
    {
        return [
            'X-Forwarded-For' => $visitante,
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => self::HOST,
            'X-Forwarded-Port' => '443',
        ];
    }

    /** Uma requisição que chega ao container vinda de <origem>, com os cabeçalhos dados. */
    private function vindoDe(string $origem, array $cabecalhos): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $origem])->withHeaders($cabecalhos);
    }

    private function quemSouEu(): array
    {
        return $this->getJson('/_teste/quem-sou-eu')->assertOk()->json();
    }

    private function cadastroVazio(): TestResponse
    {
        // Sem campos: a validação recusa sem gastar um argon2id, mas o limite conta.
        return $this->postJson('/register', []);
    }

    // ------------------------------------------------ o que o proxy confiável entrega

    public function test_do_proxy_confiavel_o_app_ve_o_ip_do_visitante_e_o_https(): void
    {
        $this->confiarNoProxy();

        $visto = $this->vindoDe(self::PROXY, $this->cabecalhosDoNginx(self::VISITANTE))->quemSouEu();

        $this->assertSame(self::VISITANTE, $visto['ip']);
        $this->assertTrue($visto['https'], 'O app não acreditou no X-Forwarded-Proto do proxy confiável.');
        $this->assertSame(self::HOST, $visto['host']);
        $this->assertSame('https://'.self::HOST.'/destino', $visto['link']);
    }

    public function test_o_hsts_sai_quando_o_proxy_confiavel_diz_que_era_https(): void
    {
        $this->confiarNoProxy();

        $this->vindoDe(self::PROXY, $this->cabecalhosDoNginx(self::VISITANTE))
            ->get('/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security');
    }

    public function test_a_prova_do_aceite_grava_o_ip_do_visitante_e_nao_o_do_proxy(): void
    {
        $this->confiarNoProxy();

        $this->vindoDe(self::PROXY, $this->cabecalhosDoNginx(self::VISITANTE))->post('/register', [
            'name' => 'Visitante',
            'email' => 'visitante@example.com',
            'password' => 'uma-senha-comprida',
            'terms' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            self::VISITANTE,
            User::where('email', 'visitante@example.com')->sole()->terms_accepted_ip,
            'A prova do aceite (LGPD) gravou o IP errado.',
        );
    }

    /**
     * O cabeçalho que o framework aceitaria por padrão e que o app recusa: com ele, quem
     * falasse com o proxy escolheria o caminho-base de todos os links gerados.
     */
    public function test_o_proxy_nao_escolhe_o_prefixo_dos_links(): void
    {
        $this->confiarNoProxy();

        $visto = $this->vindoDe(self::PROXY, $this->cabecalhosDoNginx(self::VISITANTE) + [
            'X-Forwarded-Prefix' => '/atacante',
        ])->quemSouEu();

        $this->assertSame('https://'.self::HOST.'/destino', $visto['link']);
    }

    // ------------------------------------------------------- limites de tentativa

    public function test_dois_visitantes_atras_do_mesmo_proxy_nao_dividem_o_limite(): void
    {
        $this->confiarNoProxy();

        for ($i = 0; $i < 5; $i++) {
            $this->vindoDe(self::PROXY, $this->cabecalhosDoNginx(self::VISITANTE))->cadastroVazio()->assertStatus(422);
        }

        $this->vindoDe(self::PROXY, $this->cabecalhosDoNginx(self::VISITANTE))->cadastroVazio()
            ->assertStatus(429);

        $this->vindoDe(self::PROXY, $this->cabecalhosDoNginx(self::OUTRO_VISITANTE))->cadastroVazio()
            ->assertStatus(422);
    }

    /**
     * O defeito que a configuração evita, registrado: sem proxy confiável, o limite de
     * cadastro (5 por minuto "por IP") passa a ser o do site inteiro.
     */
    public function test_sem_proxy_confiavel_o_site_inteiro_divide_um_limite_so(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->vindoDe(self::PROXY, $this->cabecalhosDoNginx(self::VISITANTE))->cadastroVazio()->assertStatus(422);
        }

        $this->vindoDe(self::PROXY, $this->cabecalhosDoNginx(self::OUTRO_VISITANTE))->cadastroVazio()
            ->assertStatus(429);
    }

    // ------------------------------------------------------- quem NÃO é o proxy

    public function test_de_quem_nao_e_o_proxy_os_cabecalhos_sao_ignorados(): void
    {
        $this->confiarNoProxy();

        $visto = $this->vindoDe(self::INTRUSO, $this->cabecalhosDoNginx(self::VISITANTE))->quemSouEu();

        $this->assertSame(self::INTRUSO, $visto['ip'], 'Um endereço fora da lista conseguiu escolher o próprio IP.');
        $this->assertFalse($visto['https'], 'Um endereço fora da lista conseguiu se dizer https.');

        $this->vindoDe(self::INTRUSO, $this->cabecalhosDoNginx(self::VISITANTE))
            ->get('/login')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_sem_trusted_proxies_nem_o_gateway_e_confiavel(): void
    {
        $this->assertNull($this->configComAmbiente('trustedproxy.php', ['TRUSTED_PROXIES' => null])['proxies']);
        $this->assertNull($this->configComAmbiente('trustedproxy.php', ['TRUSTED_PROXIES' => ''])['proxies']);
        $this->assertNull($this->configComAmbiente('trustedproxy.php', ['TRUSTED_PROXIES' => '  '])['proxies']);

        config(['trustedproxy.proxies' => null]);

        $visto = $this->vindoDe(self::PROXY, $this->cabecalhosDoNginx(self::VISITANTE))->quemSouEu();

        $this->assertSame(self::PROXY, $visto['ip']);
        $this->assertFalse($visto['https']);
    }

    // ------------------------------------------------------- a leitura da variável

    public function test_a_variavel_aceita_ips_e_faixas_separados_por_virgula(): void
    {
        $proxies = $this->configComAmbiente('trustedproxy.php', [
            'TRUSTED_PROXIES' => ' 172.16.80.1, 10.0.0.0/8 ,2001:db8::/32  fd00::1',
        ])['proxies'];

        $this->assertSame(['172.16.80.1', '10.0.0.0/8', '2001:db8::/32', 'fd00::1'], $proxies);
    }

    /**
     * O curinga do framework ("*": confia em QUALQUER um que se conecte) e o mesmo curinga
     * escrito como faixa não viram confiança — nem misturados com um valor bom.
     */
    public function test_curinga_e_lixo_nao_viram_confianca(): void
    {
        foreach (['*', '**', '0.0.0.0/0', '::/0', 'REMOTE_ADDR', 'nginx', '300.1.1.1', '10.0.0.0/33', '10.0.0.0/abc', '10.0.0.0/'] as $valor) {
            $this->assertNull(
                $this->configComAmbiente('trustedproxy.php', ['TRUSTED_PROXIES' => $valor])['proxies'],
                "TRUSTED_PROXIES={$valor} virou confiança.",
            );
        }

        $this->assertSame(
            [self::PROXY],
            $this->configComAmbiente('trustedproxy.php', ['TRUSTED_PROXIES' => '*,'.self::PROXY.',0.0.0.0/0'])['proxies'],
        );
    }

    public function test_curinga_na_variavel_nao_deixa_ninguem_forjar_o_ip(): void
    {
        config(['trustedproxy.proxies' => $this->configComAmbiente('trustedproxy.php', ['TRUSTED_PROXIES' => '*'])['proxies']]);

        $visto = $this->vindoDe(self::INTRUSO, $this->cabecalhosDoNginx(self::VISITANTE))->quemSouEu();

        $this->assertSame(self::INTRUSO, $visto['ip']);
        $this->assertFalse($visto['https']);
    }
}
