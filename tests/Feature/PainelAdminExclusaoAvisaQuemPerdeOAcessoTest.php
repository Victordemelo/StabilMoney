<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Mail\AlertaDoPainel;
use App\Mail\ContaDaFamiliaExcluida;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mime\Address;
use Tests\Concerns\SimulaSmtpQueRecusa;
use Tests\TestCase;

/**
 * Excluir uma pessoa pelo painel avisa quem perde o acesso (decisão de 23/09/2026).
 *
 * O defeito: só a exclusão feita pelo próprio titular (ProfileController::destroy) avisava —
 * o titular e cada dependente. Pelo painel ninguém recebia nada: a família inteira descobria
 * ao tentar entrar, com "credenciais inválidas", e concluía que errou a senha ou foi invadida.
 * Ficava registrado como "decisão pendente de moderação/LGPD"; agora está decidido: avisa.
 *
 * As mesmas regras do caminho do titular:
 *  - montado ANTES do delete, enviado só DEPOIS do commit — exclusão desfeita não avisa ninguém;
 *  - pelo `Notificador`: SMTP recusando não desfaz a exclusão nem vira HTTP 500;
 *  - excluir um DEPENDENTE não fala em "conta-família excluída" (a família continua de pé).
 *
 * E o que é próprio da moderação: o texto diz que foi a administração, sem o motivo (o que a
 * moderação anotou é registro interno) e sem o IP/aparelho de quem agiu, com o contato de
 * `legal.contact_email`. A pessoa excluída também é avisada — coerente com os dependentes:
 * seria estranho a família inteira saber, menos o dono da conta.
 */
class PainelAdminExclusaoAvisaQuemPerdeOAcessoTest extends TestCase
{
    use RefreshDatabase;
    use SimulaSmtpQueRecusa;

    private Admin $admin;

    private User $titular;

    private User $ana;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.enabled' => true]);

        $this->admin = Admin::factory()->comDoisFatores()->create(['name' => 'Chefe', 'email' => 'chefe@painel.test']);

        $this->titular = User::factory()->create([
            'name' => 'Carla Titular', 'email' => 'carla@familia.test', 'is_admin' => true,
        ]);
        // Fora de ordem alfabética de propósito: o aviso ao titular sai ordenado por nome.
        $this->bruno = $this->dependente('Bruno Dependente', 'bruno@familia.test');
        $this->ana = $this->dependente('Ana Dependente', 'ana@familia.test');
    }

    private function dependente(string $nome, string $email): User
    {
        return User::factory()->create([
            'name' => $nome, 'email' => $email,
            'account_owner_id' => $this->titular->id, 'is_admin' => false,
        ]);
    }

    /** Exclui pela ficha, como o formulário do painel faz. */
    private function excluir(User $quem): TestResponse
    {
        return $this->actingAs($this->admin, 'admin')
            ->withSession(['admin_2fa_ok' => true])
            ->from(route('painel.pessoa', $this->titular->id))
            ->delete(route('painel.excluir', $quem->id), ['confirmacao' => $quem->email]);
    }

    /** O HTML do aviso de conta excluída que foi para este endereço. */
    private function avisoDeContaExcluidaPara(string $email): string
    {
        $html = null;

        Mail::assertSent(AlertaDeSeguranca::class, function (AlertaDeSeguranca $mail) use ($email, &$html) {
            if (! $mail->hasTo($email) || $mail->titulo !== 'Conta excluída') {
                return false;
            }

            $html = $mail->render();

            return true;
        });

        return (string) $html;
    }

    // ══════════════════════════════════════════════════ excluir o titular

    public function test_cada_dependente_e_avisado_de_que_a_conta_familia_foi_excluida_pela_administracao(): void
    {
        Mail::fake();

        $this->excluir($this->titular)->assertSessionHasNoErrors()->assertRedirect(route('painel.pessoas'));

        Mail::assertSent(ContaDaFamiliaExcluida::class, 2);

        foreach ([$this->ana, $this->bruno] as $dependente) {
            Mail::assertSent(ContaDaFamiliaExcluida::class, function (ContaDaFamiliaExcluida $mail) use ($dependente) {
                $html = $mail->render();

                return $mail->hasTo($dependente->email)
                    && $mail->pelaAdministracao
                    && $mail->envelope()->subject === 'A conta-família de Carla Titular foi excluída no Stabil Money'
                    && str_contains($html, 'Olá, '.$dependente->name.'.')
                    && str_contains($html, 'foi <strong>excluída pela administração do app</strong>')
                    && str_contains($html, 'o seu login')
                    && str_contains($html, (string) config('legal.contact_email'))
                    // Não põe na conta do titular o que foi a administração que fez.
                    && ! str_contains($html, 'excluiu a conta')
                    && ! str_contains($html, 'Fale com');
            });
        }
    }

    /**
     * O titular também é avisado — e o aviso nomeia quem perdeu o acesso junto, como no
     * caminho do perfil. Só o QUANDO vai nos detalhes: o IP e o aparelho da requisição são os
     * do administrador.
     */
    public function test_o_titular_excluido_e_avisado_e_o_aviso_nomeia_os_dependentes(): void
    {
        Mail::fake();

        $this->excluir($this->titular)->assertSessionHasNoErrors();

        $html = $this->avisoDeContaExcluidaPara('carla@familia.test');

        $this->assertStringContainsString('excluída pela administração do app', $html);
        $this->assertStringContainsString('<strong>Ana Dependente</strong> e <strong>Bruno Dependente</strong>', $html);
        $this->assertStringContainsString('A administração do Stabil Money excluiu a sua conta e o acesso de 2 dependentes.', $html);
        $this->assertStringContainsString((string) config('legal.contact_email'), $html);

        $this->assertStringNotContainsString('Endereço IP', $html);
        $this->assertStringNotContainsString('127.0.0.1', $html);
        $this->assertStringNotContainsString('Aparelho', $html);
        // "Não foi você?" não cabe: ninguém espera que tenha sido.
        $this->assertStringNotContainsString('Não foi você', $html);
    }

    /**
     * O motivo da moderação é registro interno. Um titular banido por fraude e depois
     * excluído não recebe — nem a família dele — o texto que o moderador escreveu.
     */
    public function test_nenhum_aviso_expoe_o_motivo_da_moderacao(): void
    {
        Mail::fake();

        $this->actingAs($this->admin, 'admin')
            ->withSession(['admin_2fa_ok' => true])
            ->post(route('painel.banir', $this->titular->id), ['motivo' => 'Chargeback fraudulento no cartão'])
            ->assertSessionHasNoErrors();

        $this->excluir($this->titular->fresh())->assertSessionHasNoErrors();

        $renderizados = 0;

        foreach ([AlertaDeSeguranca::class, ContaDaFamiliaExcluida::class] as $classe) {
            Mail::assertSent($classe, function ($mail) use (&$renderizados) {
                $renderizados++;
                $this->assertStringNotContainsString('Chargeback', $mail->render());

                return true;
            });
        }

        // Titular + 2 dependentes: nenhum ficou de fora da checagem.
        $this->assertSame(3, $renderizados);
    }

    /**
     * Os avisos saem DEPOIS da exclusão: no instante de cada envio, a família já não existe.
     * Transporte `array` de verdade — o `Mail::fake()` não dispara o evento de envio, e é nele
     * que se olha o banco.
     */
    public function test_os_avisos_saem_depois_que_a_exclusao_ja_aconteceu(): void
    {
        $familiaExistiaNoEnvio = [];

        Event::listen(MessageSending::class, function (MessageSending $envio) use (&$familiaExistiaNoEnvio) {
            $para = array_map(fn (Address $a) => $a->getAddress(), $envio->message->getTo());

            $familiaExistiaNoEnvio[implode(',', $para)] = User::whereKey($this->titular->id)->exists()
                || User::whereKey($this->ana->id)->exists()
                || User::whereKey($this->bruno->id)->exists();
        });

        $this->excluir($this->titular)->assertSessionHasNoErrors()->assertRedirect(route('painel.pessoas'));

        // O alerta ao admin + o titular + os 2 dependentes — os quatro com a família já apagada.
        $this->assertSame([
            'chefe@painel.test' => false,
            'carla@familia.test' => false,
            'ana@familia.test' => false,
            'bruno@familia.test' => false,
        ], $familiaExistiaNoEnvio);
    }

    // ══════════════════════════════════════════════════ quando dá errado

    /** Exclusão desfeita (erro no meio do delete) não avisa ninguém de uma exclusão que não houve. */
    public function test_exclusao_que_falha_nao_avisa_ninguem(): void
    {
        Mail::fake();
        Exceptions::fake();

        $apagados = 0;
        User::deleting(function (User $alvo) use (&$apagados) {
            if (! $alvo->isTitular() && ++$apagados === 2) {
                throw new \RuntimeException('Falha simulada ao apagar o segundo dependente.');
            }
        });

        $this->excluir($this->titular)->assertSessionHasErrors('excluir');

        $this->assertModelExists($this->titular);
        $this->assertModelExists($this->ana);
        $this->assertModelExists($this->bruno);
        Mail::assertNothingSent();
    }

    /**
     * SMTP recusando (o "550" de um servidor real) não desfaz a exclusão nem vira HTTP 500: a
     * ação é o que importa, o aviso é acessório — e a falha vai para o log.
     */
    public function test_smtp_que_recusa_nao_desfaz_a_exclusao_nem_vira_erro(): void
    {
        $this->smtpQueRecusa();

        $naoEntregues = [];
        Log::listen(function ($log) use (&$naoEntregues) {
            if ($log->message === 'E-mail não foi entregue.') {
                $naoEntregues[] = $log->context['oque'];
            }
        });

        $this->excluir($this->titular)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('painel.pessoas'))
            ->assertSessionHas('status', 'Conta de Carla Titular <carla@familia.test> excluída definitivamente.');

        $this->assertModelMissing($this->titular);
        $this->assertModelMissing($this->ana);
        $this->assertModelMissing($this->bruno);
        $this->assertDatabaseHas('admin_audit_logs', ['acao' => AdminAuditLog::EXCLUIU, 'target_user_id' => $this->titular->id]);

        // Cada aviso foi TENTADO — e a recusa de um não impediu os outros.
        $this->assertSame(
            [AlertaDeSeguranca::class, ContaDaFamiliaExcluida::class, ContaDaFamiliaExcluida::class],
            $naoEntregues,
        );
    }

    public function test_sem_mailer_ninguem_e_avisado_e_a_exclusao_segue(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);

        $this->excluir($this->titular)->assertSessionHasNoErrors()->assertRedirect(route('painel.pessoas'));

        $this->assertModelMissing($this->titular);
        Mail::assertNothingSent();
    }

    // ══════════════════════════════════════════════════ excluir um dependente

    /**
     * Excluir UM dependente pelo painel não é excluir a família: ninguém recebe "a
     * conta-família foi excluída". Quem perdeu o acesso — só ele — é avisado, e o aviso diz
     * que o dinheiro da família continua com o titular. O titular não recebe aviso de
     * exclusão (a conta dele segue de pé).
     */
    public function test_excluir_um_dependente_nao_fala_em_conta_familia_excluida(): void
    {
        Mail::fake();

        $this->excluir($this->ana)->assertSessionHasNoErrors()->assertRedirect(route('painel.pessoas'));

        $this->assertModelMissing($this->ana);
        $this->assertModelExists($this->titular);
        $this->assertModelExists($this->bruno);

        Mail::assertNotSent(ContaDaFamiliaExcluida::class);
        Mail::assertNotSent(AlertaDeSeguranca::class, fn (AlertaDeSeguranca $mail) => $mail->hasTo('carla@familia.test'));
        Mail::assertNotSent(AlertaDeSeguranca::class, fn (AlertaDeSeguranca $mail) => $mail->hasTo('bruno@familia.test'));

        $html = $this->avisoDeContaExcluidaPara('ana@familia.test');

        $this->assertStringContainsString('excluída pela administração do app', $html);
        $this->assertStringContainsString('o seu login, a sua foto de perfil e os seus dados pessoais foram apagados', $html);
        $this->assertStringContainsString('continua com <strong>Carla Titular</strong>', $html);
        $this->assertStringNotContainsString('junto com os lançamentos, contas, metas', $html);

        // E o alerta ao admin continua saindo, como em toda exclusão.
        Mail::assertSent(AlertaDoPainel::class, fn (AlertaDoPainel $mail) => $mail->acao === AdminAuditLog::EXCLUIU);
    }
}
