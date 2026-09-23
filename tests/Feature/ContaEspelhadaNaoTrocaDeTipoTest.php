<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A-4 da auditoria de 05/09/2026: uma conta que um cartão de débito ou um Pix
 * espelha não pode trocar de tipo — nem zerada.
 *
 * `Account::travaDeClasse` só olhava o histórico da PRÓPRIA conta. Uma corrente
 * sem saldo e sem lançamento nenhum, mas vinculada a um cartão de débito, passava
 * pela trava e podia virar cartão de crédito. Como o débito manda o id da conta
 * vinculada em todo lançamento (`Account::paymentOptions`), dali em diante a compra
 * "no débito" caía NUM CARTÃO: virava fatura, consumia limite e não saía do caixa.
 * Na variante corrente → poupança, o débito ficava com `checking_account_id`
 * apontando para uma poupança.
 *
 * A troca agora é recusada com a mensagem dizendo QUAL método depende da conta; a
 * saída (vincular o método a outra conta) destrava de verdade.
 */
class ContaEspelhadaNaoTrocaDeTipoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $corrente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Zerada e sem histórico: a `travaDeClasse` sozinha deixaria trocar.
        $this->corrente = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Conta Principal',
            'bank' => 'itau',
            'initial_balance' => 0,
        ]);
        $this->assertFalse($this->corrente->hasMoneyHistory());
    }

    private function virarCartaoDeCredito(Account $conta, bool $json = false): TestResponse
    {
        $dados = [
            'name' => $conta->name,
            'type' => 'credit_card',
            'bank' => 'itau',
            'credit_limit' => '5.000,00',
            'closing_day' => 10,
            'due_day' => 20,
        ];

        $cliente = $this->actingAs($this->user)->from(route('accounts.index'));

        return $json
            ? $cliente->putJson(route('accounts.update', $conta), $dados)
            : $cliente->put(route('accounts.update', $conta), $dados);
    }

    /** O cenário do relatório: o débito passaria a lançar num cartão de crédito. */
    public function test_corrente_vinculada_a_um_cartao_de_debito_nao_vira_cartao_de_credito(): void
    {
        Account::factory()->for($this->user)->debitCard($this->corrente->id)->create(['name' => 'Débito Itaú']);

        $this->virarCartaoDeCredito($this->corrente)->assertSessionHasErrors('type');

        $this->assertSame('checking', $this->corrente->fresh()->type, 'O tipo não podia ter mudado.');

        $erro = session('errors')->first('type');
        $this->assertStringContainsString('Débito Itaú', $erro, 'A mensagem tem de dizer qual método depende da conta.');
        $this->assertStringContainsString('viraria fatura', $erro);

        // E o débito continua mandando o dinheiro para a CORRENTE, não para um cartão.
        $opcao = Account::paymentOptions($this->user->id)->firstWhere('name', 'Débito Itaú → Conta Principal');
        $this->assertNotNull($opcao);
        $this->assertSame($this->corrente->id, $opcao->id);
        $this->assertFalse($opcao->isCard);
    }

    /** A variante do relatório: corrente → poupança deixaria o Pix apontando para uma poupança. */
    public function test_corrente_vinculada_a_um_pix_nao_vira_poupanca(): void
    {
        Account::factory()->for($this->user)->pix($this->corrente->id)->create(['name' => 'Pix do CPF']);

        $this->actingAs($this->user)
            ->put(route('accounts.update', $this->corrente), [
                'name' => $this->corrente->name,
                'type' => 'savings',
                'bank' => 'itau',
                'initial_balance' => '0,00',
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame('checking', $this->corrente->fresh()->type);
        $this->assertStringContainsString('Pix do CPF', session('errors')->first('type'));
    }

    /** Com dois métodos, os dois aparecem — e a frase vai para o plural. */
    public function test_a_mensagem_cita_todos_os_metodos_que_dependem_da_conta(): void
    {
        Account::factory()->for($this->user)->debitCard($this->corrente->id)->create(['name' => 'Débito Itaú']);
        Account::factory()->for($this->user)->pix($this->corrente->id)->create(['name' => 'Pix do CPF']);

        $this->virarCartaoDeCredito($this->corrente)->assertSessionHasErrors('type');

        $erro = session('errors')->first('type');
        $this->assertStringContainsString('o Cartão de Débito "Débito Itaú" e o Pix "Pix do CPF" tiram dinheiro desta conta', $erro);
        $this->assertStringContainsString('Vincule esses métodos a outra conta', $erro);
    }

    /** Poupança espelhada por um débito também trava (o vínculo pela coluna da poupança). */
    public function test_poupanca_vinculada_a_um_cartao_de_debito_tambem_nao_troca_de_tipo(): void
    {
        $poupanca = Account::factory()->for($this->user)->create([
            'type' => 'savings', 'name' => 'Poupança', 'bank' => 'itau', 'initial_balance' => 0,
        ]);
        Account::factory()->for($this->user)->create([
            'type' => 'debit_card', 'name' => 'Débito da Poupança', 'initial_balance' => null,
            'savings_account_id' => $poupanca->id,
        ]);

        $this->actingAs($this->user)
            ->put(route('accounts.update', $poupanca), [
                'name' => 'Poupança',
                'type' => 'checking',
                'bank' => 'itau',
                'initial_balance' => '0,00',
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame('savings', $poupanca->fresh()->type);
    }

    /** Pelo modal (JSON) a recusa chega no mesmo 422 dos demais erros do Form Request. */
    public function test_pelo_modal_a_recusa_vem_em_422_com_o_nome_do_metodo(): void
    {
        Account::factory()->for($this->user)->debitCard($this->corrente->id)->create(['name' => 'Débito Itaú']);

        $resposta = $this->virarCartaoDeCredito($this->corrente, json: true)
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->assertStringContainsString('Débito Itaú', (string) $resposta->json('errors.type.0'));
        $this->assertSame('checking', $this->corrente->fresh()->type);
    }

    /** A saída que a mensagem aponta funciona: vinculado a outra conta, a troca passa. */
    public function test_depois_de_vincular_o_metodo_a_outra_conta_a_troca_passa(): void
    {
        $debito = Account::factory()->for($this->user)->debitCard($this->corrente->id)->create(['name' => 'Débito Itaú']);
        $outra = Account::factory()->for($this->user)->create(['type' => 'checking', 'name' => 'Outra', 'bank' => 'itau']);

        $this->virarCartaoDeCredito($this->corrente)->assertSessionHasErrors('type');

        // Vincula o débito à outra conta pelo próprio cadastro do débito.
        $this->actingAs($this->user)
            ->put(route('accounts.update', $debito), [
                'name' => 'Débito Itaú',
                'type' => 'debit_card',
                'bank' => 'itau',
                'checking_account_id' => $outra->id,
            ])
            ->assertSessionHasNoErrors();

        $this->virarCartaoDeCredito($this->corrente)->assertSessionHasNoErrors();
        $this->assertSame('credit_card', $this->corrente->fresh()->type);
    }

    /** Conta sem ninguém vinculado segue livre (não travar demais). */
    public function test_conta_sem_metodo_vinculado_continua_trocando_de_tipo(): void
    {
        $this->virarCartaoDeCredito($this->corrente)->assertSessionHasNoErrors();

        $this->assertSame('credit_card', $this->corrente->fresh()->type);
    }

    /** Mandar o MESMO tipo (editar só o nome) nunca esbarra na trava. */
    public function test_editar_outros_campos_de_conta_espelhada_continua_livre(): void
    {
        Account::factory()->for($this->user)->debitCard($this->corrente->id)->create(['name' => 'Débito Itaú']);

        $this->actingAs($this->user)
            ->put(route('accounts.update', $this->corrente), [
                'name' => 'Conta Principal (salário)',
                'type' => 'checking',
                'bank' => 'itau',
                'initial_balance' => '0,00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Conta Principal (salário)', $this->corrente->fresh()->name);
    }

    /** A tela já trava o select — no modal da lista e na página cheia — e diz por quê. */
    public function test_a_tela_trava_o_tipo_e_diz_qual_metodo_depende_da_conta(): void
    {
        Account::factory()->for($this->user)->debitCard($this->corrente->id)->create(['name' => 'Débito Itaú']);
        $livre = Account::factory()->for($this->user)->create(['type' => 'checking', 'name' => 'Livre', 'initial_balance' => 0]);

        $lista = $this->actingAs($this->user)->get(route('accounts.index'))->assertOk();
        $lista->assertSee('id="type-c'.$this->corrente->id.'" name="_type_travado"', false);
        $lista->assertSee('o Cartão de Débito &quot;Débito Itaú&quot; tira dinheiro desta conta', false);
        // A conta sem vínculo continua com o select de verdade.
        $lista->assertSee('id="type-c'.$livre->id.'" name="type"', false);

        $this->actingAs($this->user)->get(route('accounts.edit', $this->corrente))
            ->assertOk()
            ->assertSee('data-type-locked', false)
            ->assertSee('data-type-locked-by-mirror', false);
    }
}
