<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Mail\BemVindoDependente;
use App\Models\User;
use App\Support\ContextoDeSeguranca;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Avisos por e-mail das ações sensíveis da conta.
 *
 * **O buraco que eles fecham:** o app já derruba as outras sessões quando a senha muda e
 * já exige a senha atual para desligar o 2FA — tudo isso protege contra quem NÃO tem a
 * credencial. O caso oposto ficava descoberto: quem JÁ entrou troca a senha, desliga o 2FA
 * e o dono da conta não fica sabendo de nada, descobrindo semanas depois, quando já não
 * consegue entrar. O e-mail é o único canal que o invasor não controla.
 *
 * O teste mais importante daqui **não é** nenhum dos "disparou o alerta": é o
 * `falha_no_envio_nao_derruba_a_acao`. Trocar a senha é o que a pessoa faz JUSTAMENTE ao
 * desconfiar de invasão — se o servidor de e-mail estiver fora do ar e a exceção subir,
 * ela vê um erro 500 e conclui que a troca falhou, quando ela já foi gravada.
 */
class AlertasDeSegurancaTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'password';

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

    /** Um alerta chegou ao dono da conta, do tipo esperado? */
    private function assertAlertouPara(User $user, string $trechoDoAssunto): void
    {
        Mail::assertSent(AlertaDeSeguranca::class, function (AlertaDeSeguranca $mail) use ($user, $trechoDoAssunto) {
            return $mail->hasTo($user->email)
                && str_contains($mail->assunto, $trechoDoAssunto);
        });
    }

    // ══════════════════════════════════════════════════════════════════ senha

    public function test_trocar_a_senha_nas_configuracoes_avisa_o_dono(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => self::SENHA,
            'password' => 'outra-senha-bem-comprida',
            'password_confirmation' => 'outra-senha-bem-comprida',
        ])->assertSessionHasNoErrors();

        $this->assertAlertouPara($user, 'senha do Stabil Money foi alterada');
    }

    public function test_redefinir_a_senha_pelo_link_avisa_o_dono(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = app('auth.password.broker')->createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'outra-senha-bem-comprida',
            'password_confirmation' => 'outra-senha-bem-comprida',
        ])->assertSessionHasNoErrors();

        $this->assertAlertouPara($user, 'senha do Stabil Money foi redefinida');
    }

    // ══════════════════════════════════════════════════════════════════ 2FA

    public function test_ativar_o_2fa_avisa_o_dono(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.2fa.ativar'), ['password' => self::SENHA]);
        $user->refresh();

        $this->actingAs($user)->post(route('settings.2fa.confirmar'), [
            'codigo' => Totp::codigo($user->two_factor_secret, Totp::passoAtual()),
        ]);

        $this->assertTrue($user->fresh()->temDoisFatores());
        $this->assertAlertouPara($user, 'duas etapas ativada');
    }

    public function test_desativar_o_2fa_avisa_o_dono(): void
    {
        Mail::fake();
        $user = $this->comDoisFatores();

        $this->actingAs($user)->delete(route('settings.2fa.desativar'), ['password' => self::SENHA]);

        $this->assertFalse($user->fresh()->temDoisFatores());
        $this->assertAlertouPara($user, 'duas etapas DESATIVADA');
    }

    /** Alarme falso é o que faz a pessoa parar de ler os alertas seguintes. */
    public function test_cancelar_uma_configuracao_pendente_nao_avisa_nada(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('settings.2fa.ativar'), ['password' => self::SENHA]);
        $this->actingAs($user->fresh())->delete(route('settings.2fa.desativar'));

        Mail::assertNotSent(AlertaDeSeguranca::class);
    }

    // ══════════════════════════════════════════════════════════════════ sessões e conta

    public function test_encerrar_outras_sessoes_avisa_o_dono(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('settings.sessions.destroy'), ['password' => self::SENHA])
            ->assertSessionHasNoErrors();

        $this->assertAlertouPara($user, 'aparelhos foram desconectados');
    }

    public function test_excluir_a_conta_avisa_antes_de_apagar(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $email = $user->email;

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => self::SENHA]);

        // A conta sumiu...
        $this->assertNull(User::find($user->id));

        // ...mas o aviso saiu ANTES, para o endereço que existia. Enviar depois do
        // delete não teria nome nem endereço para quem escrever.
        Mail::assertSent(AlertaDeSeguranca::class, fn (AlertaDeSeguranca $mail) => $mail->hasTo($email));
    }

    // ══════════════════════════════════════════════════════════════════ dependente

    public function test_dependente_novo_recebe_boas_vindas_e_a_senha_nao_vai_junto(): void
    {
        Mail::fake();
        $titular = User::factory()->create(['is_admin' => true]);

        $this->actingAs($titular)->post(route('dependentes.store'), [
            'name' => 'Maria',
            'email' => 'maria@familia.test',
            'password' => 'senha-do-dependente-123',
            'relationship' => 'filho',
        ])->assertSessionHasNoErrors();

        Mail::assertSent(BemVindoDependente::class, function (BemVindoDependente $mail) use ($titular) {
            $corpo = $mail->render();

            return $mail->hasTo('maria@familia.test')
                // O aviso é para o DEPENDENTE, não para quem o criou.
                && ! $mail->hasTo($titular->email)
                // 🚨 A senha jamais pode viajar por e-mail: caixa de entrada, backup e
                // servidor intermediário guardam isso para sempre.
                && ! str_contains($corpo, 'senha-do-dependente-123')
                && str_contains($corpo, 'Esqueci a senha');
        });
    }

    // ══════════════════════════════════════════════════════════════════ robustez

    /**
     * 🚨 O teste que justifica o App\Support\Notificador existir.
     *
     * Com o servidor de e-mail fora do ar, a ação tem de acontecer do mesmo jeito. Se a
     * exceção subir, o usuário vê erro 500 e acredita que a troca de senha não valeu —
     * quando ela já está gravada. E aí ele tenta de novo, ou pior: desiste achando que
     * ainda está com a senha antiga.
     */
    public function test_falha_no_envio_nao_derruba_a_acao(): void
    {
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP fora do ar'));

        $user = User::factory()->create();

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => self::SENHA,
            'password' => 'outra-senha-bem-comprida',
            'password_confirmation' => 'outra-senha-bem-comprida',
        ])->assertSessionHasNoErrors();

        // A senha mudou mesmo, apesar de o aviso não ter saído.
        $this->assertTrue(
            Hash::check('outra-senha-bem-comprida', $user->fresh()->password),
            'A troca de senha foi desfeita por causa de uma falha de e-mail.'
        );
    }

    /** Sem transporte que entregue, não adianta encher storage/logs de HTML. */
    public function test_sem_mailer_nenhum_alerta_e_disparado(): void
    {
        Mail::fake();
        config()->set('mail.default', 'log');

        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('settings.sessions.destroy'), ['password' => self::SENHA]);

        Mail::assertNothingSent();
    }

    // ══════════════════════════════════════════════════════════════════ conteúdo

    public function test_o_alerta_diz_quando_de_onde_e_o_que_fazer(): void
    {
        Mail::fake();
        $user = User::factory()->create(['name' => 'Victor']);

        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120'])
            ->delete(route('settings.sessions.destroy'), ['password' => self::SENHA]);

        Mail::assertSent(AlertaDeSeguranca::class, function (AlertaDeSeguranca $mail) {
            $html = $mail->render();

            return str_contains($html, 'Victor')                 // saudação nominal
                && str_contains($html, '203.0.113.9')            // de onde
                && str_contains($html, 'Chrome no Windows')      // com o quê
                && str_contains($html, 'Não foi você?')          // o que fazer
                && str_contains($html, 'Nunca pedimos sua senha por e-mail.');
        });
    }

    /**
     * A mensagem sai em DUAS partes (HTML + texto puro).
     *
     * Não é capricho: filtro de spam desconfia de mensagem só-HTML, leitor de tela e
     * cliente em modo texto mostram a parte texto, e um HTML que não renderize deixa o
     * aviso ilegível sem ela. Aqui o e-mail é construído de verdade (transporte `array`)
     * em vez de montado à mão no teste — montar à mão passaria mesmo se o Mailable
     * parasse de declarar a versão texto.
     */
    public function test_o_email_vai_em_html_e_em_texto_puro(): void
    {
        $user = User::factory()->create(['name' => 'Victor']);

        Mail::to($user->email)->send(AlertaDeSeguranca::senhaAlterada($user, new ContextoDeSeguranca(
            quando: '6 de agosto de 2026, às 00:42',
            ip: '203.0.113.9',
            dispositivo: 'Chrome no Windows',
        )));

        $mensagem = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();

        $html = $mensagem->getHtmlBody();
        $texto = $mensagem->getTextBody();

        $this->assertNotEmpty($texto, 'O e-mail saiu só em HTML — sem a parte texto.');

        foreach ([$html, $texto] as $parte) {
            $this->assertStringContainsString('Senha alterada', $parte);
            $this->assertStringContainsString('203.0.113.9', $parte);
            $this->assertStringContainsString('Não foi você?', $parte);
        }

        // A parte texto é texto: sem marcação vazando.
        $this->assertStringNotContainsString('<strong>', $texto);
        $this->assertStringNotContainsString('<p ', $texto);
        $this->assertStringNotContainsString('<table', $texto);
    }
}
