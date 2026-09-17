<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A-2 da auditoria de 05/09/2026: redefinir a senha pelo link ("esqueci a senha")
 * tem de derrubar TODAS as sessões da conta.
 *
 * É o caminho de quem já perdeu a conta para um invasor: a pessoa pede o link,
 * define a senha nova — e o invasor, que entrou antes, seguia logado com o cookie
 * que já tinha. A troca pelas Configurações (`PasswordController`) já derrubava
 * as outras sessões; o reset, que é justamente o caso mais grave, não.
 *
 * Diferença de propósito entre os dois caminhos: nas Configurações quem troca está
 * logado, e a sessão DELE é preservada. No reset ninguém está autenticado — não
 * existe sessão "atual" a poupar, então caem todas.
 *
 * As sessões são conferidas pelas linhas de `sessions` (driver `database`, o de
 * produção), mesmo padrão do `SecurityHardeningTest`: com `AuthenticateSession`
 * desligado, é apagar a linha que de fato desconecta o navegador.
 */
class RedefinirSenhaDerrubaSessoesTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA_NOVA = 'outra-senha-bem-comprida';

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    private function sessao(string $id, ?int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Teste)',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    private function redefinir(User $user, ?string $token = null): TestResponse
    {
        return $this->post(route('password.store'), [
            'token' => $token ?? app('auth.password.broker')->createToken($user),
            'email' => $user->email,
            'password' => self::SENHA_NOVA,
            'password_confirmation' => self::SENHA_NOVA,
        ]);
    }

    public function test_redefinir_pelo_link_derruba_todas_as_sessoes_da_conta(): void
    {
        $dono = User::factory()->create();
        $outraPessoa = User::factory()->create();

        $this->sessao('sessao-do-invasor', $dono->id);
        $this->sessao('sessao-no-celular-do-dono', $dono->id);
        $this->sessao('sessao-de-outra-pessoa', $outraPessoa->id);
        $this->sessao('visitante-sem-login', null);

        $this->redefinir($dono)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        // Todas as sessões da conta caem — inclusive a do próprio dono em outro
        // aparelho: não há como distinguir o invasor dele, e entrar de novo com a
        // senha nova é o preço certo.
        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-do-invasor']);
        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-no-celular-do-dono']);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $dono->id)->count());

        // Só as dele: ninguém mais é desconectado pelo reset alheio.
        $this->assertDatabaseHas('sessions', ['id' => 'sessao-de-outra-pessoa']);
        $this->assertDatabaseHas('sessions', ['id' => 'visitante-sem-login']);
    }

    /**
     * A tela de Segurança mostra a idade da senha a partir de `password_changed_at`.
     * Sem o carimbo, quem acabou de redefinir continuava lendo "alterada há um ano".
     */
    public function test_redefinir_pelo_link_grava_password_changed_at(): void
    {
        $this->freezeSecond();
        $dono = User::factory()->create(['password_changed_at' => now()->subYear()]);

        $this->redefinir($dono)->assertSessionHasNoErrors();

        $this->assertSame(
            now()->toDateTimeString(),
            $dono->fresh()->password_changed_at?->toDateTimeString(),
        );
    }

    /**
     * O cookie de "lembrar de mim" re-autentica SEM sessão nenhuma: apagar as linhas
     * de `sessions` não o alcança. Quem o invalida é o `remember_token` novo — o guard
     * só aceita o cookie se o token dele casar com o gravado (`retrieveByToken`).
     */
    public function test_redefinir_pelo_link_invalida_o_lembrar_de_mim_de_todos_os_aparelhos(): void
    {
        $dono = User::factory()->create(['remember_token' => 'token-do-cookie-do-invasor']);
        $provider = Auth::guard('web')->getProvider();

        // Controle: antes do reset, o cookie do invasor ainda abriria a conta.
        $this->assertNotNull($provider->retrieveByToken($dono->id, 'token-do-cookie-do-invasor'));

        $this->redefinir($dono)->assertSessionHasNoErrors();

        $this->assertNotSame('token-do-cookie-do-invasor', $dono->fresh()->remember_token);
        $this->assertNull(
            $provider->retrieveByToken($dono->id, 'token-do-cookie-do-invasor'),
            'O cookie de "lembrar de mim" emitido antes do reset não pode mais autenticar.',
        );
    }

    public function test_redefinir_pelo_link_continua_avisando_o_dono(): void
    {
        Mail::fake();
        $dono = User::factory()->create();
        $this->sessao('sessao-do-invasor', $dono->id);

        $this->redefinir($dono)->assertSessionHasNoErrors();

        Mail::assertSent(AlertaDeSeguranca::class, fn (AlertaDeSeguranca $mail) => $mail->hasTo($dono->email)
            && str_contains($mail->assunto, 'senha do Stabil Money foi redefinida'));
        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-do-invasor']);
    }

    /**
     * Derrubar as sessões só pode acontecer quando a redefinição DEU CERTO. Se valesse
     * para qualquer tentativa, bastaria saber o e-mail de alguém e mandar um token
     * inventado para desconectar a pessoa de todos os aparelhos, quantas vezes quisesse.
     */
    public function test_token_invalido_nao_derruba_ninguem_nem_muda_nada(): void
    {
        $dono = User::factory()->create([
            'password_changed_at' => null,
            'remember_token' => 'token-legitimo',
        ]);
        $this->sessao('sessao-legitima-do-dono', $dono->id);

        $this->redefinir($dono, token: 'token-inventado')->assertSessionHasErrors('email');

        $this->assertDatabaseHas('sessions', ['id' => 'sessao-legitima-do-dono']);
        $this->assertNull($dono->fresh()->password_changed_at);
        $this->assertSame('token-legitimo', $dono->fresh()->remember_token);
    }
}
