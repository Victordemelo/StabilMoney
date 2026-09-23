<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RedefinicaoDeSenha;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SimulaSmtpQueRecusa;
use Tests\TestCase;

/**
 * "Esqueci a senha" não revela quem tem conta e não quebra com o SMTP recusando (achado da
 * rodada de 22-23/09/2026).
 *
 * Os defeitos:
 * - o link era enviado DENTRO da requisição. O broker garante um tempo mínimo de resposta
 *   para todo pedido (`auth.timebox_duration`), mas o envio (~1 s) estourava esse mínimo só
 *   para e-mail CADASTRADO — medindo a resposta, um script montava a lista de clientes;
 * - o pedido repetido (dentro de 60 s) respondia "aguarde" só para e-mail cadastrado: pedir
 *   duas vezes bastava para saber quem tem conta;
 * - SMTP recusando o endereço ("550") era HTTP 500.
 *
 * O comportamento certo: a mesma resposta para todo mundo, o link enviado depois dela (pelo
 * Notificador, com a falha no log) e um tempo mínimo que cubra o argon2id do token.
 */
class EsqueciASenhaNaoRevelaQuemTemContaTest extends TestCase
{
    use RefreshDatabase;
    use SimulaSmtpQueRecusa;

    private function pedir(string $email): TestResponse
    {
        return $this->from('/forgot-password')->post('/forgot-password', ['email' => $email]);
    }

    /** O que a pessoa vê: o código, para onde volta, o recado e os erros. */
    private function oQueAparece(TestResponse $resposta): array
    {
        return [
            'codigo' => $resposta->getStatusCode(),
            'destino' => $resposta->headers->get('Location'),
            'recado' => session('status'),
            'erros' => session('errors')?->getBag('default')->all() ?? [],
        ];
    }

    public function test_smtp_recusando_nao_da_erro_500_e_a_resposta_e_a_de_sempre(): void
    {
        $user = User::factory()->create();
        $this->smtpQueRecusa();

        $avisos = [];
        Log::listen(function (MessageLogged $registro) use (&$avisos) {
            $avisos[] = $registro->message;
        });

        $this->pedir($user->email)
            ->assertRedirect('/forgot-password')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __(Password::RESET_LINK_SENT));

        $this->assertContains('E-mail não foi entregue.', $avisos, 'A falha do SMTP não ficou registrada no log.');
    }

    public function test_conta_existente_inexistente_e_pedido_repetido_veem_a_mesma_coisa(): void
    {
        $user = User::factory()->create();
        Notification::fake();

        $existente = $this->oQueAparece($this->pedir($user->email));
        $this->flushSession();
        $inexistente = $this->oQueAparece($this->pedir('ninguem@nao-existe.test'));
        $this->flushSession();
        // Dentro de 60 s: o broker responde THROTTLED — só para e-mail cadastrado.
        $repetido = $this->oQueAparece($this->pedir($user->email));

        $this->assertSame($existente, $inexistente);
        $this->assertSame($existente, $repetido, 'O pedido repetido respondia diferente só para e-mail cadastrado.');
        Notification::assertSentToTimes($user, RedefinicaoDeSenha::class, 1);
    }

    /**
     * O envio acontece DEPOIS de a resposta estar pronta: é isso que tira o tempo do SMTP do
     * tempo de resposta. `RequestHandled` é disparado quando o kernel termina a resposta; o
     * `MessageSending`, quando o e-mail de fato sai.
     */
    public function test_o_link_sai_depois_que_a_resposta_esta_pronta(): void
    {
        $user = User::factory()->create();

        $ordem = [];
        Event::listen(RequestHandled::class, function () use (&$ordem) {
            $ordem[] = 'resposta';
        });
        Event::listen(MessageSending::class, function () use (&$ordem) {
            $ordem[] = 'envio';
        });

        $this->pedir($user->email)->assertSessionHasNoErrors();

        $this->assertSame(['resposta', 'envio'], $ordem, 'O link saiu antes da resposta: o tempo dela revela quem tem conta.');
    }

    /** Adiar o envio não pode duplicá-lo: cada pedido manda um link só. */
    public function test_cada_pedido_manda_um_link_so(): void
    {
        $ana = User::factory()->create();
        $bruno = User::factory()->create();
        Notification::fake();

        $this->pedir($ana->email);
        $this->flushSession();
        $this->pedir($bruno->email);

        Notification::assertSentToTimes($ana, RedefinicaoDeSenha::class, 1);
        Notification::assertSentToTimes($bruno, RedefinicaoDeSenha::class, 1);
    }

    /**
     * O tempo mínimo cobre o argon2id do token (~134 ms no container de dev; mais numa VPS
     * modesta). Com os 200 ms do framework, o hash sozinho já chegava perto do limite, e
     * passar dele faria o e-mail cadastrado responder mais devagar. Vale também para o login.
     */
    public function test_o_tempo_minimo_das_respostas_de_autenticacao_cobre_o_argon2id(): void
    {
        $this->assertGreaterThanOrEqual(500000, (int) config('auth.timebox_duration'));
    }
}
