<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Os e-mails que toda pessoa recebe no primeiro dia saem no layout do app, em PT-BR, com HTML
 * e texto — e o link funciona nas DUAS partes (achados E-2 e E-3 da auditoria de 07/09/2026).
 *
 * O defeito: a confirmação do cadastro e o "esqueci a senha" eram as notificações padrão do
 * framework (`VerifyEmail` e `ResetPassword`) — sem a marca, com "Todos os direitos
 * reservados" no rodapé e nota 48% de compatibilidade (o Outlook e o Gmail desmontam o
 * layout). E a confirmação da troca de e-mail era só texto, impressa com `{{ }}`: o `&` da URL
 * assinada virava `&amp;`, a assinatura não conferia, e quem clicava recebia "este link
 * expirou" — a troca de e-mail com SMTP no ar não se completava pelo link.
 *
 * Cada teste manda o e-mail pelo caminho real (cadastro, "esqueci a senha", troca de e-mail no
 * perfil), pega a mensagem no transporte `array` e SEGUE o link — primeiro o da parte texto,
 * que é onde o `&amp;` quebrava.
 *
 * O que não pode mudar, e continua coberto onde já estava: a falha de SMTP no cadastro e no
 * reenvio (CadastroNaoQuebraComSmtpForaTest, ReenvioDoLinkDeVerificacaoTest), que dependem de a
 * exceção do envio subir até o `Notificador::tentarEnviar`.
 */
class EmailsDoPrimeiroDiaNoLayoutDoAppTest extends TestCase
{
    use RefreshDatabase;

    /** A última mensagem que saiu para este endereço. */
    private function mensagemPara(string $endereco): Email
    {
        $mensagens = Mail::mailer()->getSymfonyTransport()->messages()
            ->map(fn ($enviada) => $enviada->getOriginalMessage())
            ->filter(fn (Email $email) => collect($email->getTo())->contains(fn ($to) => $to->getAddress() === $endereco));

        $this->assertNotEmpty($mensagens, "Nenhum e-mail saiu para {$endereco}.");

        return $mensagens->last();
    }

    private function totalDeMensagens(): int
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->count();
    }

    /** O link como a parte TEXTO o entrega — é ele que o leitor de texto puro clica. */
    private function linkDoTexto(Email $email, string $trecho): string
    {
        $this->assertSame(1, preg_match('~https?://\S*'.preg_quote($trecho, '~').'\S*~', (string) $email->getTextBody(), $achado), "Link com {$trecho} não está na parte texto.");

        return $achado[0];
    }

    /** O link do botão, como o navegador o lê do atributo (o `&amp;` do HTML vira `&`). */
    private function linkDoBotao(Email $email, string $trecho): string
    {
        $this->assertSame(1, preg_match('~href="([^"]*'.preg_quote($trecho, '~').'[^"]*)"~', (string) $email->getHtmlBody(), $achado), "Botão com {$trecho} não está na parte HTML.");

        return html_entity_decode($achado[1], ENT_QUOTES | ENT_HTML5);
    }

    /** O layout do app, e não o modelo padrão do framework. */
    private function assertNoLayoutDoApp(Email $email): void
    {
        $html = (string) $email->getHtmlBody();

        $this->assertNotSame('', trim($html), 'O e-mail saiu sem a parte HTML.');
        $this->assertNotSame('', trim((string) $email->getTextBody()), 'O e-mail saiu sem a parte texto.');

        $this->assertStringContainsString('Stabil<span style="color:#6FCDA8;">Money</span>', $html);
        $this->assertStringContainsString('Nunca pedimos sua senha por e-mail.', $html);

        foreach (['Todos os direitos reservados', 'All rights reserved', 'Verify Email Address', 'Reset Password', 'Regards'] as $doFramework) {
            $this->assertStringNotContainsString($doFramework, $html, "Sobrou o modelo do framework: \"{$doFramework}\".");
        }
    }

    // ══════════════════════════════════════════════════ confirmação do cadastro

    public function test_o_cadastro_manda_a_confirmacao_no_layout_do_app_e_o_link_do_texto_confirma(): void
    {
        $this->post('/register', [
            'name' => 'Pessoa Nova',
            'email' => 'nova@exemplo.test',
            'password' => 'senha-bem-comprida-123',
            'terms' => '1',
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'nova@exemplo.test')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail(), 'Com o link enviado, a confirmação é exigida.');

        $email = $this->mensagemPara('nova@exemplo.test');

        $this->assertNoLayoutDoApp($email);
        $this->assertSame('Confirme seu e-mail — Stabil Money', $email->getSubject());
        $this->assertStringContainsString('Olá, Pessoa Nova.', (string) $email->getTextBody());
        $this->assertStringContainsString('Confirmar meu e-mail', (string) $email->getHtmlBody());

        $link = $this->linkDoTexto($email, '/verify-email/');
        $this->assertSame($link, $this->linkDoBotao($email, '/verify-email/'), 'O botão e o texto levam a lugares diferentes.');

        $this->actingAs($user)->get($link)->assertRedirect();

        $this->assertTrue($user->fresh()->hasVerifiedEmail(), 'O link da parte texto não confirmou o e-mail.');
    }

    public function test_o_reenvio_da_confirmacao_tambem_sai_no_layout_do_app(): void
    {
        $user = User::factory()->unverified()->create(['name' => 'Pendente', 'email' => 'pendente@exemplo.test']);

        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))
            ->assertSessionHas('status', 'verification-link-sent');

        $this->assertNoLayoutDoApp($this->mensagemPara('pendente@exemplo.test'));
    }

    // ══════════════════════════════════════════════════ esqueci a senha

    public function test_esqueci_a_senha_manda_o_link_no_layout_do_app_e_o_link_do_texto_redefine(): void
    {
        $user = User::factory()->create(['name' => 'Quem Esqueceu', 'email' => 'esqueceu@exemplo.test']);

        $this->post('/forgot-password', ['email' => 'esqueceu@exemplo.test'])->assertSessionHasNoErrors();

        $email = $this->mensagemPara('esqueceu@exemplo.test');

        $this->assertNoLayoutDoApp($email);
        $this->assertSame('Redefina sua senha do Stabil Money', $email->getSubject());
        $this->assertStringContainsString('Olá, Quem Esqueceu.', (string) $email->getTextBody());
        $this->assertStringContainsString('60 minutos', (string) $email->getTextBody());

        $link = $this->linkDoTexto($email, '/reset-password/');
        $this->assertSame($link, $this->linkDoBotao($email, '/reset-password/'));

        $this->get($link)->assertOk();

        // O token e o e-mail vêm do próprio link, como vêm para quem clica.
        $partes = parse_url($link);
        parse_str($partes['query'] ?? '', $query);

        $this->post('/reset-password', [
            'token' => basename($partes['path']),
            'email' => $query['email'],
            'password' => 'senha-nova-bem-comprida',
            'password_confirmation' => 'senha-nova-bem-comprida',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('senha-nova-bem-comprida', $user->fresh()->password));
    }

    /** Continua sem revelar quem tem conta: a mesma resposta, e nenhum e-mail sai. */
    public function test_esqueci_a_senha_com_email_que_nao_existe_responde_igual_e_nada_sai(): void
    {
        User::factory()->create(['email' => 'existe@exemplo.test']);

        $existe = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'existe@exemplo.test']);
        $enviadas = $this->totalDeMensagens();

        $naoExiste = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'ninguem@exemplo.test']);

        $naoExiste->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
        $this->assertSame($existe->getSession()->get('status'), $naoExiste->getSession()->get('status'));
        $this->assertSame($enviadas, $this->totalDeMensagens(), 'Saiu e-mail para um endereço sem conta.');
    }

    // ══════════════════════════════════════════════════ confirmação da troca de e-mail

    /**
     * O defeito mais grave dos três: com o link impresso por `{{ }}` na parte texto (a única
     * que existia), o `&` virava `&amp;` e a assinatura não conferia. Aqui o link é seguido
     * exatamente como sai na parte texto.
     */
    public function test_a_troca_de_email_manda_o_link_no_layout_do_app_e_o_link_do_texto_confirma(): void
    {
        $user = User::factory()->create([
            'name' => 'Quem Troca',
            'email' => 'antigo@exemplo.test',
            'password' => Hash::make('senha-atual-123'),
        ]);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Quem Troca',
            'email' => 'novo@exemplo.test',
            'current_password' => 'senha-atual-123',
        ])->assertSessionHasNoErrors();

        $email = $this->mensagemPara('novo@exemplo.test');

        $this->assertNoLayoutDoApp($email);
        $this->assertSame('Confirme seu novo e-mail — Stabil Money', $email->getSubject());
        $this->assertStringContainsString('novo@exemplo.test', (string) $email->getTextBody());

        $link = $this->linkDoTexto($email, '/meu-perfil/confirmar-email/');
        $this->assertStringNotContainsString('&amp;', $link, 'O link da parte texto saiu com &amp; — a assinatura não confere.');
        $this->assertSame($link, $this->linkDoBotao($email, '/meu-perfil/confirmar-email/'));

        $this->actingAs($user)->get($link)->assertRedirect(route('profile.edit'));

        $this->assertSame('novo@exemplo.test', $user->fresh()->email, 'O link da parte texto não concluiu a troca de e-mail.');
    }
}
