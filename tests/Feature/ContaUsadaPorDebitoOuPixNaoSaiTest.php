<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Conta de onde um cartão de débito ou um Pix tira o dinheiro não pode ser excluída (achado
 * da rodada de 22-23/09/2026, a mesma família do A-4).
 *
 * O defeito: a FK `checking_account_id`/`savings_account_id` é `nullOnDelete`. Excluir a
 * corrente deixava o débito (ou o Pix) sem conta nenhuma — e o método sumia EM SILÊNCIO do
 * select de pagamento, porque `paymentOptions()` pula quem não espelha nada. Com a outra
 * conta vinculada, o débito passava a sacar dela sem ninguém ter pedido.
 *
 * O comportamento certo: a exclusão é recusada com uma mensagem que NOMEIA o método e diz o
 * que fazer (editar o método para usar outra conta, ou excluí-lo). Nada muda no banco.
 */
class ContaUsadaPorDebitoOuPixNaoSaiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function conta(string $tipo = 'checking', string $nome = 'Corrente Itaú'): Account
    {
        return Account::factory()->for($this->user)->create([
            'type' => $tipo,
            'name' => $nome,
            'initial_balance' => 0,
        ]);
    }

    private function excluir(Account $conta): TestResponse
    {
        return $this->actingAs($this->user)
            ->from(route('accounts.index'))
            ->delete(route('accounts.destroy', $conta));
    }

    public function test_corrente_usada_por_um_cartao_de_debito_nao_sai(): void
    {
        $corrente = $this->conta();
        $debito = Account::factory()->for($this->user)->debitCard($corrente->id)->create(['name' => 'Débito Itaú']);

        $this->excluir($corrente)
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHasErrors('account');

        $erro = (string) session('errors')->first('account');
        $this->assertStringContainsString('Débito Itaú', $erro);
        $this->assertStringContainsString('Edite o método', $erro);

        $this->assertModelExists($corrente);
        $this->assertSame($corrente->id, $debito->fresh()->checking_account_id, 'O débito perdeu a conta.');
    }

    public function test_poupanca_usada_por_um_pix_nao_sai(): void
    {
        $poupanca = $this->conta('savings', 'Poupança Nubank');
        Account::factory()->for($this->user)->pix($poupanca->id, 'savings')->create(['name' => 'Pix do celular']);

        $this->excluir($poupanca)->assertSessionHasErrors('account');

        $this->assertStringContainsString('Pix do celular', (string) session('errors')->first('account'));
        $this->assertModelExists($poupanca);
    }

    /**
     * Débito que tira da corrente E da poupança: sem a poupança ele continuaria funcionando,
     * mas passaria a sacar SÓ da corrente — mudança de comportamento que ninguém pediu. A
     * regra é a mesma da troca de tipo: qualquer vínculo segura a conta.
     */
    public function test_conta_que_e_uma_das_duas_do_debito_tambem_nao_sai(): void
    {
        $corrente = $this->conta();
        $poupanca = $this->conta('savings', 'Poupança Itaú');
        $debito = Account::factory()->for($this->user)->debitCard($corrente->id)->create([
            'name' => 'Débito Itaú',
            'savings_account_id' => $poupanca->id,
        ]);

        $this->excluir($poupanca)->assertSessionHasErrors('account');

        $this->assertModelExists($poupanca);
        $this->assertSame($poupanca->id, $debito->fresh()->savings_account_id);
    }

    /** Dois métodos na mesma conta: a mensagem cita os dois. */
    public function test_a_mensagem_nomeia_todos_os_metodos_que_dependem_da_conta(): void
    {
        $corrente = $this->conta();
        Account::factory()->for($this->user)->debitCard($corrente->id)->create(['name' => 'Débito Itaú']);
        Account::factory()->for($this->user)->pix($corrente->id)->create(['name' => 'Pix Itaú']);

        $this->excluir($corrente)->assertSessionHasErrors('account');

        $erro = (string) session('errors')->first('account');
        $this->assertStringContainsString('Débito Itaú', $erro);
        $this->assertStringContainsString('Pix Itaú', $erro);
        $this->assertStringContainsString('cada método', $erro);
    }

    /** Desvinculado o método, a conta sai como sempre saiu. */
    public function test_sem_metodo_vinculado_a_conta_continua_saindo(): void
    {
        $corrente = $this->conta();
        $outra = $this->conta('checking', 'Corrente Nubank');
        $debito = Account::factory()->for($this->user)->debitCard($corrente->id)->create(['name' => 'Débito Itaú']);

        $debito->forceFill(['checking_account_id' => $outra->id])->save();

        $this->excluir($corrente)->assertSessionHasNoErrors();

        $this->assertModelMissing($corrente);
        $this->assertSame($outra->id, $debito->fresh()->checking_account_id);
    }
}
