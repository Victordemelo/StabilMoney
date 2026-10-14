<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Atividade;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A compra parcelada na tela de editar (13/10/2026 — pedido do Victor: "não consigo excluir ela" e
 * "fala que o excluir vai excluir todas as parcelas"). Antes nada se editava nem se excluía a partir
 * de uma parcela ("não pode ser editada sozinha — use Contas a pagar"). Agora a compra é uma coisa
 * só: descrição, categoria e quem fez valem para as N parcelas; excluir leva a compra inteira (menos
 * as parcelas pagas por um pagamento de fatura do app); valor, data e cartão não mudam.
 */
class CompraParceladaNaEdicaoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $corrente;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new \DateTimeImmutable('2026-10-13 10:00:00'));
        $this->user = User::factory()->create();
        $this->corrente = Account::factory()->for($this->user)->create(['type' => 'checking', 'name' => 'Itaú', 'bank' => 'itau', 'initial_balance' => 5000]);
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create([
            'name' => 'Roxinho', 'bank' => 'nubank', 'closing_day' => 2, 'due_day' => 9, 'credit_limit' => 5000,
        ]);
    }

    private function parcelar(string $data = '2026-10-05', int $n = 4): Transaction
    {
        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense', 'account_id' => $this->cartao->id, 'description' => 'Geladeira',
            'amount' => '1.200,00', 'date' => $data, 'installments' => $n,
        ])->assertSessionHasNoErrors();

        return Transaction::where('installment_no', 1)->firstOrFail();
    }

    private function editar(Transaction $parcela, array $campos)
    {
        return $this->actingAs($this->user)->from(route('transactions.edit', $parcela))
            ->put(route('transactions.update', $parcela), $campos + [
                'type' => 'expense', 'amount' => number_format((float) $parcela->amount, 2, ',', '.'),
                'account_id' => $parcela->account_id, 'date' => $parcela->date->toDateString(),
            ]);
    }

    public function test_descricao_categoria_e_autor_valem_para_todas_as_parcelas(): void
    {
        $primeira = $this->parcelar();
        $categoria = Category::factory()->expense()->for($this->user)->create(['name' => 'Casa']);

        $this->editar($primeira, ['description' => 'Geladeira Brastemp', 'category_id' => $categoria->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Compra atualizada nas 4 parcelas.');

        $parcelas = Transaction::orderBy('installment_no')->get();
        $this->assertSame(array_fill(0, 4, 'Geladeira Brastemp'), $parcelas->pluck('description')->all());
        $this->assertSame(array_fill(0, 4, $categoria->id), $parcelas->pluck('category_id')->all());
        $this->assertSame(1200.0, $this->cartao->fresh()->committed, 'Nada de dinheiro mudou.');
    }

    public function test_valor_data_e_cartao_nao_mudam_por_aqui(): void
    {
        $primeira = $this->parcelar();

        foreach ([['amount' => '999,00'], ['date' => '2026-10-06'], ['account_id' => $this->corrente->id]] as $mudanca) {
            $this->editar($primeira, $mudanca)->assertSessionHasErrors('transaction');
        }

        $this->assertSame(1200.0, round(Transaction::sum('amount'), 2));
        $this->assertSame(4, Transaction::where('account_id', $this->cartao->id)->count());
    }

    public function test_excluir_qualquer_parcela_leva_a_compra_inteira(): void
    {
        $this->parcelar();
        $terceira = Transaction::where('installment_no', 3)->firstOrFail();

        $this->actingAs($this->user)->delete(route('transactions.destroy', $terceira))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Compra excluída: as 4 parcelas saíram da fatura.');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0.0, $this->cartao->fresh()->committed);
        $this->assertSame(1, Atividade::where('acao', 'compra.excluida')->count(), 'Uma ação, uma linha.');
    }

    public function test_as_parcelas_pagas_numa_fatura_do_app_ficam(): void
    {
        $this->parcelar('2026-09-20');
        // Paga a fatura fechada (a 1ª parcela, de 20/09) pelo app.
        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->corrente->id, 'ciclo' => 'fechado', 'paid_on' => '2026-10-13',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Transaction::where('account_id', $this->cartao->id)->whereNotNull('paid_at')->count());

        $this->actingAs($this->user)->delete(route('transactions.destroy', Transaction::where('installment_no', 1)->firstOrFail()))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Compra excluída: 3 parcelas saíram da fatura. A parcela que já foi paga continua no histórico.');

        $this->assertSame(1, Transaction::where('account_id', $this->cartao->id)->count());
    }

    public function test_compra_toda_paga_numa_fatura_do_app_nao_sai(): void
    {
        $this->parcelar('2026-09-20', 2);
        Transaction::where('account_id', $this->cartao->id)->update(['paid_at' => now()]);

        $this->actingAs($this->user)->from(route('transactions.index'))
            ->delete(route('transactions.destroy', Transaction::firstOrFail()))
            ->assertSessionHasErrors('transaction');
        $this->assertSame(2, Transaction::count());
    }

    public function test_a_tela_de_editar_diz_o_que_sai_e_o_que_nao_muda(): void
    {
        $this->parcelar();
        $segunda = Transaction::where('installment_no', 2)->firstOrFail();

        $html = $this->actingAs($this->user)->get(route('transactions.edit', $segunda))->assertOk()->getContent();

        // Sem o seletor Receita/Despesa: o tipo vai num hidden.
        $this->assertStringNotContainsString('id="tt-income"', $html);
        $this->assertStringContainsString('<input type="hidden" name="type" value="expense">', $html);
        // Valor, data e cartão como fatos; os campos que valem para as 4 parcelas.
        $this->assertStringContainsString('class="tx-ed-fatos"', $html);
        $this->assertStringContainsString('4x de R$ 300,00', $html);
        $this->assertStringNotContainsString('id="amount"', $html);
        $this->assertStringContainsString('id="description"', $html);
        $this->assertStringContainsString('Salvar a compra', $html);
        // A exclusão diz que todas as parcelas saem e que é preciso lançar de novo.
        $this->assertStringContainsString('Excluir a compra inteira', $html);
        $this->assertStringContainsString('As 4 parcelas saem da fatura do cartão de uma vez', $html);
        $this->assertStringContainsString('data-confirmar="Excluir a compra inteira? As 4 parcelas saem da fatura. Para corrigir, você vai precisar lançar a compra de novo.', $html);
    }

    public function test_numa_despesa_o_onde_nao_tem_conta_de_receita_e_a_categoria_e_so_de_despesa(): void
    {
        Category::factory()->income()->for($this->user)->create(['name' => 'Salário extra']);
        Category::factory()->expense()->for($this->user)->create(['name' => 'Mercado']);
        Account::factory()->for($this->user)->create(['type' => 'pix', 'name' => 'Pix Itaú', 'bank' => 'itau', 'checking_account_id' => $this->corrente->id, 'initial_balance' => null]);
        $despesa = Transaction::factory()->for($this->user)->expense()->create([
            'account_id' => $this->corrente->id, 'amount' => 30, 'date' => '2026-10-13',
        ]);

        $html = $this->actingAs($this->user)->get(route('transactions.edit', $despesa))->assertOk()->getContent();
        $inicio = strpos($html, 'id="account_id"');
        $select = substr($html, $inicio, strpos($html, '</select>', $inicio) - $inicio);

        $this->assertStringNotContainsString('Contas correntes', $select);
        $this->assertStringContainsString('Pix Itaú', $select);
        $this->assertStringContainsString('Por onde saiu', $html);
        $inicio = strpos($html, 'id="category_id"');
        $categorias = substr($html, $inicio, strpos($html, '</select>', $inicio) - $inicio);
        $this->assertStringContainsString('Mercado', $categorias);
        $this->assertStringNotContainsString('Salário extra', $categorias);
    }

    /**
     * A tela de editar nasce TRAVADA (13/10/2026 — pedido do Victor): o "Editar movimentação" do
     * lado libera os campos (`sm/edicao-travada.js`). O servidor manda o formulário e o cartão do
     * botão; com erro de validação, o formulário já vem liberado. E cada parcela diz a fatura dela.
     */
    public function test_a_edicao_nasce_travada_e_as_parcelas_dizem_a_fatura(): void
    {
        $primeira = $this->parcelar('2026-10-05', 3);

        $html = $this->actingAs($this->user)->get(route('transactions.edit', $primeira))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<form [^>]*data-tx-form\s+data-tx-travavel\s*>#', $html);
        $this->assertStringContainsString('<section class="card tx-lado-card tx-editar" data-tx-editar hidden>', $html);
        $this->assertStringContainsString('data-tx-liberar>', $html);
        $this->assertLessThan(strpos($html, 'tx-excluir"'), strpos($html, 'data-tx-editar'), 'O Editar vem em cima do Excluir.');
        $this->assertStringContainsString('<div class="form-actions" data-tx-acoes>', $html);

        // Fecha dia 2, vence dia 9: compra em 05/10 cai na fatura de novembro; as outras, dez e jan.
        $this->assertStringContainsString('<span>Parcela</span><span>Fatura</span>', $html);
        foreach (['nov/26', 'dez/26', 'jan/27'] as $mes) {
            $this->assertMatchesRegularExpression('#title="Compra em [^"]+">'.preg_quote($mes, '#').'</span>#i', $html);
        }

        // Com erro de validação, abre liberada.
        $this->editar($primeira, ['amount' => '1,00']);
        $comErro = $this->actingAs($this->user)->get(route('transactions.edit', $primeira))->getContent();
        $this->assertMatchesRegularExpression('#data-tx-travavel\s+data-tx-liberado#', $comErro);
    }
}
