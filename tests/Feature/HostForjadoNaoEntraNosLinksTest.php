<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\EnderecoPublico;
use App\Support\VerificadorDeSenhaVazada;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Host forjado não entra nos links dos e-mails ("password reset poisoning" — publicação na
 * VPS, 23/09/2026).
 *
 * O link do "Esqueci a senha" era montado com o Host da REQUISIÇÃO: um POST em
 * /forgot-password com o e-mail da vítima e `Host: atacante.example` fazia a vítima receber,
 * do nosso servidor, um link para atacante.example/reset-password/<token>. O mesmo valia para
 * o link de confirmação do cadastro e o da troca de e-mail. A Cloudflare só entrega o nosso
 * Host, mas quem fala direto com o IP da VPS manda o que quiser.
 *
 * Em produção são duas travas no app, e cada uma segura sozinha:
 * - `TrustHosts`: Host diferente do APP_URL recebe 400 antes de qualquer rota;
 * - raiz fixa (`EnderecoPublico::fixarEmProducao`): todo link sai com o APP_URL, em https,
 *   mesmo que um Host forjado passasse.
 * Fora de produção, nenhuma das duas: em dev os links seguem o endereço aberto (localhost ou
 * o IP da máquina no Wi-Fi, para testar no celular).
 *
 * A produção é simulada trocando o ambiente do app DEPOIS do boot (subir a aplicação inteira
 * em produção faria o RefreshDatabase parar para perguntar se pode migrar). O boot do
 * AppServiceProvider já passou, então a raiz fixa é aplicada pela MESMA função que ele chama.
 */
class HostForjadoNaoEntraNosLinksTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'https://stabilmoney.victordemelo.com.br';

    private const HOST_FORJADO = 'atacante.example';

    /** @var list<string> corpo (texto + HTML) de cada e-mail que saiu durante o teste */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageSending::class, function (MessageSending $envio) {
            $this->emails[] = $envio->message->getTextBody().'|'.$envio->message->getHtmlBody();
        });
    }

    private function emProducao(): void
    {
        config(['app.url' => self::APP_URL]);
        $this->app['env'] = 'production';
        EnderecoPublico::fixarEmProducao($this->app);

        // Em produção o CSRF vale de verdade (nos testes o framework o pula). Estes testes
        // são sobre o Host, não sobre o token.
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /** Desliga a primeira trava para provar que a segunda segura sozinha. */
    private function semTrustHosts(): void
    {
        $this->withoutMiddleware(TrustHosts::class);
    }

    private function forjado(string $caminho): string
    {
        return 'http://'.self::HOST_FORJADO.$caminho;
    }

    private function assertEmailComLinkDoApp(string $caminhoDoLink): void
    {
        $this->assertNotEmpty($this->emails, 'Nenhum e-mail saiu — o teste não provou nada.');

        $todos = implode("\n", $this->emails);

        $this->assertStringNotContainsString(self::HOST_FORJADO, $todos, 'Um e-mail saiu com o Host forjado.');
        $this->assertStringContainsString(self::APP_URL.$caminhoDoLink, $todos, 'O link não saiu com o APP_URL.');
    }

    // ------------------------------------------------ trava 1: o Host é recusado

    public function test_em_producao_host_forjado_e_recusado_antes_de_qualquer_rota(): void
    {
        $user = User::factory()->create();
        $this->emProducao();

        $this->post($this->forjado('/forgot-password'), ['email' => $user->email])->assertStatus(400);

        $this->assertSame([], $this->emails, 'O pedido com Host forjado chegou a enviar e-mail.');
    }

    public function test_em_producao_o_host_do_app_url_passa_e_o_up_responde(): void
    {
        $this->emProducao();

        $this->get(self::APP_URL.'/up')->assertOk()->assertSeeText('ok');
        $this->get(self::APP_URL.'/login')->assertOk();

        // É assim que o scripts/deploy.sh confere o app, direto na porta do container:
        // http e com o Host do app no cabeçalho.
        $this->get('http://stabilmoney.victordemelo.com.br/up')->assertOk();
    }

    public function test_em_producao_host_diferente_leva_400_ate_no_up(): void
    {
        $this->emProducao();

        $this->get('http://127.0.0.1:8081/up')->assertStatus(400);
    }

    /**
     * APP_URL sem host em produção: nenhum Host passa. Com a lista vazia, o TrustHosts do
     * framework voltaria a aceitar QUALQUER um — a porta aberta sem aviso.
     */
    public function test_em_producao_sem_host_no_app_url_nenhum_host_passa(): void
    {
        $this->emProducao();
        config(['app.url' => '']);

        $this->assertSame([EnderecoPublico::NENHUM_HOST], EnderecoPublico::padroesDeHostConfiavel());
        $this->get('http://stabilmoney.victordemelo.com.br/up')->assertStatus(400);
    }

    public function test_o_padrao_aceito_e_so_o_host_exato_do_app_url(): void
    {
        config(['app.url' => 'https://Stabilmoney.Victordemelo.com.br/']);

        $this->assertSame(['^stabilmoney\.victordemelo\.com\.br$'], EnderecoPublico::padroesDeHostConfiavel());

        $this->emProducao();

        // Sem subdomínios, e o ponto é ponto mesmo (não "qualquer caractere").
        $this->get('https://x.stabilmoney.victordemelo.com.br/up')->assertStatus(400);
        $this->get('https://stabilmoneyXvictordemelo.com.br/up')->assertStatus(400);
    }

    // ------------------------------------------ trava 2: os links saem do APP_URL

    public function test_em_producao_o_link_de_redefinicao_de_senha_usa_o_app_url(): void
    {
        $user = User::factory()->create();
        $this->emProducao();
        $this->semTrustHosts();

        $this->post($this->forjado('/forgot-password'), ['email' => $user->email])->assertSessionHasNoErrors();

        $this->assertEmailComLinkDoApp('/reset-password/');
    }

    public function test_em_producao_o_link_de_confirmacao_do_cadastro_usa_o_app_url(): void
    {
        $this->emProducao();
        $this->semTrustHosts();

        // Em produção a política de senha consulta o Have I Been Pwned. Uma resposta no
        // formato da API, sem esta senha, para o teste não depender de rede.
        Http::fake([VerificadorDeSenhaVazada::ENDERECO.'*' => Http::response(str_repeat('0', 35).":1\r\n")]);

        $this->post($this->forjado('/register'), [
            'name' => 'Pessoa Nova',
            'email' => 'nova@example.com',
            'password' => 'uma-senha-comprida',
            'terms' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertEmailComLinkDoApp('/verify-email/');
    }

    public function test_em_producao_o_link_da_troca_de_email_usa_o_app_url(): void
    {
        $user = User::factory()->create(['email' => 'antigo@example.com', 'password' => 'senha-real']);
        $this->emProducao();
        $this->semTrustHosts();

        $this->actingAs($user)->patch($this->forjado('/meu-perfil'), [
            'name' => $user->name,
            'email' => 'novo@example.com',
            'current_password' => 'senha-real',
        ])->assertSessionHasNoErrors();

        $this->assertEmailComLinkDoApp('/meu-perfil/confirmar-email/');
    }

    /** Até com o TRUSTED_PROXIES errado (requisição vista como http), os links saem em https. */
    public function test_em_producao_os_links_da_pagina_saem_em_https(): void
    {
        $this->emProducao();

        $html = $this->get('http://stabilmoney.victordemelo.com.br/login')->assertOk()->getContent();

        $this->assertStringContainsString(self::APP_URL.'/forgot-password', $html);
        $this->assertStringNotContainsString('http://stabilmoney.victordemelo.com.br', $html);
    }

    // ------------------------------------------------ fora de produção: como sempre

    /**
     * Em desenvolvimento o app é aberto pelo IP da máquina no Wi-Fi (testar no celular): o
     * Host "desconhecido" tem de passar, e o link tem de apontar para o endereço aberto.
     */
    public function test_em_desenvolvimento_os_links_seguem_o_endereco_aberto(): void
    {
        $user = User::factory()->create();
        $this->app['env'] = 'local';
        EnderecoPublico::fixarEmProducao($this->app);
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->post('http://192.168.0.10:8001/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotEmpty($this->emails);
        $this->assertStringContainsString('http://192.168.0.10:8001/reset-password/', implode("\n", $this->emails));
    }
}
