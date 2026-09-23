<?php

namespace Tests\Feature;

use App\Http\Middleware\BloqueiaUsuarioBanido;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Banido sem 2FA não recebe sessão no login (achado da rodada de 22-23/09/2026).
 *
 * O defeito: `LoginRequest::authenticate()` conferia a senha e chamava `login()` sem olhar o
 * banimento. A conta banida entrava — sessão aberta e, com "lembrar de mim" (a caixa vem
 * marcada por padrão), um remember token gravado no banco e o cookie emitido —, e só o
 * `BloqueiaUsuarioBanido`, na requisição SEGUINTE, a derrubava.
 *
 * O comportamento certo: com a senha conferida, o banido é barrado ANTES do `login()`, com o
 * recado de sempre (`BloqueiaUsuarioBanido::mensagem()`, sem o motivo). A senha vem antes do
 * banimento de propósito: com a senha errada, a resposta é a de sempre, e o recado de
 * suspensão não vira sonda de quem está banido. A tentativa conta no limite, como uma falha.
 */
class BanidoNaoRecebeSessaoNoLoginTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'uma-senha-qualquer-123';

    private const MOTIVO = 'Chargeback em série';

    private function banido(array $atributos = []): User
    {
        return User::factory()->create([
            'password' => Hash::make(self::SENHA),
            // Sem token de "lembrar de mim": é o `login(..., remember: true)` que o cria, e é
            // por ele nascer (ou não) que o teste enxerga se a conta chegou a entrar.
            'remember_token' => null,
            'banned_at' => now(),
            'banned_reason' => self::MOTIVO,
            ...$atributos,
        ]);
    }

    private function recaller(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    public function test_banido_sem_2fa_nao_recebe_sessao_nem_lembrar_de_mim(): void
    {
        $user = $this->banido();

        $resposta = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => self::SENHA,
            'remember' => 'on',
        ]);

        $resposta->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => BloqueiaUsuarioBanido::mensagem()])
            ->assertCookieMissing($this->recaller());

        $this->assertGuest('web');
        $this->assertNull($user->fresh()->remember_token, 'O banido ganhou um remember token no login barrado.');
        $this->assertStringNotContainsString(self::MOTIVO, (string) session('errors')->first('email'));
    }

    public function test_dependente_de_titular_banido_tambem_para_no_login(): void
    {
        $titular = $this->banido(['is_admin' => true]);
        $dependente = User::factory()->create([
            'password' => Hash::make(self::SENHA),
            'account_owner_id' => $titular->id,
            'is_admin' => false,
        ]);

        $this->from('/login')->post('/login', ['email' => $dependente->email, 'password' => self::SENHA])
            ->assertSessionHasErrors(['email' => BloqueiaUsuarioBanido::mensagem()]);

        $this->assertGuest('web');
    }

    /** Senha errada de conta banida: a resposta de sempre — a suspensão não vira sonda. */
    public function test_senha_errada_de_banido_recebe_a_resposta_de_sempre(): void
    {
        $user = $this->banido();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'outra-senha-errada'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest('web');
    }

    /** O login da tela usa AJAX (sm/auth.js): recebe o 422 com o recado, não um redirect. */
    public function test_login_por_ajax_do_banido_recebe_422_com_o_recado(): void
    {
        $user = $this->banido();

        $this->postJson('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', BloqueiaUsuarioBanido::mensagem());

        $this->assertGuest('web');
    }

    /**
     * Cada tentativa barrada conta no limite, como uma falha: sem isso, quem sabe a senha de
     * uma conta banida repetiria o login à vontade, e cada tentativa custa um argon2id.
     */
    public function test_tentativas_do_banido_contam_no_limite_do_login(): void
    {
        $user = $this->banido();

        for ($i = 1; $i <= 5; $i++) {
            $this->from('/login')->post('/login', ['email' => $user->email, 'password' => self::SENHA])
                ->assertSessionHasErrors(['email' => BloqueiaUsuarioBanido::mensagem()]);
        }

        $sexta = $this->from('/login')->post('/login', ['email' => $user->email, 'password' => self::SENHA]);

        $this->assertStringNotContainsString(
            'suspensa',
            (string) session('errors')?->first('email'),
            'A 6ª tentativa no mesmo minuto ainda conferiu a senha: o banido não conta no limite.',
        );
        $sexta->assertSessionHasErrors('email');
    }

    public function test_conta_sem_banimento_continua_entrando(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::SENHA)]);

        $this->post('/login', ['email' => $user->email, 'password' => self::SENHA])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user, 'web');
    }
}
