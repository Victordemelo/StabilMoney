<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Models\User;
use App\Notifications\RedefinicaoDeSenha;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Recuperar senha" com confirmação e espera de 60 s (out/2026 — pedido do Victor depois do
 * primeiro teste em produção). Antes a tela dizia "enviado" e deixava pedir na hora, para outro
 * e-mail. Agora:
 *  - depois do pedido, o formulário dá lugar à confirmação com o e-mail informado;
 *  - "Reenviar" (o MESMO e-mail) e "Usar outro e-mail" só depois de 60 s;
 *  - a espera vale no SERVIDOR, por sessão e por rede — um POST direto não a pula;
 *  - continua sem revelar quem tem conta: a mesma tela e a mesma espera para todo e-mail.
 */
class RecuperarSenhaComEsperaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->freezeSecond(); // os segundos de espera contados no teste são exatos
    }

    private function pedir(string $email, string $ip = '198.51.100.10'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from(route('password.request'))->post(route('password.email'), ['email' => $email]);
    }

    private function tela(array $query = []): HTMLDocument
    {
        $html = $this->get(route('password.request', $query))->assertOk()->getContent();

        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    public function test_depois_do_pedido_a_tela_mostra_o_email_e_o_reenviar_espera_60_segundos(): void
    {
        $this->pedir('victor@exemplo.test')->assertRedirect(route('password.request'))->assertSessionHasNoErrors();

        $doc = $this->tela();
        $this->assertStringContainsString('Confira seu e-mail', $doc->body->textContent);
        $this->assertSame('victor@exemplo.test', trim($doc->querySelector('.ac-email')->textContent));
        $this->assertNull($doc->getElementById('email'), 'O formulário para outro e-mail não pode continuar na tela.');

        $form = $doc->querySelector('form[data-reenviar-link]');
        $this->assertSame('60', $form->getAttribute('data-espera'));
        $this->assertSame('victor@exemplo.test', $form->querySelector('input[name="email"]')->getAttribute('value'),
            'O reenvio vai para o MESMO e-mail.');
        $this->assertSame('Reenviar em 60 s', trim($form->querySelector('[data-reenviar-rotulo]')->textContent));
        // Sem JS o botão fica ligado: quem decide é o servidor, que diz quanto falta.
        $this->assertFalse($form->querySelector('[data-reenviar-botao]')->hasAttribute('disabled'));
        $this->assertTrue($doc->querySelector('[data-outro-email]')->hasAttribute('hidden'));
    }

    public function test_pedir_de_novo_antes_de_60_segundos_e_recusado_no_servidor(): void
    {
        $user = User::factory()->create(['email' => 'victor@exemplo.test']);
        $this->pedir($user->email);

        $this->travel(15)->seconds();
        $this->pedir($user->email)
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors(['email' => PasswordResetLinkController::mensagemDeEspera(45)]);

        Notification::assertSentToTimes($user, RedefinicaoDeSenha::class, 1);
    }

    public function test_descartar_a_sessao_nao_pula_a_espera_a_rede_tambem_espera(): void
    {
        $user = User::factory()->create(['email' => 'victor@exemplo.test']);
        $this->pedir($user->email);

        // Um script que joga fora o cookie a cada POST: mesma rede, sessão nova.
        $this->flushSession();
        $this->pedir('outra@exemplo.test')->assertSessionHasErrors('email');

        // De outra rede e outra sessão, outra pessoa pede normalmente.
        $this->flushSession();
        $this->pedir('outra@exemplo.test', '203.0.113.50')->assertSessionHasNoErrors();
    }

    public function test_a_espera_e_a_mesma_exista_ou_nao_a_conta(): void
    {
        $user = User::factory()->create(['email' => 'existe@exemplo.test']);

        $this->pedir($user->email, '198.51.100.1');
        $this->travel(10)->seconds();
        $existe = $this->pedir($user->email, '198.51.100.1');

        $this->flushSession();
        $this->pedir('ninguem@exemplo.test', '198.51.100.2');
        $this->travel(10)->seconds();
        $naoExiste = $this->pedir('ninguem@exemplo.test', '198.51.100.2');

        $this->assertSame(
            [$existe->getStatusCode(), $existe->headers->get('Location'), session('errors')?->first('email')],
            [$naoExiste->getStatusCode(), $naoExiste->headers->get('Location'), PasswordResetLinkController::mensagemDeEspera(50)],
        );
        $this->assertSame(PasswordResetLinkController::mensagemDeEspera(50), $existe->getSession()->get('errors')->first('email'));
    }

    public function test_depois_de_60_segundos_reenvia_e_libera_usar_outro_email(): void
    {
        $user = User::factory()->create(['email' => 'victor@exemplo.test']);
        $this->pedir($user->email);

        // Antes da espera, "usar outro e-mail" não troca a tela.
        $this->assertNotNull($this->tela(['outro' => 1])->querySelector('form[data-reenviar-link]'));

        $this->travel(61)->seconds();
        $doc = $this->tela();
        $this->assertSame('0', $doc->querySelector('form[data-reenviar-link]')->getAttribute('data-espera'));
        $this->assertSame('Reenviar o link', trim($doc->querySelector('[data-reenviar-rotulo]')->textContent));
        $this->assertFalse($doc->querySelector('[data-outro-email]')->hasAttribute('hidden'));

        $this->pedir($user->email)->assertSessionHasNoErrors();
        Notification::assertSentToTimes($user, RedefinicaoDeSenha::class, 2);

        $this->travel(61)->seconds();
        $this->assertNotNull($this->tela(['outro' => 1])->getElementById('email'), 'Depois da espera, volta ao formulário.');
    }

    public function test_sem_envio_de_email_nao_comeca_espera_nenhuma(): void
    {
        config(['mail.default' => 'log']);

        $this->pedir('victor@exemplo.test')->assertSessionHasErrors('email');
        $this->assertNotNull($this->tela()->getElementById('email'), 'Sem envio, a tela continua no formulário.');
    }
}
