<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Mail\ConfirmarNovoEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SimulaSmtpQueRecusa;
use Tests\TestCase;

/**
 * Pedir a troca do próprio e-mail não quebra quando o link não sai (item 3 da rodada de
 * 22/09/2026).
 *
 * O defeito: em /meu-perfil, com o SMTP recusando o endereço novo ("550"), o
 * `ProfileController::update` respondia **HTTP 500**. O envio do link saía por `Mail::to`
 * direto, sem proteção — o mesmo buraco já fechado no cadastro
 * (CadastroNaoQuebraComSmtpForaTest) e no reenvio do link (ReenvioDoLinkDeVerificacaoTest).
 * E como o `pending_email` era gravado ANTES do envio, a conta ficava com uma troca
 * pendente que nenhum link confirma.
 *
 * O comportamento certo, e o porquê de cada parte:
 *  - a tela diz a verdade: o link não saiu, confira o endereço e tente de novo;
 *  - nenhuma pendência fica gravada — nem a deste pedido, nem a de um pedido anterior
 *    (o pedido novo sempre tomou o lugar do anterior; ressuscitá-lo reabriria justamente o
 *    endereço que a pessoa talvez estivesse corrigindo);
 *  - o aviso "pediram para trocar o seu e-mail" ao endereço atual NÃO sai: ele fala de um
 *    pedido, e o pedido não ficou de pé;
 *  - nome, telefone e foto enviados junto continuam salvos.
 */
class TrocaDeEmailNaoQuebraComSmtpForaTest extends TestCase
{
    use RefreshDatabase;
    use SimulaSmtpQueRecusa;

    private const SENHA = 'senha-de-teste-1234';

    private const ATUAL = 'dono@atual.test';

    private const NOVO = 'digitado@novo.test';

    private function dono(): User
    {
        return User::factory()->create([
            'name' => 'Dono',
            'email' => self::ATUAL,
            'phone' => null,
            'password' => Hash::make(self::SENHA),
        ]);
    }

    /** @param  array<string, mixed>  $extra  outros campos do mesmo formulário */
    private function pedirTroca(User $user, array $extra = []): TestResponse
    {
        return $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => self::NOVO,
                'current_password' => self::SENHA,
                ...$extra,
            ]);
    }

    // ------------------------------------------------------- SMTP recusando (o defeito)

    public function test_smtp_recusando_o_endereco_novo_nao_da_mais_erro_500(): void
    {
        $this->smtpQueRecusa();

        $this->pedirTroca($this->dono())
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('email');
    }

    public function test_sem_link_enviado_nao_fica_pendencia_nenhuma(): void
    {
        $this->smtpQueRecusa();
        $user = $this->dono();

        $this->pedirTroca($user);

        $user->refresh();
        $this->assertSame(self::ATUAL, $user->email, 'O e-mail de acesso não pode mudar sem confirmação.');
        $this->assertNull($user->pending_email, 'Ficou uma troca pendente que nenhum link confirma.');
        $this->assertNull($user->pending_email_sent_at);
    }

    /**
     * Um pedido anterior, ainda pendente, também sai de cena. O pedido novo sempre invalidou
     * o link do anterior (o `hash` do link muda); a falha não o ressuscita.
     */
    public function test_a_falha_nao_ressuscita_o_pedido_anterior(): void
    {
        $this->smtpQueRecusa();
        $user = $this->dono();
        $user->forceFill(['pending_email' => 'pedido@anterior.test', 'pending_email_sent_at' => now()->subMinutes(10)])->save();

        $this->pedirTroca($user)->assertRedirect(route('profile.edit'));

        $this->assertNull($user->fresh()->pending_email);
    }

    public function test_a_tela_diz_a_verdade_e_devolve_o_endereco_para_conferir(): void
    {
        $this->smtpQueRecusa();

        $this->followingRedirects()
            ->actingAs($this->dono())
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Dono',
                'email' => self::NOVO,
                'current_password' => self::SENHA,
            ])
            ->assertOk()
            ->assertSee('Não conseguimos enviar o link de confirmação para '.self::NOVO)
            ->assertSee('você continua entrando com '.self::ATUAL)
            ->assertSee('Confira se o endereço está certo e tente de novo')
            // O endereço digitado volta no campo: é onde se enxerga um erro de digitação.
            ->assertSee('value="'.self::NOVO.'"', false)
            ->assertDontSee('Enviamos um link de confirmação');
    }

    public function test_nome_telefone_e_foto_do_mesmo_envio_continuam_salvos(): void
    {
        $this->smtpQueRecusa();
        Storage::fake(User::AVATAR_DISK);
        $user = $this->dono();

        $this->pedirTroca($user, [
            'name' => 'Nome Novo',
            'phone' => '(11) 90000-0000',
            'avatar' => UploadedFile::fake()->create('eu.jpg', 12, 'image/jpeg'),
        ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('email');

        // A tela avisa que o resto ficou — senão a pessoa refaz tudo achando que perdeu.
        $this->assertStringContainsString(
            'As outras alterações do perfil foram salvas.',
            (string) session('errors')->first('email'),
        );

        $user->refresh();
        $this->assertSame('Nome Novo', $user->name);
        $this->assertSame('(11) 90000-0000', $user->phone);
        $this->assertNotNull($user->avatar_path);
        Storage::disk(User::AVATAR_DISK)->assertExists($user->avatar_path);
        $this->assertSame(self::ATUAL, $user->email);
    }

    /**
     * O aviso ao endereço atual ("pediram para trocar o seu e-mail") não é nem TENTADO.
     * Com este SMTP qualquer tentativa também falharia e sumiria no log — por isso a prova
     * é pelas tentativas de envio, e não pelo que chegou.
     */
    public function test_o_aviso_ao_endereco_atual_nao_sai_se_o_pedido_nao_ficou_de_pe(): void
    {
        $this->smtpQueRecusa();

        $tentativas = [];
        Event::listen(MessageSending::class, function (MessageSending $evento) use (&$tentativas) {
            foreach ($evento->message->getTo() as $destinatario) {
                $tentativas[] = $destinatario->getAddress();
            }
        });

        $this->pedirTroca($this->dono());

        $this->assertSame([self::NOVO], $tentativas, 'Só o link ao endereço novo podia ter sido tentado.');
    }

    /** A falha é de operação, não de tela: fica no log para alguém olhar o SMTP. */
    public function test_a_falha_vai_para_o_log(): void
    {
        $this->smtpQueRecusa();
        Log::spy();

        $this->pedirTroca($this->dono());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensagem, array $contexto = []) => $mensagem === 'E-mail não foi entregue.'
                && str_contains($contexto['oque'] ?? '', 'troca de e-mail')
                && str_contains($contexto['erro'] ?? '', '550'))
            ->once();
    }

    // -------------------------------------------------------------- SMTP no ar (feliz)

    /**
     * O invariante é estrutural: no instante em que o link sai, a pendência AINDA não está
     * no banco — ela só é gravada com a prova de entrega na mão. Se o processo morrer entre
     * uma coisa e outra, sobra no máximo um link que não vale, nunca uma pendência sem link.
     */
    public function test_a_pendencia_so_e_gravada_depois_que_o_link_saiu(): void
    {
        // Transporte `array` de verdade (o do phpunit.xml): o `Mail::fake()` não dispara o
        // evento de envio, e é nele que se olha o banco no meio do caminho.
        $user = $this->dono();

        $pendenciaNoEnvio = [];
        Event::listen(MessageSending::class, function (MessageSending $evento) use (&$pendenciaNoEnvio, $user) {
            if (str_contains((string) $evento->message->getSubject(), 'Confirme seu novo e-mail')) {
                $pendenciaNoEnvio[] = DB::table('users')->where('id', $user->id)->value('pending_email');
            }
        });

        $this->pedirTroca($user)->assertSessionHasNoErrors();

        $this->assertSame([null], $pendenciaNoEnvio, 'A pendência foi gravada antes de o link sair.');
        $this->assertSame(self::NOVO, $user->fresh()->pending_email);
        $this->assertNotNull($user->fresh()->pending_email_sent_at);
    }

    public function test_com_o_link_entregue_o_pedido_segue_como_sempre(): void
    {
        Mail::fake();
        $user = $this->dono();

        $this->pedirTroca($user)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Enviamos um link de confirmação para '.self::NOVO));

        Mail::assertSent(ConfirmarNovoEmail::class, fn (ConfirmarNovoEmail $mail) => $mail->hasTo(self::NOVO));
        Mail::assertSent(AlertaDeSeguranca::class, fn (AlertaDeSeguranca $mail) => $mail->hasTo(self::ATUAL)
            && str_contains($mail->assunto, 'Pedido para trocar o e-mail'));
        $this->assertSame(self::NOVO, $user->fresh()->pending_email);
    }
}
