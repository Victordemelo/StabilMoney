<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Lembrar de mim" vale 7 dias e depois pede a senha de novo (out/2026 — decisão do Victor).
 *
 * O padrão do Laravel é um cookie de 400 dias, e o prazo do cookie é só um pedido ao navegador:
 * quem copiou o valor o reenvia quando quiser. Por isso o prazo viaja DENTRO do valor
 * (`id|token|hash|expira`, cifrado e autenticado pelo EncryptCookies) e o servidor o confere
 * (`App\Auth\GuardaDeSessao`). O formato antigo, sem prazo, não entra mais.
 */
class LembrarDeMimValeSeteDiasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    public function test_o_cookie_dura_sete_dias_e_leva_o_prazo_dentro_do_valor(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();

        $resposta = $this->entrarLembrando($user);
        $cookie = $resposta->getCookie(Auth::guard('web')->getRecallerName());

        $this->assertNotNull($cookie);
        $this->assertSame(now()->addDays(7)->getTimestamp(), $cookie->getExpiresTime(), 'O cookie deve expirar em 7 dias, não em 400.');

        $partes = explode('|', $cookie->getValue());
        $this->assertCount(4, $partes);
        $this->assertSame((string) $user->id, $partes[0]);
        $this->assertSame((string) now()->addDays(7)->getTimestamp(), $partes[3]);
    }

    public function test_dentro_dos_sete_dias_entra_sem_senha(): void
    {
        $user = User::factory()->create();
        $lembrar = $this->lembrarDe($this->entrarLembrando($user));

        $this->travel(6)->days();
        $this->sessaoExpira();

        $this->voltarCom($lembrar)->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_depois_dos_sete_dias_pede_a_senha_e_apaga_o_cookie(): void
    {
        $user = User::factory()->create();
        $lembrar = $this->lembrarDe($this->entrarLembrando($user));

        // Mesmo que o navegador (ou quem copiou o cookie) continue mandando o valor.
        $this->travel(7)->days();
        $this->travel(1)->minutes();
        $this->sessaoExpira();

        $resposta = $this->voltarCom($lembrar)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        $apagado = $resposta->getCookie(Auth::guard('web')->getRecallerName(), false);
        $this->assertNotNull($apagado, 'O cookie vencido deveria ser apagado do navegador.');
        $this->assertLessThan(now()->getTimestamp(), $apagado->getExpiresTime());
    }

    public function test_o_formato_antigo_sem_prazo_nao_entra_mais(): void
    {
        $user = User::factory()->create();
        $guarda = Auth::guard('web');
        $hash = (fn ($senha) => $this->hashPasswordForCookie($senha))->call($guarda, $user->getAuthPassword());

        $this->voltarCom("{$user->id}|{$user->getRememberToken()}|{$hash}")
            ->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_um_prazo_que_nao_e_numero_nao_entra(): void
    {
        $user = User::factory()->create();
        $lembrar = $this->lembrarDe($this->entrarLembrando($user));
        [$id, $token, $hash] = explode('|', $lembrar);

        $this->voltarCom("{$id}|{$token}|{$hash}|amanha")->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_tela_de_login_diz_quanto_tempo_vale(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Lembrar de mim por 7 dias');
    }

    // ══════════════════════════════════════════════════ navegador

    private function entrarLembrando(User $user): TestResponse
    {
        return $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on'])
            ->assertRedirect(route('dashboard', absolute: false));
    }

    private function lembrarDe(TestResponse $resposta): string
    {
        $cookie = $resposta->getCookie(Auth::guard('web')->getRecallerName());
        $this->assertNotNull($cookie, 'O login com a caixa marcada não emitiu o "lembrar de mim".');

        return $cookie->getValue();
    }

    private function sessaoExpira(): void
    {
        DB::table('sessions')->delete();
    }

    /** Navegador sem sessão nenhuma, só com o cookie de "lembrar de mim". */
    private function voltarCom(string $lembrar): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['cookie']->flushQueuedCookies();
        $this->app['session']->driver()->flush();
        $this->defaultCookies = [];

        return $this->withCookie(Auth::guard('web')->getRecallerName(), $lembrar);
    }
}
