<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerificacaoDeEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SimulaSmtpQueRecusa;
use Tests\TestCase;

/**
 * O botão "Reenviar e-mail de verificação" tem três saídas — e a diferença entre as duas de
 * falha é uma decisão de segurança (ver EmailVerificationNotificationController::store).
 *
 * O defeito de origem: o controller chamava o envio sem proteção. Com o SMTP recusando o
 * destinatário, o botão respondia **HTTP 500**; e sem mailer nenhum respondia "link
 * enviado", mentira dita justamente na tela cuja única saída é esse botão.
 *
 * A regra que este teste mais protege é a menos óbvia: **a falha de envio NÃO libera a
 * conta**, ao contrário do que acontece no cadastro. O botão é repetível — liberar a cada
 * falha daria a quem se cadastrou com o e-mail de outra pessoa um jeito de pular a
 * confirmação, bastando insistir até esgotar a cota de envio do provedor.
 */
class ReenvioDoLinkDeVerificacaoTest extends TestCase
{
    use RefreshDatabase;
    use SimulaSmtpQueRecusa;

    private function pendente(): User
    {
        return User::factory()->unverified()->create();
    }

    private function reenviar(User $user): TestResponse
    {
        return $this->actingAs($user)
            ->from(route('verification.notice'))
            ->post(route('verification.send'));
    }

    // ------------------------------------------------------- SMTP recusando

    public function test_smtp_recusando_nao_da_mais_erro_500(): void
    {
        $this->smtpQueRecusa();

        $this->reenviar($this->pendente())
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'verification-link-failed');
    }

    public function test_a_falha_de_envio_nao_libera_a_conta(): void
    {
        $this->smtpQueRecusa();
        $user = $this->pendente();

        // Várias vezes, de propósito: é a insistência que o atacante usaria.
        for ($i = 0; $i < 3; $i++) {
            $this->reenviar($user);
        }

        $this->assertNull(
            $user->fresh()->email_verified_at,
            'Falha que o usuário consegue provocar não pode liberar a conta.'
        );
        $this->actingAs($user->fresh())->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_a_tela_diz_a_verdade_quando_o_envio_falha(): void
    {
        $this->smtpQueRecusa();

        $this->followingRedirects()
            ->actingAs($this->pendente())
            ->from(route('verification.notice'))
            ->post(route('verification.send'))
            ->assertOk()
            ->assertSee('Não conseguimos enviar o e-mail agora')
            ->assertDontSee('Um novo link de verificação foi enviado');
    }

    public function test_a_falha_vai_para_o_log(): void
    {
        $this->smtpQueRecusa();
        Log::spy();

        $this->reenviar($this->pendente());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensagem, array $contexto = []) => $mensagem === 'E-mail não foi entregue.'
                && str_contains($contexto['oque'] ?? '', 'reenvio')
                && str_contains($contexto['erro'] ?? '', '550'))
            ->once();
    }

    // ------------------------------------------------------- SMTP no ar

    public function test_com_smtp_no_ar_o_link_sai_uma_vez_e_a_tela_confirma(): void
    {
        config()->set('mail.default', 'smtp');
        Notification::fake();
        $user = $this->pendente();

        $this->reenviar($user)
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentToTimes($user, VerificacaoDeEmail::class, 1);
        $this->assertNull($user->fresh()->email_verified_at, 'Enviar o link não confirma nada sozinho.');
    }

    // ------------------------------------------------------- sem mailer

    public function test_sem_mailer_a_conta_e_liberada_em_vez_de_mentir_link_enviado(): void
    {
        config()->set('mail.default', 'log');
        Notification::fake();
        $user = $this->pendente();

        // O invariante do projeto: sem como enviar e-mail, ninguém fica pendente. O botão é
        // a única saída da tela — responder "enviado" aqui prendia a pessoa para sempre.
        $this->reenviar($user)->assertRedirect(route('dashboard', absolute: false));

        $this->assertNotNull($user->fresh()->email_verified_at);
        Notification::assertNothingSent();
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
    }

    // ------------------------------------------------------- o que não mudou

    public function test_quem_ja_confirmou_vai_direto_para_o_app(): void
    {
        config()->set('mail.default', 'smtp');
        Notification::fake();
        $user = User::factory()->create();

        $this->reenviar($user)->assertRedirect(route('dashboard', absolute: false));

        Notification::assertNothingSent();
    }

    public function test_o_botao_continua_com_limite_de_tentativas(): void
    {
        config()->set('mail.default', 'smtp');
        Notification::fake();
        $user = $this->pendente();

        for ($i = 0; $i < 6; $i++) {
            $this->reenviar($user)->assertRedirect();
        }

        // throttle:6,1 da rota — sem ele o botão vira canhão de e-mail na cota do provedor.
        $this->reenviar($user)->assertStatus(429);
    }
}
