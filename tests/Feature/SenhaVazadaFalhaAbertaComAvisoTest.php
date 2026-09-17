<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\VerificadorDeSenhaVazada;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A checagem de senha vazada (`uncompromised()`, Pwned Passwords) continua FALHANDO
 * ABERTO — se a consulta cair, a senha é aceita — mas a falha passa a ficar no log.
 *
 * A decisão de manter a falha aberta é de disponibilidade: recusar a senha sempre que um
 * serviço de terceiro cai tiraria do ar o cadastro, a troca e a redefinição de senha. O
 * defeito era outro: o verificador do framework aceitava EM SILÊNCIO (HTTP 503/429 e
 * resposta que não é da API não deixavam rastro nenhum), e na falha de conexão o único
 * registro era um erro genérico com o prefixo do SHA-1 da senha na URL.
 *
 * Em teste a regra é desligada (`runningUnitTests`, no AppServiceProvider). Aqui ela é
 * religada com a rede SIMULADA (`Http::fake`) e `preventStrayRequests`: nenhum teste
 * daqui chega à internet.
 *
 * Os dois testes "continua" (senha vazada recusada, senha limpa aceita) passam também no
 * código antigo, de propósito: são a prova de que nada mudou para quem usa.
 */
class SenhaVazadaFalhaAbertaComAvisoTest extends TestCase
{
    use RefreshDatabase;

    /** O prefixo do SHA-1 dela (704F3) tem letra: não se confunde com número no log. */
    private const SENHA = 'SenhaQueNuncaVazou-9d2f';

    private const AVISO = 'Senha aceita SEM a checagem de vazamento';

    /** @var list<MessageLogged> */
    private array $registros = [];

    protected function setUp(): void
    {
        parent::setUp();

        Password::defaults(fn () => Password::min(8)->uncompromised());

        Http::preventStrayRequests();

        // Nada vai para o arquivo de log de verdade; cada registro fica capturado aqui.
        config(['logging.default' => 'null']);
        Log::listen(function (MessageLogged $registro) {
            $this->registros[] = $registro;
        });
    }

    private static function hash(string $senha): string
    {
        return strtoupper(sha1($senha));
    }

    /**
     * Corpo no formato da API: "SUFIXO:OCORRÊNCIAS" por linha (CRLF), com linhas de
     * padding (ocorrência 0) — é assim que ela responde quando o padding é pedido.
     *
     * @param  array<string, int>  $vazados  sufixo => ocorrências
     */
    private function respostaDaApi(array $vazados = []): void
    {
        $linhas = [];

        foreach ($vazados as $sufixo => $ocorrencias) {
            $linhas[] = $sufixo.':'.$ocorrencias;
        }

        for ($i = 0; $i < 20; $i++) {
            $linhas[] = strtoupper(substr(sha1('padding-'.$i), 0, 35)).':0';
        }

        Http::fake(['api.pwnedpasswords.com/*' => Http::response(implode("\r\n", $linhas))]);
    }

    private function politicaAceita(string $senha): bool
    {
        return Validator::make(['password' => $senha], ['password' => ['required', Password::defaults()]])->passes();
    }

    /** @return list<MessageLogged> os avisos de "senha aceita sem checagem" */
    private function avisos(): array
    {
        return array_values(array_filter(
            $this->registros,
            fn (MessageLogged $r) => $r->level === 'warning' && str_contains($r->message, self::AVISO),
        ));
    }

    // =========================================================== a peça certa no container

    /**
     * O binding original vem de um provider DIFERIDO, que se registra depois do nosso e
     * sobrescreveria um `singleton()` sem erro nenhum. Pedir o validador antes é o que o
     * dispara.
     */
    public function test_o_container_entrega_o_verificador_que_registra_a_falha(): void
    {
        app('validator');

        $this->assertInstanceOf(VerificadorDeSenhaVazada::class, app(UncompromisedVerifier::class));
    }

    // =========================================================== nada mudou para quem usa

    public function test_senha_vazada_continua_recusada(): void
    {
        $this->respostaDaApi([substr(self::hash(self::SENHA), 5) => 42]);

        $this->assertFalse($this->politicaAceita(self::SENHA));
        $this->assertSame([], $this->avisos(), 'Consulta que funcionou não é falha.');
        Http::assertSentCount(1);
    }

    public function test_senha_limpa_continua_aceita(): void
    {
        $this->respostaDaApi([substr(self::hash('outra-senha-qualquer'), 5) => 7]);

        $this->assertTrue($this->politicaAceita(self::SENHA));
        $this->assertSame([], $this->avisos(), 'Consulta que funcionou não é falha.');
        Http::assertSentCount(1);
    }

    /**
     * k-anonimato: só os 5 primeiros caracteres do hash saem daqui. E o padding pedido do
     * jeito que a API entende — "true", não o "1" em que o booleano do framework vira.
     */
    public function test_a_consulta_leva_so_o_prefixo_e_pede_o_padding_do_jeito_que_a_api_entende(): void
    {
        $hash = self::hash(self::SENHA);
        $this->respostaDaApi();

        $this->politicaAceita(self::SENHA);

        Http::assertSent(fn (RequisicaoHttp $requisicao) => $requisicao->url() === 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5)
            && ! str_contains(strtoupper($requisicao->url()), substr($hash, 5))
            && $requisicao->header('Add-Padding') === ['true']);
    }

    // =========================================================== a falha, agora visível

    public function test_falha_de_conexao_aceita_a_senha_e_deixa_aviso(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::failedConnection()]);

        $this->assertTrue($this->politicaAceita(self::SENHA), 'A falha continua ABERTA.');

        $avisos = $this->avisos();
        $this->assertCount(1, $avisos);
        $this->assertSame('conexao', $avisos[0]->context['motivo']);
        $this->assertStringContainsString('ConnectionException', $avisos[0]->context['erro']);
        $this->assertIsInt($avisos[0]->context['tempo_ms']);
    }

    /** Os casos que o verificador do framework aceitava sem deixar NADA no log. */
    #[DataProvider('servicoRespondendoErro')]
    public function test_servico_respondendo_erro_aceita_a_senha_e_deixa_aviso(int $status): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', $status)]);

        $this->assertTrue($this->politicaAceita(self::SENHA), 'A falha continua ABERTA.');

        $avisos = $this->avisos();
        $this->assertCount(1, $avisos);
        $this->assertSame('http', $avisos[0]->context['motivo']);
        $this->assertSame($status, $avisos[0]->context['status']);
    }

    public static function servicoRespondendoErro(): array
    {
        return [
            'limite da API (429)' => [429],
            'erro interno (500)' => [500],
            'fora do ar (503)' => [503],
        ];
    }

    /**
     * 200 com uma página qualquer (portal cativo de Wi-Fi, proxy): com o padding pedido, a
     * API nunca responde sem linhas no formato dela. Ler isso como "não vazou" seria a
     * aprovação silenciosa de novo.
     */
    public function test_resposta_que_nao_e_da_api_aceita_a_senha_e_deixa_aviso(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('<html><body>Entre na rede Wi-Fi</body></html>', 200)]);

        $this->assertTrue($this->politicaAceita(self::SENHA), 'A falha continua ABERTA.');

        $avisos = $this->avisos();
        $this->assertCount(1, $avisos);
        $this->assertSame('resposta_invalida', $avisos[0]->context['motivo']);
    }

    /**
     * O log não pode virar vazamento: nem a senha, nem o hash, nem o PREFIXO do hash — que
     * vinha na mensagem do cURL ("... for https://api.pwnedpasswords.com/range/704F3").
     */
    public function test_nenhum_registro_de_log_leva_a_senha_o_hash_ou_o_prefixo(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::failedConnection()]);
        $hash = self::hash(self::SENHA);

        $this->politicaAceita(self::SENHA);

        $this->assertNotEmpty($this->registros, 'Sem registro nenhum, este teste não provaria nada.');

        foreach ($this->registros as $registro) {
            $texto = $registro->message.' '.json_encode($registro->context);

            $this->assertStringNotContainsString(self::SENHA, $texto);
            $this->assertFalse(stripos($texto, $hash), 'O hash da senha foi para o log.');
            $this->assertFalse(stripos($texto, substr($hash, 0, 5)), 'O prefixo do hash da senha foi para o log: '.$registro->message);
        }
    }

    // =========================================================== pelos fluxos de verdade

    public function test_cadastro_segue_no_ar_com_a_consulta_fora_e_o_aviso_diz_de_onde_veio(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);

        $this->post('/register', [
            'name' => 'Pessoa Nova',
            'email' => 'nova@example.com',
            'password' => self::SENHA,
            'terms' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'nova@example.com']);

        $avisos = $this->avisos();
        $this->assertCount(1, $avisos);
        $this->assertSame('POST register', $avisos[0]->context['origem']);
        $this->assertNull($avisos[0]->context['user_id']);
    }

    public function test_cadastro_com_senha_vazada_continua_recusado(): void
    {
        $this->respostaDaApi([substr(self::hash(self::SENHA), 5) => 3]);

        $this->post('/register', [
            'name' => 'Pessoa Nova',
            'email' => 'nova@example.com',
            'password' => self::SENHA,
            'terms' => '1',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'nova@example.com']);
    }

    /** Numa troca feita por quem está logado, o aviso diz QUEM ficou com a senha sem checagem. */
    public function test_troca_de_senha_com_a_consulta_fora_registra_quem_trocou(): void
    {
        $user = User::factory()->create();
        Http::fake(['api.pwnedpasswords.com/*' => Http::failedConnection()]);

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => self::SENHA,
            'password_confirmation' => self::SENHA,
        ])->assertSessionHasNoErrors();

        $avisos = $this->avisos();
        $this->assertCount(1, $avisos);
        $this->assertSame('PUT password', $avisos[0]->context['origem']);
        $this->assertSame($user->id, $avisos[0]->context['user_id']);
    }
}
