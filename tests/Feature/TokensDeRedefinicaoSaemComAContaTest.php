<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Os pedidos de "esqueci a senha" saem junto com a conta (achado L-2 da auditoria de
 * 07/09/2026).
 *
 * O defeito: `password_reset_tokens` é chaveada pelo E-MAIL, sem FK para `users`, então nenhum
 * cascade a alcança — e ninguém a limpava no delete. A linha (e-mail + token com hash + quando)
 * sobrevivia à conta que a Política de Privacidade promete apagar. E não é só papel: o token
 * continua válido pelo prazo inteiro, e quem o tem (quem recebeu o link) redefine a senha de
 * uma conta NOVA criada com o mesmo e-mail nesse intervalo — o dependente removido e cadastrado
 * de novo pelo titular, por exemplo.
 *
 * O comportamento certo: toda exclusão apaga os tokens, na MESMA transação — a do titular, a
 * de cada dependente que vai junto, a do dependente removido pelo titular e a do painel. Quem
 * faz é o hook `deleting` do User, por onde as quatro passam.
 */
class TokensDeRedefinicaoSaemComAContaTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-1234';

    private User $titular;

    private User $dependente;

    private User $outraFamilia;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->titular = User::factory()->create([
            'email' => 'titular@familia.test',
            'password' => Hash::make(self::SENHA),
            'is_admin' => true,
        ]);
        $this->dependente = User::factory()->create([
            'email' => 'dependente@familia.test',
            'account_owner_id' => $this->titular->id,
            'is_admin' => false,
        ]);
        $this->outraFamilia = User::factory()->create(['email' => 'vizinho@outra.test']);

        // Um pedido de "esqueci a senha" em aberto para cada um.
        foreach ([$this->titular, $this->dependente, $this->outraFamilia] as $pessoa) {
            Password::broker()->createToken($pessoa);
        }
    }

    private function assertTemToken(string $email): void
    {
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $email]);
    }

    private function assertSemToken(string $email): void
    {
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email]);
    }

    public function test_excluir_o_titular_apaga_os_tokens_dele_e_dos_dependentes(): void
    {
        $this->actingAs($this->titular)
            ->delete(route('profile.destroy'), ['password' => self::SENHA, 'confirmo_dependentes' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertSemToken('titular@familia.test');
        $this->assertSemToken('dependente@familia.test');

        // O de outra família não é da conta de ninguém que saiu.
        $this->assertTemToken('vizinho@outra.test');
    }

    public function test_remover_o_dependente_apaga_so_o_token_dele(): void
    {
        $this->actingAs($this->titular)
            ->delete(route('dependentes.destroy', $this->dependente))
            ->assertRedirect(route('dependentes'));

        $this->assertSemToken('dependente@familia.test');
        $this->assertTemToken('titular@familia.test');
    }

    public function test_a_exclusao_pelo_painel_tambem_apaga_os_tokens(): void
    {
        config(['admin.enabled' => true]);
        $admin = Admin::factory()->comDoisFatores()->create();

        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_ok' => true])
            ->delete(route('painel.excluir', $this->titular->id), ['confirmacao' => 'titular@familia.test'])
            ->assertRedirect(route('painel.pessoas'));

        $this->assertModelMissing($this->titular);
        $this->assertSemToken('titular@familia.test');
        $this->assertSemToken('dependente@familia.test');
    }

    /** Na MESMA transação: se a exclusão é desfeita, os tokens voltam com a conta. */
    public function test_exclusao_desfeita_devolve_os_tokens(): void
    {
        try {
            DB::transaction(function () {
                $this->titular->delete();

                throw new \RuntimeException('Desfeito depois do delete.');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertModelExists($this->titular);
        $this->assertTemToken('titular@familia.test');
        $this->assertTemToken('dependente@familia.test');
    }

    /**
     * A consequência concreta: o titular remove o dependente e o cadastra de novo com o mesmo
     * e-mail. O link de "esqueci a senha" pedido ANTES da remoção não pode valer para a conta
     * nova — ela nasceu depois, com outra senha, e o pedido era de uma conta que não existe mais.
     */
    public function test_o_link_pedido_antes_da_exclusao_nao_vale_para_uma_conta_nova_com_o_mesmo_email(): void
    {
        $tokenAntigo = Password::broker()->createToken($this->dependente);

        $this->actingAs($this->titular)->delete(route('dependentes.destroy', $this->dependente));

        $this->actingAs($this->titular)->post(route('dependentes.store'), [
            'name' => 'Dependente de Novo',
            'email' => 'dependente@familia.test',
            'password' => 'senha-que-o-titular-definiu',
        ])->assertSessionHasNoErrors();

        $this->app['auth']->forgetGuards();

        $this->post('/reset-password', [
            'token' => $tokenAntigo,
            'email' => 'dependente@familia.test',
            'password' => 'senha-de-quem-tinha-o-link',
            'password_confirmation' => 'senha-de-quem-tinha-o-link',
        ])->assertSessionHasErrors('email');

        $novo = User::where('email', 'dependente@familia.test')->firstOrFail();
        $this->assertTrue(Hash::check('senha-que-o-titular-definiu', $novo->password), 'O link antigo trocou a senha da conta nova.');
    }
}
