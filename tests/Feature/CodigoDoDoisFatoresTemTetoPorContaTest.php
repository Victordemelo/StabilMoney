<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * O código de 6 dígitos tem teto de tentativas POR CONTA — e não só por conta + IP.
 *
 * O defeito: os limites do desafio (`dois-fatores` no app, `painel-totp` no painel) eram
 * chaveados pelo IP (o do app, por conta + IP). Quem já tem a SENHA — exatamente o caso que
 * o segundo fator existe para barrar — ganhava uma cota nova a cada endereço: 20 códigos por
 * hora por IP. Com IPv6 isso não custa nada (uma VPS comum vem com um /64, e a Cloudflare
 * repassa ao app o IPv6 de cada visitante), e 1.000 endereços são 20.000 códigos por hora:
 * ~6% de acertar o código na primeira hora, ~76% num dia. A conta de "~0,1% ao dia" do
 * AppServiceProvider só valia para quem atacasse de UM endereço.
 *
 * Agora a conta tem teto próprio, seja qual for o IP de onde os códigos vêm.
 *
 * Preço aceito: quem tem a senha consegue gastar a cota da conta e deixar o dono sem
 * completar o login por até uma hora. É o lado seguro — a alternativa era deixar o código
 * ser adivinhado —, e quem tem a senha de alguém é, de todo modo, emergência: o dono troca a
 * senha, o que já derruba o login pendente do atacante.
 */
class CodigoDoDoisFatoresTemTetoPorContaTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'password';

    private const SENHA_DO_PAINEL = 'senha-de-teste-bem-comprida';

    private function comDoisFatores(): User
    {
        $user = User::factory()->create();

        $user->forceFill([
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_recovery_codes' => RecoveryCodes::gerar(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    /** Um número de 6 dígitos que o autenticador NÃO aceitaria agora. */
    private function codigoErrado(string $segredo): string
    {
        $validos = array_map(
            fn (int $delta) => Totp::codigo($segredo, Totp::passoAtual() + $delta),
            range(-Totp::JANELA - 1, Totp::JANELA + 1),
        );

        $n = 0;
        while (in_array(sprintf('%06d', $n), $validos, true)) {
            $n++;
        }

        return sprintf('%06d', $n);
    }

    /** Passa a falar de outro endereço — é o que o atacante faz a cada lote de chutes. */
    private function doIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    // =========================================================== app

    public function test_trocar_de_ip_nao_renova_a_cota_de_codigos_da_conta(): void
    {
        $user = $this->comDoisFatores();
        $errado = $this->codigoErrado($user->two_factor_secret);

        // Quatro endereços, cinco chutes cada (o limite por minuto de cada um): 20 códigos
        // na mesma hora — o teto que o limitador promete.
        foreach (['2001:db8::1', '2001:db8::2', '2001:db8::3', '2001:db8::4'] as $ip) {
            $this->doIp($ip)
                ->post('/login', ['email' => $user->email, 'password' => self::SENHA])
                ->assertRedirect(route('two-factor.login'));

            for ($i = 0; $i < 5; $i++) {
                $this->doIp($ip)
                    ->from(route('two-factor.login'))
                    ->post(route('two-factor.login'), ['codigo' => $errado])
                    ->assertRedirect(route('two-factor.login'));
            }
        }

        // Um quinto endereço, na mesma hora: a conta já gastou a cota dela.
        $this->doIp('2001:db8::5')
            ->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertRedirect(route('two-factor.login'));

        $this->doIp('2001:db8::5')
            ->post(route('two-factor.login'), ['codigo' => $errado])
            ->assertStatus(429);

        $this->assertGuest();
    }

    /** O teto novo não mexe no de sempre: o primeiro endereço continua com 5 por minuto. */
    public function test_o_limite_por_minuto_de_cada_endereco_continua_valendo(): void
    {
        $user = $this->comDoisFatores();
        $errado = $this->codigoErrado($user->two_factor_secret);

        $this->doIp('2001:db8::1')->post('/login', ['email' => $user->email, 'password' => self::SENHA]);

        for ($i = 0; $i < 5; $i++) {
            $this->doIp('2001:db8::1')
                ->from(route('two-factor.login'))
                ->post(route('two-factor.login'), ['codigo' => $errado])
                ->assertRedirect(route('two-factor.login'));
        }

        $this->doIp('2001:db8::1')
            ->post(route('two-factor.login'), ['codigo' => $errado])
            ->assertStatus(429);
    }

    /** A cota é da CONTA: o limite de uma conta não tranca o desafio de outra. */
    public function test_a_cota_de_uma_conta_nao_tranca_outra(): void
    {
        $alvo = $this->comDoisFatores();
        $outra = $this->comDoisFatores();
        $errado = $this->codigoErrado($alvo->two_factor_secret);

        foreach (['2001:db8::1', '2001:db8::2', '2001:db8::3', '2001:db8::4'] as $ip) {
            $this->doIp($ip)->post('/login', ['email' => $alvo->email, 'password' => self::SENHA]);

            for ($i = 0; $i < 5; $i++) {
                $this->doIp($ip)
                    ->from(route('two-factor.login'))
                    ->post(route('two-factor.login'), ['codigo' => $errado]);
            }
        }

        $this->doIp('2001:db8::9')
            ->post('/login', ['email' => $outra->email, 'password' => self::SENHA])
            ->assertRedirect(route('two-factor.login'));

        $this->doIp('2001:db8::9')
            ->post(route('two-factor.login'), ['codigo' => Totp::codigo($outra->two_factor_secret, Totp::passoAtual())])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($outra);
    }

    // =========================================================== painel

    public function test_trocar_de_ip_nao_renova_a_cota_de_codigos_do_admin(): void
    {
        config(['admin.enabled' => true]);
        Mail::fake();

        $admin = Admin::factory()->comDoisFatores()->create([
            'email' => 'chefe@exemplo.com',
            'password' => Hash::make(self::SENHA_DO_PAINEL),
        ])->fresh();

        $errado = $this->codigoErrado($admin->two_factor_secret);

        // Três endereços com a cota do minuto cheia (3 cada) e um quarto com um chute: 10
        // códigos na mesma hora — o teto por hora do painel.
        foreach (['2001:db8::1' => 3, '2001:db8::2' => 3, '2001:db8::3' => 3, '2001:db8::4' => 1] as $ip => $chutes) {
            $this->doIp($ip)
                ->post(route('painel.autenticar'), ['email' => 'chefe@exemplo.com', 'password' => self::SENHA_DO_PAINEL])
                ->assertRedirect(route('painel.home'));

            for ($i = 0; $i < $chutes; $i++) {
                $this->doIp($ip)
                    ->from(route('painel.2fa.desafio'))
                    ->post(route('painel.2fa.verificar'), ['codigo' => $errado])
                    ->assertRedirect(route('painel.2fa.desafio'));
            }
        }

        $this->doIp('2001:db8::5')
            ->post(route('painel.autenticar'), ['email' => 'chefe@exemplo.com', 'password' => self::SENHA_DO_PAINEL])
            ->assertRedirect(route('painel.home'));

        $this->doIp('2001:db8::5')
            ->post(route('painel.2fa.verificar'), ['codigo' => $errado])
            ->assertStatus(429);

        $this->assertNull(session('admin_2fa_ok'));
    }
}
