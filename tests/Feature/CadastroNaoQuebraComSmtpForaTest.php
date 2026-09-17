<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * O cadastro não pode quebrar porque o e-mail não saiu (achado A-1 da auditoria de 05/09).
 *
 * O defeito reproduzido: com o SMTP real do `.env` recusando o destinatário
 * ("550 The mail server could not deliver mail to …"), `POST /register` respondia
 * **HTTP 500** — e o usuário ficava gravado assim mesmo, com `email_verified_at` nulo.
 * Como o app inteiro roda sob o middleware `verified`, isso é uma conta trancada; e como
 * `users.email` é `unique`, o endereço ficava queimado, sem nem poder recadastrar. Nem a
 * tela de "reenviar link" salvava: o servidor recusa aquele destinatário de novo.
 *
 * A causa: o envio saía do `event(new Registered($user))` → listener
 * `SendEmailVerificationNotification`, do framework, que não tem try/catch. Ou seja,
 * escapava do `App\Support\Notificador`, o helper que já concentra a regra do projeto:
 * *o aviso é acessório; a ação é o que importa*.
 *
 * A saída escolhida, e o porquê: o CLAUDE.md fixa o invariante **"nunca deixe
 * `email_verified_at` nulo num caminho que não envie link"**. Se o link não saiu, não
 * existe link — logo o usuário nasce VERIFICADO, exatamente como já acontecia quando
 * `Mailer::entrega()` é falso. A confirmação só passa a ser exigida DEPOIS da prova de
 * entrega, o que torna o invariante estrutural em vez de combinado.
 *
 * O que se perde: no instante em que o SMTP está quebrado, o app não consegue provar que
 * o endereço é de quem se cadastrou. Isso é aceito porque a falha é do NOSSO servidor, não
 * escolhida pelo atacante — e o preço da alternativa é destruir a conta de um usuário
 * legítimo. No caminho normal (SMTP no ar) a exigência continua valendo por inteiro.
 */
class CadastroNaoQuebraComSmtpForaTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'novo@example.com';

    /**
     * Instala um transporte que recusa TODO destinatário, como o SMTP real faz com um
     * endereço que ele não aceita entregar.
     *
     * `Mail::fake()` não serve aqui: ele troca o Mailer inteiro por um dublê que nunca
     * falha, então o cenário que se quer exercitar — a exceção de transporte — deixa de
     * existir. É preciso descer ao transporte do Symfony, que é onde o "550" nasce.
     */
    private function smtpQueRecusa(): void
    {
        Mail::extend('smtp-que-recusa', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new UnexpectedResponseException(
                    'Expected response code "250/251/252" but got code "550", with message '
                    .'"550 The mail server could not deliver mail to this address.".',
                    550
                );
            }

            public function __toString(): string
            {
                return 'smtp-que-recusa://';
            }
        });

        config()->set('mail.mailers.smtp-que-recusa', ['transport' => 'smtp-que-recusa']);
        config()->set('mail.default', 'smtp-que-recusa');
    }

    /** O dia em que o SMTP está no ar e entrega (caminho feliz). */
    private function smtpNoAr(): void
    {
        config()->set('mail.default', 'smtp');
    }

    private function cadastrar(string $email = self::EMAIL): TestResponse
    {
        return $this->post('/register', [
            'name' => 'Usuário Novo',
            'email' => $email,
            'password' => 'senha-bem-comprida-123',
            'terms' => '1',
        ]);
    }

    // ------------------------------------------------------- SMTP recusando (o defeito)

    /** O que o achado A-1 mediu: era 500. Agora é o cadastro concluído. */
    public function test_cadastro_conclui_mesmo_quando_o_smtp_recusa_o_destinatario(): void
    {
        $this->smtpQueRecusa();

        $this->cadastrar()
            ->assertRedirect(route('dashboard', absolute: false))
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => self::EMAIL]);
    }

    /**
     * O estado resultante é o que decide se a conta existe ou está perdida: sem link
     * enviado, `email_verified_at` NÃO pode ficar nulo — senão o middleware `verified`
     * transforma o cadastro numa conta trancada, com o endereço já ocupado.
     */
    public function test_sem_link_enviado_o_usuario_nasce_verificado_e_entra_no_app(): void
    {
        $this->smtpQueRecusa();

        $this->cadastrar();

        $user = User::where('email', self::EMAIL)->firstOrFail();

        $this->assertNotNull(
            $user->email_verified_at,
            'O link não saiu; deixar a conta por confirmar seria trancá-la sem chave.'
        );

        // Na prática: o app abre, sem desvio para a tela de confirmação.
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    /**
     * A falha de entrega é problema de operação, não de tela: precisa ficar registrada
     * para alguém investigar o SMTP — e é só isso que o usuário paga por ela.
     */
    public function test_falha_de_entrega_vira_aviso_no_log(): void
    {
        $this->smtpQueRecusa();
        Log::spy();

        $this->cadastrar();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensagem, array $contexto = []) => $mensagem === 'E-mail não foi entregue.'
                && str_contains($contexto['oque'] ?? '', 'verificação')
                && str_contains($contexto['erro'] ?? '', '550'))
            ->once();
    }

    /**
     * O `event(new Registered($user))` faz mais coisa do que mandar e-mail: é ele que
     * semeia as categorias padrão. Com o listener do framework estourando ali dentro, a
     * semeadura ficava refém da ordem de registro dos listeners — este teste tranca a
     * garantia de que o usuário começa organizado mesmo com o SMTP fora.
     */
    public function test_categorias_padrao_nascem_mesmo_com_o_smtp_fora(): void
    {
        $this->smtpQueRecusa();

        $this->cadastrar();

        $user = User::where('email', self::EMAIL)->firstOrFail();

        $this->assertGreaterThan(0, $user->categories()->where('type', 'income')->count());
        $this->assertGreaterThan(0, $user->categories()->where('type', 'expense')->count());
    }

    /**
     * O caminho de recuperação: não há conta para destravar, e é isso que se quer provar.
     *
     * Quem cai na tela de confirmação sem ter recebido link nenhum só teria como saída
     * pedir o reenvio — para o MESMO servidor que acabou de recusar o endereço. Aqui a
     * tela devolve a pessoa para o app, porque ela nunca foi trancada.
     */
    public function test_a_tela_de_confirmacao_devolve_para_o_app_em_vez_de_prender(): void
    {
        $this->smtpQueRecusa();

        $this->cadastrar();

        $this->get(route('verification.notice'))
            ->assertRedirect(route('dashboard', absolute: false));
    }

    // -------------------------------------------------------------- SMTP no ar (feliz)

    /**
     * Nada disso pode afrouxar a verificação quando ela é possível: com o servidor no ar,
     * o usuário continua nascendo POR CONFIRMAR e barrado nas rotas do app. É o que fecha
     * o buraco de se cadastrar com o e-mail de outra pessoa.
     */
    public function test_com_smtp_no_ar_o_usuario_nasce_por_confirmar_e_e_barrado(): void
    {
        $this->smtpNoAr();
        Notification::fake();

        $this->cadastrar();

        $user = User::where('email', self::EMAIL)->firstOrFail();

        $this->assertNull($user->email_verified_at, 'Com entrega possível, a confirmação continua obrigatória.');

        Notification::assertSentTo($user, VerifyEmail::class);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    /**
     * O link sai UMA vez. O envio deixou de sair do listener do framework e passou a ser
     * explícito no controller; se o listener voltasse a disparar junto (usuário ainda não
     * verificado no momento do evento), o usuário receberia dois e-mails idênticos.
     */
    public function test_o_link_de_verificacao_sai_uma_unica_vez(): void
    {
        $this->smtpNoAr();
        Notification::fake();

        $this->cadastrar();

        $user = User::where('email', self::EMAIL)->firstOrFail();

        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }
}
