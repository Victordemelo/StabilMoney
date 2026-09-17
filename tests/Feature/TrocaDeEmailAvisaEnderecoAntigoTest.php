<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Mail\ConfirmarNovoEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A-7 da auditoria de 05/09/2026: a troca de e-mail nunca avisava o endereço ANTIGO.
 *
 * O e-mail é o que recupera a conta. Quem tem a sessão e a senha trocava esse canal em
 * silêncio: o link de confirmação vai para o endereço novo — que quem pediu a troca controla
 * —, e o dono só descobria quando o "Esqueci a senha" já não chegava a ele.
 *
 * Decisão: dois avisos, os dois para o endereço antigo, cada um fiel ao estado real.
 *  - no PEDIDO: "pediram a troca, ainda não vale" — é o único momento em que o dono ainda
 *    consegue impedir (o "Esqueci a senha" ainda chega a ele e derruba as sessões);
 *  - quando a troca VALE: "foi trocado" — já com `users.email` apontando para o novo.
 *
 * E nenhum alarme falso: salvar o perfil sem mexer no e-mail, errar a senha, abrir um link
 * velho ou um endereço que outra conta tomou não avisam nada.
 */
class TrocaDeEmailAvisaEnderecoAntigoTest extends TestCase
{
    use RefreshDatabase;

    private const ANTIGO = 'dono@antigo.test';

    private const NOVO = 'escolhido@novo.test';

    protected function setUp(): void
    {
        parent::setUp();

        // Transporte que "entrega" (o fake do Laravel conta como entrega) — é o caminho de
        // produção, com a troca em duas etapas.
        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    private function dono(): User
    {
        return User::factory()->create(['name' => 'Dono', 'email' => self::ANTIGO, 'password' => 'senha-real']);
    }

    private function pedirTroca(User $user, string $para = self::NOVO): TestResponse
    {
        return $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $para,
            'current_password' => 'senha-real',
        ]);
    }

    private function linkDeConfirmacao(User $user): string
    {
        return URL::temporarySignedRoute('profile.email.confirm', now()->addHour(), [
            'user' => $user->id,
            'hash' => sha1((string) $user->fresh()->pending_email),
        ]);
    }

    /** Alertas de segurança enviados, com quem os recebeu. */
    private function alertasPara(string $endereco, string $trechoDoAssunto): int
    {
        return Mail::sent(AlertaDeSeguranca::class, fn (AlertaDeSeguranca $mail) => $mail->hasTo($endereco)
            && str_contains($mail->assunto, $trechoDoAssunto))->count();
    }

    // ══════════════════════════════════════════════════ no pedido

    public function test_pedir_a_troca_avisa_o_endereco_antigo(): void
    {
        $user = $this->dono();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120']);
        $this->pedirTroca($user)->assertSessionHasNoErrors();

        // O link continua indo para o endereço novo...
        Mail::assertSent(ConfirmarNovoEmail::class, fn (ConfirmarNovoEmail $mail) => $mail->hasTo(self::NOVO));

        // ...e o antigo fica sabendo, com o que precisa para decidir se foi ele.
        Mail::assertSent(AlertaDeSeguranca::class, 1);
        Mail::assertSent(AlertaDeSeguranca::class, function (AlertaDeSeguranca $mail) {
            $html = $mail->render();

            return $mail->hasTo(self::ANTIGO)
                && ! $mail->hasTo(self::NOVO)
                && str_contains($mail->assunto, 'Pedido para trocar o e-mail')
                // Diz "pedido", não "trocado": a troca ainda não vale.
                && str_contains($html, 'Nada mudou ainda')
                && str_contains($html, '2 horas')
                // Para qual endereço — mascarado (ver AlertaDeSeguranca::mascararEmail).
                && str_contains($html, 'es***@novo.test')
                && ! str_contains($html, self::NOVO)
                // Quando e de onde.
                && str_contains($html, '203.0.113.9')
                && str_contains($html, 'Chrome no Windows')
                // O que fazer se não foi ele: a saída que ainda funciona neste momento.
                && str_contains($html, 'Não foi você?')
                && str_contains($html, 'Esqueci a senha');
        });
    }

    /** Quem digitou o endereço o vê por inteiro na própria tela — é onde se nota um erro. */
    public function test_a_tela_mostra_para_onde_foi_o_link_em_portugues(): void
    {
        $user = $this->dono();

        $this->pedirTroca($user)
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Enviamos um link de confirmação para '.self::NOVO)
                && str_contains($status, 'você continua entrando com '.self::ANTIGO));

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertSee('Enviamos um link de confirmação para')
            ->assertDontSee('pending-email-sent');
    }

    // ══════════════════════════════════════════════════ quando a troca vale

    public function test_confirmar_a_troca_avisa_o_endereco_antigo_mesmo_com_o_email_ja_trocado(): void
    {
        $user = $this->dono();
        $this->pedirTroca($user)->assertSessionHasNoErrors();
        $link = $this->linkDeConfirmacao($user);

        $this->actingAs($user)->get($link)->assertRedirect(route('profile.edit'));

        // A troca valeu: no banco o e-mail já é o novo...
        $this->assertSame(self::NOVO, $user->fresh()->email);

        // ...e mesmo assim o aviso de "trocado" vai para o ANTIGO, e só para ele.
        $this->assertSame(1, $this->alertasPara(self::ANTIGO, 'foi trocado'));
        $this->assertSame(0, $this->alertasPara(self::NOVO, 'foi trocado'));

        Mail::assertSent(AlertaDeSeguranca::class, function (AlertaDeSeguranca $mail) {
            if (! str_contains($mail->assunto, 'foi trocado')) {
                return false;
            }

            $html = $mail->render();

            return str_contains($html, self::ANTIGO)
                && str_contains($html, 'es***@novo.test')
                && ! str_contains($html, self::NOVO)
                // "Esqueci a senha" já não acha a conta por este endereço: a saída é o contato.
                && str_contains($html, 'não funciona mais com este endereço')
                && str_contains($html, (string) config('legal.contact_email'));
        });
    }

    public function test_a_tela_confirma_a_troca_em_portugues(): void
    {
        $user = $this->dono();
        $this->pedirTroca($user);

        $this->actingAs($user)->get($this->linkDeConfirmacao($user))
            ->assertSessionHas('status', 'E-mail confirmado: a partir de agora você entra com '.self::NOVO.'.');

        $this->actingAs($user->fresh())->get(route('profile.edit'))
            ->assertSee('E-mail confirmado')
            ->assertDontSee('email-updated');
    }

    // ══════════════════════════════════════════════════ sem alarme falso

    public function test_trocar_so_o_nome_nao_avisa_ninguem(): void
    {
        $user = $this->dono();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Nome Novo',
            'email' => self::ANTIGO,
            'phone' => '(11) 90000-0000',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Nome Novo', $user->fresh()->name);
        Mail::assertNothingSent();
    }

    public function test_senha_atual_errada_nao_avisa_nada(): void
    {
        $user = $this->dono();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => self::NOVO,
            'current_password' => 'senha-errada',
        ])->assertSessionHasErrors('current_password');

        Mail::assertNothingSent();
    }

    /** Clicar de novo no link já usado não anuncia uma segunda troca. */
    public function test_link_ja_usado_nao_avisa_de_novo(): void
    {
        $user = $this->dono();
        $this->pedirTroca($user);
        $link = $this->linkDeConfirmacao($user);

        $this->actingAs($user)->get($link);
        $this->actingAs($user->fresh())->get($link);

        $this->assertSame(1, $this->alertasPara(self::ANTIGO, 'foi trocado'));
    }

    /** Endereço tomado por outra conta antes do clique: nada mudou, nada é anunciado. */
    public function test_endereco_tomado_no_intervalo_nao_avisa_troca(): void
    {
        $user = $this->dono();
        $this->pedirTroca($user);
        $link = $this->linkDeConfirmacao($user);

        User::factory()->create(['email' => self::NOVO]);

        $this->actingAs($user)->get($link);

        $this->assertSame(self::ANTIGO, $user->fresh()->email);
        $this->assertSame(0, $this->alertasPara(self::ANTIGO, 'foi trocado'));
    }

    /**
     * Sem transporte a troca vale na hora e nada sai — como todo alerta (o Notificador não
     * envia sem entrega). O que importa aqui é a troca não quebrar.
     */
    public function test_sem_mailer_a_troca_imediata_nao_envia_nada_nem_quebra(): void
    {
        config(['mail.default' => 'log']);
        $user = $this->dono();

        $this->pedirTroca($user)->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $this->assertSame(self::NOVO, $user->fresh()->email);
        Mail::assertNothingSent();
    }

    // ══════════════════════════════════════════════════ robustez

    /** Aviso é acessório: SMTP fora do ar não pode desfazer nem travar a confirmação. */
    public function test_falha_no_envio_do_aviso_nao_derruba_a_confirmacao(): void
    {
        $user = $this->dono();
        $user->forceFill(['pending_email' => self::NOVO, 'pending_email_sent_at' => now()])->save();
        $link = $this->linkDeConfirmacao($user);

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP fora do ar'));

        $this->actingAs($user)->get($link)->assertRedirect(route('profile.edit'));

        $this->assertSame(self::NOVO, $user->fresh()->email);
    }
}
