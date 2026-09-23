<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Mail\ContaDaFamiliaExcluida;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Excluir a conta do titular leva o login de cada dependente — e agora todo mundo sabe
 * disso (item 11 da rodada de 22/09/2026).
 *
 * O defeito: o hook `deleting` do User apaga os dependentes um a um, mas o modal de
 * exclusão só listava as pendências financeiras. O titular apagava o acesso da família
 * sem ver um nome sequer, e os dependentes não recebiam nada: descobriam ao tentar entrar,
 * com "credenciais inválidas" — e concluíam que erraram a senha ou que foram invadidos.
 *
 * O comportamento certo:
 *  - o modal NOMEIA quem perde o acesso;
 *  - o servidor exige um aceite explícito disso (`confirmo_dependentes`), no mesmo padrão
 *    do `confirmo_pendencias` — a regra vive em `ProfileController::dependentesQuePerdemOAcesso`,
 *    usada pela view e pelo controller;
 *  - cada dependente recebe um e-mail dizendo que a conta-família foi excluída pelo titular
 *    e que o acesso e os dados dele foram apagados;
 *  - dependente apagando o PRÓPRIO login: nada muda (não leva ninguém junto).
 */
class ExcluirTitularAvisaOsDependentesTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-1234';

    private User $titular;

    private User $ana;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create([
            'name' => 'Carla Titular',
            'email' => 'carla@familia.test',
            'password' => Hash::make(self::SENHA),
            'is_admin' => true,
        ]);

        // Criados fora de ordem alfabética de propósito: a lista sai ordenada por nome.
        $this->bruno = $this->dependente('Bruno Dependente', 'bruno@familia.test');
        $this->ana = $this->dependente('Ana Dependente', 'ana@familia.test');
    }

    private function dependente(string $nome, string $email): User
    {
        return User::factory()->create([
            'name' => $nome,
            'email' => $email,
            'password' => Hash::make(self::SENHA),
            'account_owner_id' => $this->titular->id,
            'is_admin' => false,
        ]);
    }

    /** @param  array<string, string>  $dados */
    private function excluir(User $quem, array $dados): TestResponse
    {
        return $this->actingAs($quem)
            ->from(route('settings', 'conta'))
            ->delete(route('profile.destroy'), $dados);
    }

    // ══════════════════════════════════════════════════ o modal

    public function test_o_modal_nomeia_quem_perde_o_acesso(): void
    {
        $this->actingAs($this->titular)->get(route('settings', 'conta'))
            ->assertOk()
            ->assertSeeInOrder([
                'Excluir sua conta?',
                'Estas 2 pessoas perdem o acesso junto com a sua conta:',
                'Ana Dependente', 'ana@familia.test',
                'Bruno Dependente', 'bruno@familia.test',
                'Vamos avisar cada um por e-mail.',
                'confirmo_dependentes',
            ]);
    }

    /** Sem mailer, a tela não promete um aviso que não vai sair. */
    public function test_sem_mailer_o_modal_nao_promete_aviso_por_email(): void
    {
        config(['mail.default' => 'log']);

        $this->actingAs($this->titular)->get(route('settings', 'conta'))
            ->assertOk()
            ->assertSee('O app não está enviando e-mails agora: avise cada um você mesmo.')
            ->assertDontSee('Vamos avisar cada um por e-mail.');
    }

    // ══════════════════════════════════════════════════ o aceite no servidor

    public function test_sem_confirmar_os_dependentes_a_conta_fica_de_pe(): void
    {
        Mail::fake();

        $this->excluir($this->titular, ['password' => self::SENHA])
            ->assertRedirect(route('settings', 'conta'))
            ->assertSessionHasErrorsIn('userDeletion', [
                'confirmo_dependentes' => 'Confirme que você entendeu que Ana Dependente e Bruno Dependente perdem o acesso junto com a sua conta.',
            ]);

        $this->assertModelExists($this->titular);
        $this->assertModelExists($this->ana);
        $this->assertModelExists($this->bruno);
        $this->assertAuthenticatedAs($this->titular);
        Mail::assertNothingSent();
    }

    public function test_com_um_dependente_so_a_mensagem_fala_dele(): void
    {
        $this->ana->delete();

        $this->excluir($this->titular, ['password' => self::SENHA])
            ->assertSessionHasErrorsIn('userDeletion', [
                'confirmo_dependentes' => 'Confirme que você entendeu que Bruno Dependente perde o acesso junto com a sua conta.',
            ]);

        $this->assertModelExists($this->titular);
    }

    public function test_confirmando_a_conta_sai_com_os_dependentes(): void
    {
        Mail::fake();

        $this->excluir($this->titular, ['password' => self::SENHA, 'confirmo_dependentes' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertModelMissing($this->titular);
        $this->assertModelMissing($this->ana);
        $this->assertModelMissing($this->bruno);
        $this->assertGuest();
    }

    /** O aceite dos dependentes não dispensa a senha — nem o das pendências. */
    public function test_o_aceite_nao_substitui_a_senha(): void
    {
        Mail::fake();

        $this->excluir($this->titular, ['password' => 'senha-errada', 'confirmo_dependentes' => '1'])
            ->assertSessionHasErrorsIn('userDeletion', 'password');

        $this->assertModelExists($this->titular);
        $this->assertModelExists($this->ana);
        Mail::assertNothingSent();
    }

    // ══════════════════════════════════════════════════ o aviso aos dependentes

    public function test_cada_dependente_recebe_o_aviso_de_que_a_conta_da_familia_foi_excluida(): void
    {
        Mail::fake();

        $this->excluir($this->titular, ['password' => self::SENHA, 'confirmo_dependentes' => '1']);

        Mail::assertSent(ContaDaFamiliaExcluida::class, 2);

        foreach ([$this->ana, $this->bruno] as $dependente) {
            Mail::assertSent(ContaDaFamiliaExcluida::class, function (ContaDaFamiliaExcluida $mail) use ($dependente) {
                $html = $mail->render();

                return $mail->hasTo($dependente->email)
                    // O aviso é para o DEPENDENTE, não para quem excluiu.
                    && ! $mail->hasTo('carla@familia.test')
                    && str_contains($mail->envelope()->subject, 'Carla Titular excluiu a conta-família')
                    && str_contains($html, 'Olá, '.$dependente->name.'.')
                    && str_contains($html, 'excluiu a conta')
                    && str_contains($html, 'o seu login')
                    && str_contains($html, 'Excluída por');
            });
        }

        // O titular continua recebendo o próprio aviso, como sempre.
        Mail::assertSent(AlertaDeSeguranca::class, fn (AlertaDeSeguranca $mail) => $mail->hasTo('carla@familia.test')
            && str_contains($mail->assunto, 'foi excluída'));
    }

    /**
     * Construído de verdade (transporte `array`), como no AlertasDeSegurancaTest: montar à
     * mão passaria mesmo se o Mailable parasse de declarar a versão texto.
     */
    public function test_o_aviso_vai_em_html_e_em_texto_e_escapa_o_nome(): void
    {
        Mail::to('ana@familia.test')->send(new ContaDaFamiliaExcluida(
            dependenteNome: 'Ana',
            titularNome: '<b>Carla</b>',
            quando: '22 de setembro de 2026, às 10:00',
        ));

        $mensagem = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $html = $mensagem->getHtmlBody();
        $texto = $mensagem->getTextBody();

        $this->assertNotEmpty($texto, 'O e-mail saiu só em HTML — sem a parte texto.');

        foreach ([$html, $texto] as $parte) {
            $this->assertStringContainsString('A conta-família foi excluída', $parte);
            $this->assertStringContainsString('22 de setembro de 2026, às 10:00', $parte);
            $this->assertStringContainsString((string) config('legal.contact_email'), $parte);
        }

        // Nome vindo do usuário não vira marcação no e-mail.
        $this->assertStringNotContainsString('<b>Carla</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Carla&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<strong>', $texto);
    }

    public function test_sem_mailer_ninguem_e_avisado_e_a_exclusao_segue(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);

        $this->excluir($this->titular, ['password' => self::SENHA, 'confirmo_dependentes' => '1'])
            ->assertRedirect('/');

        $this->assertModelMissing($this->titular);
        Mail::assertNothingSent();
    }

    // ══════════════════════════════════════════════════ o que não muda

    /** Dependente apagando o próprio login não leva ninguém junto: sem aceite, sem aviso. */
    public function test_dependente_apagando_o_proprio_login_nao_muda(): void
    {
        Mail::fake();

        $this->actingAs($this->ana)->get(route('settings', 'conta'))
            ->assertOk()
            ->assertDontSee('confirmo_dependentes', false);

        $this->excluir($this->ana, ['password' => self::SENHA])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertModelMissing($this->ana);
        $this->assertModelExists($this->titular);
        $this->assertModelExists($this->bruno);
        Mail::assertNotSent(ContaDaFamiliaExcluida::class);
    }

    public function test_titular_sem_dependentes_nao_ve_nem_precisa_do_aceite(): void
    {
        $sozinho = User::factory()->create(['password' => Hash::make(self::SENHA)]);

        $this->actingAs($sozinho)->get(route('settings', 'conta'))
            ->assertOk()
            ->assertDontSee('confirmo_dependentes', false);

        $this->excluir($sozinho, ['password' => self::SENHA])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertModelMissing($sozinho);
    }
}
