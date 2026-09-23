<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PasswordController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Senha nova igual à atual é recusada (achado da rodada de 23/09/2026).
 *
 * O defeito: trocar a senha pela MESMA passava. A troca derrubava as outras sessões, trocava
 * o "lembrar de mim" e mandava o alerta "sua senha foi alterada" — sem que nada tivesse
 * mudado. E o pior caso é justamente o de quem troca a senha por suspeitar de invasão: digitando
 * a mesma, a pessoa achava que tinha trancado o invasor para fora, e ele entrava de novo com a
 * senha que já conhecia. Na redefinição pelo link (o caminho de quem PERDEU a conta) é igual.
 *
 * O comportamento certo: as duas portas recusam com a mesma mensagem, sem mexer em nada —
 * nem senha, nem sessões, nem token, nem alerta. Na redefinição, a checagem só roda com o
 * token VÁLIDO: sem ele, quem soubesse um e-mail testaria candidatas à senha da conta.
 */
class SenhaNovaIgualAAtualTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'smtp', 'session.driver' => 'database']);
        Mail::fake();

        $this->user = User::factory()->create([
            'email' => 'ana@exemplo.test',
            'password' => 'senha-de-sempre',
            'remember_token' => 'token-de-lembrar-antigo',
        ]);

        // Uma sessão aberta em outro aparelho: é ela que a troca de verdade derrubaria.
        DB::table('sessions')->insert([
            'id' => 'sessao-do-outro-aparelho',
            'user_id' => $this->user->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Outro navegador',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    /** Nada mudou: senha, "lembrar de mim", a sessão do outro aparelho, e nenhum alerta. */
    private function assertNadaMudou(): void
    {
        $user = $this->user->fresh();
        $this->assertTrue(Hash::check('senha-de-sempre', $user->password));
        $this->assertSame('token-de-lembrar-antigo', $user->getRememberToken());
        $this->assertDatabaseHas('sessions', ['id' => 'sessao-do-outro-aparelho']);
        Mail::assertNothingSent();
    }

    public function test_trocar_pelas_configuracoes_para_a_mesma_senha_e_recusado(): void
    {
        $this->actingAs($this->user)
            ->from('/configuracoes')
            ->put('/password', [
                'current_password' => 'senha-de-sempre',
                'password' => 'senha-de-sempre',
                'password_confirmation' => 'senha-de-sempre',
            ])
            ->assertRedirect('/configuracoes')
            ->assertSessionHasErrorsIn('updatePassword', ['password' => PasswordController::MENSAGEM_SENHA_REPETIDA])
            ->assertSessionMissing('status');

        $this->assertNadaMudou();
    }

    /** Controle: senha diferente continua trocando como sempre. */
    public function test_trocar_pelas_configuracoes_para_outra_senha_continua_valendo(): void
    {
        $this->actingAs($this->user)
            ->from('/configuracoes')
            ->put('/password', [
                'current_password' => 'senha-de-sempre',
                'password' => 'senha-bem-diferente',
                'password_confirmation' => 'senha-bem-diferente',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('senha-bem-diferente', $this->user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-do-outro-aparelho']);
    }

    public function test_redefinir_pelo_link_para_a_mesma_senha_e_recusado_e_o_link_continua_valendo(): void
    {
        $token = Password::broker()->createToken($this->user);

        $this->from(route('password.reset', $token))
            ->post(route('password.store'), [
                'token' => $token,
                'email' => 'ana@exemplo.test',
                'password' => 'senha-de-sempre',
                'password_confirmation' => 'senha-de-sempre',
            ])
            ->assertRedirect(route('password.reset', $token))
            ->assertSessionHasErrors(['password' => PasswordController::MENSAGEM_SENHA_REPETIDA]);

        $this->assertNadaMudou();

        // O link não foi gasto: com outra senha, a redefinição sai na mesma hora.
        $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'ana@exemplo.test',
            'password' => 'senha-bem-diferente',
            'password_confirmation' => 'senha-bem-diferente',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('senha-bem-diferente', $this->user->fresh()->password));
    }

    /**
     * Sem o token válido, a resposta é a de sempre ("token inválido"), com a senha certa ou
     * não: a comparação só existe para quem já tem o link.
     */
    public function test_sem_token_valido_a_comparacao_nao_vira_sonda_da_senha(): void
    {
        $tentar = fn (string $senha) => $this->from('/reset-password/qualquer')
            ->post(route('password.store'), [
                'token' => 'token-inventado',
                'email' => 'ana@exemplo.test',
                'password' => $senha,
                'password_confirmation' => $senha,
            ]);

        $comASenhaCerta = $tentar('senha-de-sempre');
        $erroComACerta = session('errors')->getBag('default')->toArray();
        $this->flushSession();
        $comOutra = $tentar('um-chute-qualquer');
        $erroComOutra = session('errors')->getBag('default')->toArray();

        $this->assertSame($erroComOutra, $erroComACerta);
        $this->assertArrayNotHasKey('password', $erroComACerta);
        $comASenhaCerta->assertSessionHasErrors('email');
        $comOutra->assertSessionHasErrors('email');
        $this->assertNadaMudou();
    }
}
