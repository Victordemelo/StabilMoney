<?php

namespace Tests\Feature;

use App\Console\Commands\LembretesDeVencimentos;
use App\Mail\LembreteDeVencimento;
use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * `php artisan lembretes:vencimentos` — o sino da topbar chegando por e-mail.
 *
 * "Hoje" é 17/09/2026. O cartão fecha dia 10 e vence dia 20: uma compra de 05/09
 * está na fatura JÁ FECHADA, que vence em exatamente 3 dias. Conta fixa com
 * `due_day` 18 vence amanhã; 15, venceu há 2 dias; 10, há 7; 12, há 5.
 *
 * As regras que estes testes fixam:
 *  - só o TITULAR recebe, com e-mail verificado e a preferência ligada;
 *  - gatilho = vence em 3 dias / amanhã / hoje, ou vencida há múltiplo de 7 dias;
 *    disparado, o e-mail leva o quadro inteiro (inclusive a vencida há 2 dias);
 *  - um e-mail por titular por dia, mesmo com o cron reexecutado;
 *  - falha no SMTP de um titular não impede o seguinte;
 *  - sem mailer, nada sai e o comando não é erro.
 */
class LembretesDeVencimentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ───────────────────────────────────────────────────────────── cenários

    private function titular(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        Account::factory()->for($user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 5000, 'overdraft_limit' => 0,
        ]);

        return $user;
    }

    /** Cartão que fecha dia 10 / vence dia 20, com uma compra na fatura já fechada (vence 20/09). */
    private function faturaFechadaVencendoEm3Dias(User $user, float $valor = 300): Account
    {
        $cartao = Account::factory()->for($user)->creditCard()->create([
            'name' => 'Nubank', 'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
        Transaction::factory()->for($user)->for($cartao)->expense()->create([
            'amount' => $valor, 'date' => '2026-09-05', 'description' => 'Mercado',
        ]);

        return $cartao;
    }

    private function contaFixa(User $user, string $nome, int $diaDoVencimento, float $valor = 800): FixedBill
    {
        return FixedBill::create([
            'user_id' => $user->id,
            'name' => $nome,
            'amount' => $valor,
            'due_day' => $diaDoVencimento,
            'account_id' => $user->accounts()->first()->id,
            // Começa NESTE mês: com `starts_on` antigo, as competências de maio a
            // agosto nasceriam todas vencidas e o cenário viraria outro.
            'starts_on' => '2026-09-01',
            'active' => true,
        ]);
    }

    /** Renderiza as DUAS partes (HTML + texto) de um lembrete enviado. */
    private function partes(LembreteDeVencimento $mail): array
    {
        $conteudo = $mail->content();
        $this->assertSame('emails.layout', $conteudo->view);
        $this->assertSame('emails.layout-texto', $conteudo->text, 'Todo e-mail sai em duas partes (HTML + texto).');

        return [
            'html' => $mail->render(),
            'texto' => view($conteudo->text, $conteudo->with)->render(),
        ];
    }

    private function unicoEnviadoPara(User $user): LembreteDeVencimento
    {
        $enviados = Mail::sent(LembreteDeVencimento::class, fn ($m) => $m->hasTo($user->email));
        $this->assertCount(1, $enviados);

        return $enviados->first();
    }

    // ───────────────────────────────────────────────────────────── conteúdo

    public function test_fatura_fechada_que_vence_em_3_dias_avisa_o_titular_com_nome_e_valor(): void
    {
        $user = $this->titular();
        $this->faturaFechadaVencendoEm3Dias($user, 300);

        $this->artisan('lembretes:vencimentos')->assertSuccessful();

        $mail = $this->unicoEnviadoPara($user);
        $this->assertSame([], $mail->vencidas);
        $this->assertCount(1, $mail->proximas);
        $this->assertSame('Fatura Nubank', $mail->proximas[0]['nome']);
        $this->assertSame(3, $mail->proximas[0]['diasRestantes']);
        $this->assertSame('Stabil Money: 1 conta vence nos próximos dias', $mail->assunto());

        $partes = $this->partes($mail);
        foreach ($partes as $parte) {
            $this->assertStringContainsString('Fatura Nubank', $parte);
            $this->assertStringContainsString('R$ 300,00', $parte);
            $this->assertStringContainsString('vence em 3 dias', $parte);
            $this->assertStringContainsString('20/09/2026', $parte);
            $this->assertStringContainsString(route('faturas.index'), $parte);
        }
        // Só a HTML tem seção rotulada; a de texto não pode carregar marcação.
        $this->assertStringContainsString('Próximas', $partes['html']);
        $this->assertStringNotContainsString('<strong>', $partes['texto']);
        $this->assertStringNotContainsString('Vencidas', $partes['html']);
        // Não é alerta de segurança: o rodapé diz o que é e como desligar.
        $this->assertStringContainsString('lembrete automático de vencimentos', $partes['html']);
        $this->assertStringContainsString(route('settings', 'conta'), $partes['html']);
        $this->assertStringNotContainsString('aviso automático de segurança', $partes['html']);
    }

    public function test_conta_fixa_de_amanha_e_outra_vencida_ha_2_dias_saem_num_email_so_com_as_duas_secoes(): void
    {
        $user = $this->titular();
        $this->contaFixa($user, 'Luz', 18, 250);      // amanhã → dispara
        $this->contaFixa($user, 'Aluguel', 15, 1500); // há 2 dias → não dispara sozinha, mas entra no quadro

        $this->artisan('lembretes:vencimentos')->assertSuccessful();

        $mail = $this->unicoEnviadoPara($user);
        $this->assertSame(['Aluguel'], array_column($mail->vencidas, 'nome'));
        $this->assertSame(['Luz'], array_column($mail->proximas, 'nome'));
        $this->assertSame('Stabil Money: 1 conta vencida e 1 conta vence nos próximos dias', $mail->assunto());

        $partes = $this->partes($mail);
        foreach ($partes as $parte) {
            $this->assertStringContainsString('Luz', $parte);
            $this->assertStringContainsString('vence amanhã', $parte);
            $this->assertStringContainsString('R$ 250,00', $parte);
            $this->assertStringContainsString('Aluguel', $parte);
            $this->assertStringContainsString('venceu há 2 dias', $parte);
            $this->assertStringContainsString('R$ 1.500,00', $parte);
        }
        $this->assertStringContainsString('Vencidas', $partes['html']);
        $this->assertStringContainsString('Próximas', $partes['html']);
        $this->assertStringContainsString('VENCIDAS', $partes['texto']);
        $this->assertStringContainsString('PRÓXIMAS', $partes['texto']);
    }

    public function test_nome_do_cartao_e_escapado_no_html(): void
    {
        $user = $this->titular();
        $cartao = $this->faturaFechadaVencendoEm3Dias($user);
        $cartao->update(['name' => '<b>x</b>']);

        $this->artisan('lembretes:vencimentos');

        $html = $this->unicoEnviadoPara($user)->render();
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    // ───────────────────────────────────────────────────────────── gatilho

    public function test_sem_nada_vencendo_nao_manda_email(): void
    {
        $user = $this->titular();
        $this->contaFixa($user, 'Escola', 25); // vence em 8 dias: fora do horizonte

        $this->artisan('lembretes:vencimentos')
            ->expectsOutputToContain('Nenhum lembrete')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($user->fresh()->reminder_last_sent_on);
    }

    public function test_vencida_ha_7_dias_avisa_de_novo_mas_ha_5_nao(): void
    {
        $cinco = $this->titular();
        $this->contaFixa($cinco, 'Internet', 12); // venceu há 5 dias

        $sete = $this->titular();
        $this->contaFixa($sete, 'Condomínio', 10); // venceu há 7 dias

        $this->artisan('lembretes:vencimentos');

        Mail::assertNotSent(LembreteDeVencimento::class, fn ($m) => $m->hasTo($cinco->email));
        $mail = $this->unicoEnviadoPara($sete);
        $this->assertSame('Stabil Money: 1 conta vencida', $mail->assunto());
        $this->assertStringContainsString('venceu há 7 dias', $mail->render());
    }

    public function test_regra_do_gatilho(): void
    {
        foreach ([3, 1, 0, -7, -14, -21] as $dias) {
            $this->assertTrue(LembretesDeVencimentos::dispara($dias), "dias={$dias} deveria disparar");
        }
        foreach ([4, 2, -1, -2, -5, -6, -8, -13] as $dias) {
            $this->assertFalse(LembretesDeVencimentos::dispara($dias), "dias={$dias} não deveria disparar");
        }
    }

    // ───────────────────────────────────────────────────────────── quem recebe

    public function test_segunda_execucao_no_mesmo_dia_nao_manda_outro_email(): void
    {
        $user = $this->titular();
        $this->faturaFechadaVencendoEm3Dias($user);

        $this->artisan('lembretes:vencimentos')->assertSuccessful();
        $this->assertSame('2026-09-17', $user->fresh()->reminder_last_sent_on->toDateString());

        $this->artisan('lembretes:vencimentos')->assertSuccessful();

        Mail::assertSentCount(1);

        // No dia seguinte a trava solta (amanhã a fatura vence em 2 dias — não dispara;
        // depois de amanhã, em 1 — dispara de novo).
        Carbon::setTestNow('2026-09-19');
        $this->artisan('lembretes:vencimentos')->assertSuccessful();
        Mail::assertSentCount(2);
    }

    public function test_quem_desligou_a_preferencia_nao_recebe(): void
    {
        $user = $this->titular(['reminder_emails' => false]);
        $this->faturaFechadaVencendoEm3Dias($user);

        $this->artisan('lembretes:vencimentos')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_dependente_nunca_recebe_so_o_titular(): void
    {
        $titular = $this->titular();
        $dependente = User::factory()->create(['account_owner_id' => $titular->id, 'is_admin' => false]);
        $this->faturaFechadaVencendoEm3Dias($titular);

        $this->artisan('lembretes:vencimentos')->assertSuccessful();

        $this->unicoEnviadoPara($titular);
        Mail::assertNotSent(LembreteDeVencimento::class, fn ($m) => $m->hasTo($dependente->email));
        Mail::assertSentCount(1);

        // Nem pedindo por id: `--user` com dependente avisa e não manda nada.
        $this->artisan('lembretes:vencimentos', ['--user' => $dependente->id])
            ->expectsOutputToContain('Nenhum titular')
            ->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_email_nao_verificado_nao_recebe(): void
    {
        $user = $this->titular(['email_verified_at' => null]);
        $this->faturaFechadaVencendoEm3Dias($user);

        $this->artisan('lembretes:vencimentos')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_opcao_user_restringe_a_um_titular(): void
    {
        $a = $this->titular();
        $this->faturaFechadaVencendoEm3Dias($a);
        $b = $this->titular();
        $this->faturaFechadaVencendoEm3Dias($b);

        $this->artisan('lembretes:vencimentos', ['--user' => $b->id])->assertSuccessful();

        Mail::assertNotSent(LembreteDeVencimento::class, fn ($m) => $m->hasTo($a->email));
        $this->unicoEnviadoPara($b);
        $this->assertNull($a->fresh()->reminder_last_sent_on);
    }

    // ───────────────────────────────────────────────────────────── robustez

    public function test_falha_no_smtp_do_primeiro_titular_nao_impede_o_segundo(): void
    {
        $a = $this->titular();
        $this->faturaFechadaVencendoEm3Dias($a);
        $b = $this->titular();
        $this->faturaFechadaVencendoEm3Dias($b);

        // Mailer que explode só para o primeiro endereço.
        $entregues = [];
        Mail::shouldReceive('to')->andReturnUsing(function ($destino) use (&$entregues, $a) {
            $pendente = Mockery::mock();
            $pendente->shouldReceive('send')->andReturnUsing(function () use ($destino, &$entregues, $a) {
                if ($destino === $a->email) {
                    throw new \RuntimeException('SMTP fora do ar');
                }
                $entregues[] = $destino;
            });

            return $pendente;
        });

        $this->artisan('lembretes:vencimentos')
            ->expectsOutputToContain('1 lembrete(s) enviado(s), 1 falha(s)')
            ->assertFailed();

        $this->assertSame([$b->email], $entregues);
        $this->assertNull($a->fresh()->reminder_last_sent_on, 'Falhou: continua elegível para a próxima passada.');
        $this->assertSame('2026-09-17', $b->fresh()->reminder_last_sent_on->toDateString());
    }

    public function test_dry_run_lista_sem_enviar_nem_gravar(): void
    {
        $user = $this->titular(['name' => 'Joana Teste']);
        $this->faturaFechadaVencendoEm3Dias($user);

        $this->artisan('lembretes:vencimentos', ['--dry-run' => true])
            ->expectsOutputToContain('Joana Teste')
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($user->fresh()->reminder_last_sent_on);
    }

    public function test_sem_mailer_nao_envia_e_nao_e_erro(): void
    {
        config(['mail.default' => 'log']);
        $user = $this->titular();
        $this->faturaFechadaVencendoEm3Dias($user);

        $this->artisan('lembretes:vencimentos')
            ->expectsOutputToContain('Sem mailer')
            ->assertSuccessful();

        Mail::assertNothingSent();
        // Não grava a data: quando o SMTP entrar, a pessoa recebe na primeira passada.
        $this->assertNull($user->fresh()->reminder_last_sent_on);
    }

    public function test_o_comando_esta_agendado_diariamente(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'lembretes:vencimentos'));

        $this->assertNotNull($evento, 'lembretes:vencimentos precisa estar no agendador — senão ninguém é avisado.');
        $this->assertSame('0 8 * * *', $evento->expression);
    }

    // ───────────────────────────────────────────────────────────── preferência na tela

    public function test_toggle_nas_configuracoes_grava_a_preferencia(): void
    {
        $user = $this->titular();
        $this->assertTrue($user->reminder_emails, 'Nasce ligado.');

        $this->actingAs($user)->get(route('settings', 'conta'))
            ->assertOk()
            ->assertSee('Lembretes de vencimento por e-mail')
            ->assertSee('aria-checked="true"', false);

        $this->actingAs($user)->patch(route('settings.lembretes'), ['reminder_emails' => 0])
            ->assertRedirect(route('settings', 'conta'))
            ->assertSessionHas('status', 'Lembretes de vencimento por e-mail desligados.');
        $this->assertFalse($user->fresh()->reminder_emails);

        $this->actingAs($user)->get(route('settings', 'conta'))->assertSee('aria-checked="false"', false);

        $this->actingAs($user)->patch(route('settings.lembretes'), ['reminder_emails' => 1]);
        $this->assertTrue($user->fresh()->reminder_emails);

        $this->actingAs($user)->patch(route('settings.lembretes'), ['reminder_emails' => 'talvez'])
            ->assertSessionHasErrors('reminder_emails');
    }

    public function test_dependente_nao_muda_a_preferencia_e_ve_que_o_titular_recebe(): void
    {
        $titular = $this->titular();
        $dependente = User::factory()->create(['account_owner_id' => $titular->id, 'is_admin' => false]);

        $this->actingAs($dependente)->get(route('settings', 'conta'))
            ->assertOk()
            ->assertSee('Vão para o titular')
            ->assertDontSee('settings.lembretes');

        $this->actingAs($dependente)->patch(route('settings.lembretes'), ['reminder_emails' => 0])
            ->assertForbidden();
    }

    public function test_politica_de_privacidade_descreve_os_lembretes(): void
    {
        $this->get('/privacidade')
            ->assertOk()
            ->assertSee('lembretes de vencimento')
            ->assertSee('Configurações › Conta');
    }
}
