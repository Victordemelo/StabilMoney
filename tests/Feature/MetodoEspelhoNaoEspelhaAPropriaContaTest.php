<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Um cartão de débito ou Pix não pode ficar vinculado à PRÓPRIA conta (24/09/2026).
 *
 * O vínculo tem de apontar para uma corrente/poupança da família — e a conta que está sendo
 * editada ainda É corrente/poupança enquanto a validação roda. Uma corrente zerada "Nubank"
 * virando Pix com a chave no próprio "Nubank" passava, e o formulário até oferecia a opção
 * (a lista de vínculo trazia a própria conta).
 *
 * O estrago era da família inteira: o saldo de um método espelho é o da conta vinculada, e
 * passava a ser o dele mesmo — recursão infinita ("Maximum call stack size reached") no
 * `Account::paymentOptions`, que o modal "Lançar" chama no shell de TODA tela. Erro 500 em
 * qualquer página, sem volta pela interface: trocar o tipo de volta e excluir a conta são
 * recusados, porque a própria conta aparece como o método que depende dela.
 */
class MetodoEspelhoNaoEspelhaAPropriaContaTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private Account $corrente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);

        // Zerada e sem histórico: a trava de classe sozinha deixa virar débito/Pix.
        $this->corrente = Account::factory()->for($this->titular)->create([
            'name' => 'Nubank',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => 0,
        ]);
    }

    public function test_corrente_nao_vira_pix_com_a_chave_nela_mesma(): void
    {
        $this->actingAs($this->titular)
            ->from(route('accounts.index'))
            ->put(route('accounts.update', $this->corrente), [
                'name' => 'Nubank',
                'type' => 'pix',
                'bank' => 'nubank',
                'pix_account_id' => $this->corrente->id,
            ])
            ->assertSessionHasErrors('checking_account_id');

        $conta = $this->corrente->fresh();
        $this->assertSame('checking', $conta->type, 'A conta não podia ter virado Pix de si mesma.');
        $this->assertNull($conta->checking_account_id);

        // E o app continua abrindo: o modal "Lançar" (em toda tela) lê o saldo de cada método.
        $this->actingAs($this->titular)->get(route('dashboard'))->assertOk();
        $this->actingAs($this->titular)->get(route('accounts.index'))->assertOk();
    }

    public function test_poupanca_nao_vira_cartao_de_debito_vinculado_a_ela_mesma(): void
    {
        $poupanca = Account::factory()->for($this->titular)->create([
            'name' => 'Poupança Caixa',
            'type' => 'savings',
            'bank' => 'caixa',
            'initial_balance' => 0,
        ]);

        $this->actingAs($this->titular)
            ->put(route('accounts.update', $poupanca), [
                'name' => 'Poupança Caixa',
                'type' => 'debit_card',
                'bank' => 'caixa',
                'savings_account_id' => $poupanca->id,
            ])
            ->assertSessionHasErrors('savings_account_id');

        $this->assertSame('savings', $poupanca->fresh()->type);
    }

    /** O `exists` aceita "05" (o banco converte para 5), e seria gravado como o mesmo id. */
    public function test_id_da_propria_conta_escrito_de_outro_jeito_tambem_e_recusado(): void
    {
        foreach (['0'.$this->corrente->id, ' '.$this->corrente->id] as $mesmoId) {
            $this->actingAs($this->titular)
                ->put(route('accounts.update', $this->corrente), [
                    'name' => 'Nubank',
                    'type' => 'debit_card',
                    'bank' => 'nubank',
                    'checking_account_id' => $mesmoId,
                ])
                ->assertSessionHasErrors('checking_account_id');

            $this->assertSame('checking', $this->corrente->fresh()->type, "Passou com \"{$mesmoId}\".");
        }
    }

    /** Pelo modal (AJAX), a recusa vem no 422 que o formulário já sabe mostrar. */
    public function test_pelo_modal_a_recusa_vem_em_422(): void
    {
        $this->actingAs($this->titular)
            ->putJson(route('accounts.update', $this->corrente), [
                'name' => 'Nubank',
                'type' => 'debit_card',
                'bank' => 'nubank',
                'checking_account_id' => $this->corrente->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('checking_account_id');
    }

    /** A tela não oferece a própria conta como vínculo — oferece as outras. */
    public function test_o_formulario_de_edicao_nao_oferece_a_propria_conta_como_vinculo(): void
    {
        $outra = Account::factory()->for($this->titular)->create([
            'name' => 'Itaú', 'type' => 'checking', 'bank' => 'itau', 'initial_balance' => 0,
        ]);

        foreach ([route('accounts.edit', $this->corrente), route('accounts.index')] as $url) {
            $html = $this->actingAs($this->titular)->get($url)->assertOk()->getContent();

            foreach (['checking_account_id', 'pix_account_id'] as $campo) {
                $id = $campo.'-c'.$this->corrente->id;
                $this->assertMatchesRegularExpression('/<select[^>]*id="'.$id.'"[^>]*>(.*?)<\/select>/s', $html, "Sem o select {$id} em {$url}.");
                preg_match('/<select[^>]*id="'.$id.'"[^>]*>(.*?)<\/select>/s', $html, $select);

                $this->assertStringNotContainsString('value="'.$this->corrente->id.'"', $select[1], "{$url}: {$id} oferece a própria conta.");
                $this->assertStringContainsString('value="'.$outra->id.'"', $select[1], "{$url}: {$id} deixou de oferecer as outras contas.");
            }
        }
    }

    /** Vincular a OUTRA conta continua como sempre. */
    public function test_vincular_a_outra_conta_continua_passando(): void
    {
        $outra = Account::factory()->for($this->titular)->create([
            'name' => 'Itaú', 'type' => 'checking', 'bank' => 'itau', 'initial_balance' => 0,
        ]);

        $this->actingAs($this->titular)
            ->put(route('accounts.update', $this->corrente), [
                'name' => 'Pix do CPF',
                'type' => 'pix',
                'bank' => 'nubank',
                'pix_account_id' => $outra->id,
            ])
            ->assertSessionHasNoErrors();

        $conta = $this->corrente->fresh();
        $this->assertSame('pix', $conta->type);
        $this->assertSame($outra->id, $conta->checking_account_id);
        $this->actingAs($this->titular)->get(route('dashboard'))->assertOk();
    }
}
