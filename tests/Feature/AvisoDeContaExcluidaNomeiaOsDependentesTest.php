<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O aviso de conta excluída diz quem perdeu o acesso junto — e não diz a um dependente que o
 * dinheiro da família sumiu (item 4 da segunda rodada de 22/09/2026).
 *
 * O defeito: excluir o titular apaga o login de cada dependente (hook `deleting` do User), mas
 * o e-mail ao titular (`AlertaDeSeguranca::contaExcluida`) dizia só "sua conta foi excluída".
 * O modal nomeava as pessoas; o e-mail, que é o que fica, não — e se a exclusão não partiu do
 * dono (sessão sequestrada), é por ele que o dono descobre que a família inteira ficou sem
 * login. Na mesma frase, o dependente que apagava o PRÓPRIO login lia que os lançamentos, as
 * contas e as metas tinham sido apagados, o que é falso: são da família e ficam com o titular.
 *
 * Os nomes vêm de `ProfileController::dependentesQuePerdemOAcesso` (a mesma fonte do modal e
 * do aceite `confirmo_dependentes`), capturados antes do delete.
 */
class AvisoDeContaExcluidaNomeiaOsDependentesTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-1234';

    private User $titular;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->titular = User::factory()->create([
            'name' => 'Carla Titular',
            'email' => 'carla@familia.test',
            'password' => Hash::make(self::SENHA),
            'is_admin' => true,
        ]);
    }

    private function dependente(string $nome): User
    {
        return User::factory()->create([
            'name' => $nome,
            'password' => Hash::make(self::SENHA),
            'account_owner_id' => $this->titular->id,
            'is_admin' => false,
        ]);
    }

    private function excluir(User $quem): TestResponse
    {
        return $this->actingAs($quem)
            ->from(route('settings', 'conta'))
            ->delete(route('profile.destroy'), ['password' => self::SENHA, 'confirmo_dependentes' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');
    }

    /** O HTML do aviso de conta excluída que foi para este endereço. */
    private function avisoPara(string $email): string
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

    public function test_o_aviso_ao_titular_nomeia_os_dependentes_que_perderam_o_acesso(): void
    {
        $this->dependente('Bruno Dependente');
        $this->dependente('Ana Dependente');

        $this->excluir($this->titular);

        $html = $this->avisoPara('carla@familia.test');

        // Os dois, em ordem alfabética (a mesma do modal), e o que eles perderam.
        $this->assertStringContainsString('<strong>Ana Dependente</strong> e <strong>Bruno Dependente</strong>', $html);
        $this->assertStringContainsString('os acessos de quem era dependente da sua conta-família', $html);
        $this->assertStringContainsString('o acesso de 2 dependentes foram removidos', $html, 'O preheader não conta os dependentes.');
    }

    public function test_com_um_dependente_so_a_frase_fica_no_singular(): void
    {
        $this->dependente('Bruno Dependente');

        $this->excluir($this->titular);

        $html = $this->avisoPara('carla@familia.test');

        $this->assertStringContainsString('o acesso de <strong>Bruno Dependente</strong>, que era dependente', $html);
        $this->assertStringContainsString('o acesso de 1 dependente foram removidos', $html);
    }

    /** Nome vem do usuário: vai escapado, como todo dado no parágrafo do e-mail. */
    public function test_o_nome_do_dependente_vai_escapado(): void
    {
        $this->dependente('<b>Bia</b> & Cia');

        $this->excluir($this->titular);

        $html = $this->avisoPara('carla@familia.test');

        $this->assertStringContainsString('&lt;b&gt;Bia&lt;/b&gt; &amp; Cia', $html);
        $this->assertStringNotContainsString('<b>Bia</b>', $html);
    }

    public function test_titular_sem_dependentes_nao_recebe_frase_sobre_dependentes(): void
    {
        $this->excluir($this->titular);

        $html = $this->avisoPara('carla@familia.test');

        $this->assertStringNotContainsString('dependente', $html);
        $this->assertStringContainsString('junto com os lançamentos, contas, metas', $html);
    }

    /**
     * O dependente que apaga o próprio login não leva o dinheiro da família: o aviso a ele
     * não pode dizer que as contas e os lançamentos foram apagados — e diz com quem ficaram.
     */
    public function test_o_aviso_ao_dependente_nao_diz_que_o_dinheiro_da_familia_sumiu(): void
    {
        $bruno = $this->dependente('Bruno Dependente');

        $this->excluir($bruno);

        $html = $this->avisoPara($bruno->email);

        $this->assertStringNotContainsString('junto com os lançamentos, contas, metas', $html);
        $this->assertStringContainsString('o seu login, a sua foto de perfil e os seus dados pessoais foram apagados', $html);
        $this->assertStringContainsString('continua com <strong>Carla Titular</strong>', $html);

        // E é verdade: o titular continua lá.
        $this->assertModelExists($this->titular);
    }
}
