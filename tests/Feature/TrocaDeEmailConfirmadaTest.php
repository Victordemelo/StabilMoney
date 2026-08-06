<?php

namespace Tests\Feature;

use App\Mail\ConfirmarNovoEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Troca de e-mail em duas etapas e honestidade quando o app não envia e-mail.
 *
 * O e-mail é o que recupera a conta. Exigir a senha atual (feito antes) impede que uma
 * sessão sequestrada troque o endereço, mas não prova que o endereço DIGITADO é seu —
 * uma letra errada aponta a conta para um lugar que você não controla, e a recuperação
 * vai junto. Por isso o endereço novo só vale depois de confirmado nele mesmo.
 *
 * E enquanto `MAIL_MAILER=log` (fase de testes), nada disso pode fingir que funcionou.
 */
class TrocaDeEmailConfirmadaTest extends TestCase
{
    use RefreshDatabase;

    /** Liga um transporte que "entrega" (o fake do Laravel conta como entrega). */
    private function comMailer(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    private function semMailer(): void
    {
        config(['mail.default' => 'log']);
    }

    // ------------------------------------------------ com mailer (produção)

    public function test_com_mailer_o_email_novo_fica_pendente_ate_ser_confirmado(): void
    {
        $this->comMailer();

        $user = User::factory()->create(['email' => 'antigo@example.com', 'password' => 'senha-real']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'novo@example.com',
            'current_password' => 'senha-real',
        ])->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('antigo@example.com', $user->email, 'O e-mail mudou antes da confirmação.');
        $this->assertSame('novo@example.com', $user->pending_email);
        $this->assertNotNull($user->pending_email_sent_at);
    }

    public function test_o_link_de_confirmacao_vai_para_o_endereco_novo(): void
    {
        $this->comMailer();

        $user = User::factory()->create(['email' => 'antigo@example.com', 'password' => 'senha-real']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'novo@example.com',
            'current_password' => 'senha-real',
        ]);

        // O destinatário TEM de ser o endereço novo — mandar para o antigo não provaria
        // nada sobre quem controla a caixa nova.
        Mail::assertSent(
            ConfirmarNovoEmail::class,
            fn (ConfirmarNovoEmail $mail) => $mail->hasTo('novo@example.com')
                && ! $mail->hasTo('antigo@example.com'),
        );
    }

    public function test_clicar_no_link_efetiva_a_troca(): void
    {
        $this->comMailer();

        $user = User::factory()->create(['email' => 'antigo@example.com', 'password' => 'senha-real']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'novo@example.com',
            'current_password' => 'senha-real',
        ]);

        $user->refresh();

        $url = URL::temporarySignedRoute('profile.email.confirm', now()->addHour(), [
            'user' => $user->id,
            'hash' => sha1($user->pending_email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('novo@example.com', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNotNull($user->email_verified_at, 'Confirmar no endereço novo é verificá-lo.');
    }

    public function test_link_sem_assinatura_valida_e_recusado(): void
    {
        $this->comMailer();

        $user = User::factory()->create(['email' => 'antigo@example.com', 'password' => 'senha-real']);
        $user->forceFill(['pending_email' => 'novo@example.com'])->save();

        // URL montada na mão, sem assinatura.
        $this->actingAs($user)
            ->get("/meu-perfil/confirmar-email/{$user->id}?hash=".sha1('novo@example.com'))
            ->assertForbidden();

        $this->assertSame('antigo@example.com', $user->fresh()->email);
    }

    /** Pedir outra troca invalida o link anterior (o hash muda). */
    public function test_link_antigo_nao_vale_depois_de_novo_pedido(): void
    {
        $this->comMailer();

        $user = User::factory()->create(['email' => 'antigo@example.com', 'password' => 'senha-real']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name, 'email' => 'primeiro@example.com', 'current_password' => 'senha-real',
        ]);
        $linkAntigo = URL::temporarySignedRoute('profile.email.confirm', now()->addHour(), [
            'user' => $user->id,
            'hash' => sha1('primeiro@example.com'),
        ]);

        // Muda de ideia.
        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name, 'email' => 'segundo@example.com', 'current_password' => 'senha-real',
        ]);

        $this->actingAs($user)->get($linkAntigo);

        $user->refresh();
        $this->assertSame('antigo@example.com', $user->email, 'O link velho trocou para o endereço errado.');
        $this->assertSame('segundo@example.com', $user->pending_email);
    }

    /** E-mail que virou de outra pessoa no meio do caminho não é aplicado. */
    public function test_endereco_ocupado_no_intervalo_nao_e_aplicado(): void
    {
        $this->comMailer();

        $user = User::factory()->create(['email' => 'antigo@example.com', 'password' => 'senha-real']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name, 'email' => 'disputado@example.com', 'current_password' => 'senha-real',
        ]);

        // Outra pessoa se cadastra com o endereço antes do clique.
        User::factory()->create(['email' => 'disputado@example.com']);

        $url = URL::temporarySignedRoute('profile.email.confirm', now()->addHour(), [
            'user' => $user->id, 'hash' => sha1('disputado@example.com'),
        ]);

        $this->actingAs($user)->get($url);

        $user->refresh();
        $this->assertSame('antigo@example.com', $user->email);
        $this->assertNull($user->pending_email, 'A pendência deveria ter sido descartada.');
    }

    // -------------------------------------------- sem mailer (fase de testes)

    /**
     * Sem transporte, a troca continua imediata: exigir confirmação por um e-mail que
     * nunca chega trancaria o usuário no endereço antigo para sempre.
     */
    public function test_sem_mailer_a_troca_continua_imediata(): void
    {
        $this->semMailer();

        $user = User::factory()->create(['email' => 'antigo@example.com', 'password' => 'senha-real']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'novo@example.com',
            'current_password' => 'senha-real',
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('novo@example.com', $user->email);
        $this->assertNull($user->pending_email);
    }

    /**
     * "Esqueci a senha" não pode dizer que enviou quando nada saiu — a pessoa ficaria
     * esperando um e-mail que não vem, em vez de procurar o suporte.
     */
    public function test_sem_mailer_o_esqueci_a_senha_avisa_em_vez_de_mentir(): void
    {
        $this->semMailer();

        User::factory()->create(['email' => 'existe@example.com']);

        $resposta = $this->post(route('password.email'), ['email' => 'existe@example.com']);

        $resposta->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            config('legal.contact_email'),
            (string) session('errors')->first('email'),
            'O aviso precisa dizer para onde a pessoa deve ir.',
        );
    }

    /** Com mailer, o fluxo antigo continua igual — inclusive sem revelar se o e-mail existe. */
    public function test_com_mailer_o_esqueci_a_senha_volta_a_funcionar(): void
    {
        $this->comMailer();

        User::factory()->create(['email' => 'existe@example.com']);

        $this->post(route('password.email'), ['email' => 'existe@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');
    }
}
