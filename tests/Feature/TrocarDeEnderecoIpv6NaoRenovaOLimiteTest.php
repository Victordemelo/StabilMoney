<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ChaveDeIp;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Trocar de endereço dentro da própria rede IPv6 não renova os limites de tentativa
 * (publicação na VPS, 24/09/2026).
 *
 * Atrás da Cloudflare o app passou a enxergar o IP REAL do visitante — e a Cloudflare
 * atende em IPv6 por padrão, repassando o endereço IPv6 no CF-Connecting-IP. Todos os
 * limites "por IP" (cadastro, "esqueci a senha", login, código do 2FA, painel) usavam o
 * endereço inteiro como chave. Só que em IPv6 quem recebe UM endereço recebe a rede
 * inteira: a menor rede entregue a uma casa, a um celular ou a uma VPS é um /64 — 2^64
 * endereços que o próprio visitante escolhe, sem pedir a ninguém. Cada endereço novo era
 * um balde novo, e o limite deixava de existir para quem tem IPv6:
 *
 *  - o código do 2FA (6 dígitos) tem limite por conta + IP justamente para a força bruta
 *    não achar o número (5/min e 20/h — "~0,1% de chance por dia"). Com um endereço por
 *    tentativa, quem já tem a senha tenta os 10^6 códigos na velocidade da rede;
 *  - o login (e-mail + IP, e o login-ip) protege a senha de UMA conta da mesma forma;
 *  - o cadastro e o "esqueci a senha" (5/min) custam um argon2id cada.
 *
 * A chave dos limites agora agrupa o IPv6 pela rede /64 (App\Support\ChaveDeIp). IPv4
 * continua endereço a endereço. E o teto por HORA do código do 2FA passou a ser da CONTA:
 * com muitas redes (um /48 de túnel IPv6 são 65.536 redes /64, uma botnet tem milhares de
 * IPv4), a cota por conta + rede se multiplicava do mesmo jeito.
 *
 * O visitante chega como em produção: vindo do nginx (o proxy confiável), com o IP no
 * X-Forwarded-For. Endereços de documentação (RFC 3849 e RFC 5737).
 */
class TrocarDeEnderecoIpv6NaoRenovaOLimiteTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY = '172.16.80.1';

    /** Uma rede /64 — a de UM visitante. */
    private const REDE = '2001:db8:aaaa:1';

    /** Outra rede /64, de outro visitante. */
    private const OUTRA_REDE = '2001:db8:aaaa:2';

    private const SENHA = 'password';

    protected function setUp(): void
    {
        parent::setUp();

        config(['trustedproxy.proxies' => [self::PROXY]]);
    }

    /** Uma requisição que chega pelo nginx, de <ip>. */
    private function de(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])->withHeaders([
            'X-Forwarded-For' => $ip,
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
        ]);
    }

    /** O n-ésimo endereço da rede <rede>. */
    private function endereco(string $rede, int $n): string
    {
        return $rede.'::'.dechex($n);
    }

    private function cadastroVazio(string $ip): TestResponse
    {
        // Sem campos: a validação recusa sem gastar um argon2id, mas o limite conta.
        return $this->de($ip)->postJson('/register', []);
    }

    // ------------------------------------------------------------ cadastro (5/min)

    public function test_trocar_de_endereco_dentro_da_mesma_rede_ipv6_nao_renova_o_limite_do_cadastro(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->cadastroVazio($this->endereco(self::REDE, $i))->assertStatus(422);
        }

        $this->cadastroVazio($this->endereco(self::REDE, 6))->assertStatus(429);
    }

    public function test_outra_rede_ipv6_tem_o_proprio_limite(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->cadastroVazio($this->endereco(self::REDE, $i))->assertStatus(422);
        }

        $this->cadastroVazio($this->endereco(self::OUTRA_REDE, 1))->assertStatus(422);
    }

    public function test_ipv4_continua_endereco_a_endereco(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->cadastroVazio('203.0.113.7')->assertStatus(422);
        }

        $this->cadastroVazio('203.0.113.7')->assertStatus(429);
        $this->cadastroVazio('203.0.113.8')->assertStatus(422);
    }

    // ------------------------------------------------ login: a senha de UMA conta

    public function test_trocar_de_endereco_ipv6_nao_renova_as_tentativas_de_senha_de_uma_conta(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $this->de($this->endereco(self::REDE, $i))
                ->postJson('/login', ['email' => $user->email, 'password' => 'senha-errada'])
                ->assertStatus(422)
                ->assertJsonPath('errors.email.0', trans('auth.failed'));
        }

        // A sexta, de um endereço novo da MESMA rede: já é o bloqueio, não "senha errada".
        $this->de($this->endereco(self::REDE, 6))
            ->postJson('/login', ['email' => $user->email, 'password' => 'senha-errada'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn (string $mensagem) => str_starts_with(
                $mensagem,
                'O número limite de tentativas de login foi atingido',
            ));
    }

    // ------------------------------------------ o código do 2FA (5/min + 20/h)

    public function test_trocar_de_endereco_ipv6_nao_renova_as_tentativas_do_codigo_do_2fa(): void
    {
        $user = $this->comDoisFatores();

        // Quem ataca já tem a senha: passa pela primeira etapa e fica com o login pendente.
        $this->de($this->endereco(self::REDE, 1))
            ->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('two-factor.login'));

        for ($i = 1; $i <= 5; $i++) {
            $this->de($this->endereco(self::REDE, $i))
                ->from(route('two-factor.login'))
                ->post(route('two-factor.login'), ['codigo' => '000000'])
                ->assertRedirect(route('two-factor.login'));
        }

        // Endereço novo, mesma rede, mesma sessão: o sexto palpite tem de ser barrado.
        $this->de($this->endereco(self::REDE, 6))
            ->post(route('two-factor.login'), ['codigo' => '000000'])
            ->assertStatus(429);

        $this->assertGuest();
    }

    /**
     * O teto por hora do código é da CONTA: espalhar os palpites por muitas redes (um /48
     * são 65.536 redes /64) não multiplica a cota.
     */
    public function test_espalhar_os_palpites_do_2fa_por_muitas_redes_nao_multiplica_o_teto_da_hora(): void
    {
        $user = $this->comDoisFatores();

        $this->de($this->endereco(self::REDE, 1))
            ->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('two-factor.login'));

        // 20 palpites, cada um de uma rede /64 diferente (nenhuma chega ao limite de minuto).
        for ($rede = 1; $rede <= 20; $rede++) {
            $this->de('2001:db8:bbbb:'.dechex($rede).'::1')
                ->from(route('two-factor.login'))
                ->post(route('two-factor.login'), ['codigo' => '000000'])
                ->assertRedirect(route('two-factor.login'));
        }

        $this->de('2001:db8:bbbb:ff::1')
            ->post(route('two-factor.login'), ['codigo' => '000000'])
            ->assertStatus(429);

        $this->assertGuest();
    }

    private function comDoisFatores(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_recovery_codes' => RecoveryCodes::gerar(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    // ------------------------------------------------------------ a chave em si

    public function test_a_chave_agrupa_o_ipv6_pela_rede_e_deixa_o_ipv4_como_esta(): void
    {
        // IPv6: a rede /64, qualquer que seja a grafia do endereço.
        $this->assertSame('2001:db8:aaaa:1::/64', ChaveDeIp::de('2001:db8:aaaa:1::1'));
        $this->assertSame('2001:db8:aaaa:1::/64', ChaveDeIp::de('2001:0DB8:AAAA:0001:ffff:ffff:ffff:ffff'));
        $this->assertNotSame(ChaveDeIp::de('2001:db8:aaaa:1::1'), ChaveDeIp::de('2001:db8:aaaa:2::1'));

        // IPv4: o endereço — inclusive quando chega escrito como IPv6. Sem isso, todo
        // "::ffff:x.x.x.x" cairia no MESMO /64 (os 64 primeiros bits são zero).
        $this->assertSame('203.0.113.7', ChaveDeIp::de('203.0.113.7'));
        $this->assertSame('203.0.113.7', ChaveDeIp::de('::ffff:203.0.113.7'));
        $this->assertNotSame(ChaveDeIp::de('::ffff:203.0.113.7'), ChaveDeIp::de('::ffff:198.51.100.23'));

        // Sem IP (requisição de console, teste): como veio, sem erro.
        $this->assertSame('', ChaveDeIp::de(null));
    }
}
