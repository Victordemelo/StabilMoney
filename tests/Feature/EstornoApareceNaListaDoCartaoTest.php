<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Estorno na lista de itens do cartão (item 🔵 da auditoria de 02/09/2026).
 *
 * A receita lançada num cartão é um ESTORNO: abate a fatura, devolve limite e
 * rola de ciclo. Mas `FaturaService::cycleItems` filtrava `type=expense`, então
 * a fatura "encolhia" sem nenhuma linha explicando por quê — e o estorno não
 * tinha onde ser visto nem excluído.
 *
 * Cartão fecha dia 10 / vence dia 20; hoje 05/08 → ciclo aberto (10/07, 10/08].
 */
class EstornoApareceNaListaDoCartaoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-05');

        $this->user = User::factory()->create();
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create([
            'name' => 'Nubank', 'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function card()
    {
        return app(FaturaService::class)->build($this->user->id)['cards']->first();
    }

    public function test_compra_e_estorno_aparecem_na_lista_e_o_total_e_o_liquido(): void
    {
        Transaction::factory()->for($this->user)->for($this->cartao)->expense()->create([
            'amount' => 1000, 'date' => '2026-08-01', 'description' => 'Notebook',
        ]);
        $estorno = Transaction::factory()->for($this->user)->for($this->cartao)->income()->create([
            'amount' => 400, 'date' => '2026-08-03', 'description' => 'Devolução parcial',
        ]);

        $card = $this->card();
        $this->assertCount(2, $card['items'], 'O estorno precisa estar na lista do ciclo em que caiu.');
        $this->assertTrue($card['items']->contains('id', $estorno->id));
        $this->assertSame(600.0, round((float) $card['currentInvoice'], 2), 'Total do ciclo = compra − estorno.');
        $this->assertSame(600.0, round((float) $card['invoiceDue'], 2));

        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Estorno', $html);
        $this->assertStringContainsString('−R$ 400,00', $html, 'Valor do estorno sai com sinal negativo (traço U+2212 antes do símbolo).');
        $this->assertStringContainsString('Devolução parcial', $html);
    }

    public function test_estorno_de_outro_ciclo_nao_entra_na_lista_do_aberto(): void
    {
        Transaction::factory()->for($this->user)->for($this->cartao)->expense()->create([
            'amount' => 1000, 'date' => '2026-08-01', 'description' => 'Notebook',
        ]);
        // Ciclo fechado (10/06, 10/07].
        Transaction::factory()->for($this->user)->for($this->cartao)->income()->create([
            'amount' => 400, 'date' => '2026-07-05', 'description' => 'Estorno antigo',
        ]);

        $card = $this->card();
        $this->assertCount(1, $card['items']);
        $this->assertSame('Notebook', $card['items']->first()->description);
    }

    public function test_excluir_o_estorno_pela_lista_devolve_a_divida_inteira(): void
    {
        Transaction::factory()->for($this->user)->for($this->cartao)->expense()->create([
            'amount' => 1000, 'date' => '2026-08-01', 'description' => 'Notebook',
        ]);
        $estorno = Transaction::factory()->for($this->user)->for($this->cartao)->income()->create([
            'amount' => 400, 'date' => '2026-08-03', 'description' => 'Devolução parcial',
        ]);

        $this->assertSame(600.0, round((float) Account::find($this->cartao->id)->committed, 2));

        $this->actingAs($this->user)->delete(route('faturas.compra.destroy', $estorno))
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('transactions', ['id' => $estorno->id]);
        $this->assertSame(1000.0, round((float) Account::find($this->cartao->id)->committed, 2));
        $this->assertCount(1, $this->card()['items']);
    }
}
