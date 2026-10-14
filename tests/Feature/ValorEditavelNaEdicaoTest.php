<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O VALOR de uma movimentação já paga ou recebida muda na edição, e o saldo acompanha
 * (14/10/2026 — o Victor reverteu a trava de out/2026: "no edit deve dar para alterar o valor,
 * aí ele altera o saldo total"). O valor novo passa pela reconciliação de sempre: o piso do
 * cheque especial, a escolha de fonte, o resgate ligado.
 *
 * O que continua preso:
 * - o TIPO de uma movimentação que já aconteceu (receita ↔ despesa: excluir e lançar de novo);
 * - o valor da compra de CARTÃO já paga numa fatura — a fatura paga ficaria diferente do que
 *   saiu da conta (`TransactionController::valorTravado`): estornar o pagamento antes.
 *
 * "Já paga/recebida" é `Transaction::jaFoiPagaOuRecebida()`: compra de cartão de crédito quando
 * tem `paid_at`; qualquer outra movimentação quando a data dela já chegou.
 */
class ValorEditavelNaEdicaoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new \DateTimeImmutable('2026-08-05 10:00:00'));

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
    }

    private function lancamento(string $tipo, float $valor, string $data, ?Account $conta = null): Transaction
    {
        return Transaction::factory()->for($this->user)->{$tipo === 'income' ? 'income' : 'expense'}()->create([
            'account_id' => ($conta ?? $this->conta)->id,
            'amount' => $valor,
            'date' => $data,
            'description' => 'Original',
            'category_id' => null,
            'made_by_user_id' => $this->user->id,
        ]);
    }

    /** O payload que o formulário mandaria sem mudar nada, com `$mudancas` por cima. */
    private function editar(Transaction $t, array $mudancas = []): TestResponse
    {
        return $this->actingAs($this->user)->from(route('transactions.edit', $t))
            ->put(route('transactions.update', $t), $mudancas + [
                'type' => $t->type,
                'amount' => number_format((float) $t->amount, 2, ',', '.'),
                'account_id' => $t->account_id,
                'date' => $t->date->toDateString(),
                'description' => $t->description,
                'made_by_user_id' => $t->made_by_user_id,
            ]);
    }

    private function erroDoValor(TestResponse $resposta): string
    {
        $resposta->assertSessionHasErrors('amount');

        return (string) session('errors')->first('amount');
    }

    // ============================================================ (a) despesa de hoje

    public function test_despesa_de_hoje_muda_de_valor_e_o_saldo_acompanha(): void
    {
        $despesa = $this->lancamento('expense', 14.38, '2026-08-05');

        $this->editar($despesa, ['amount' => '14,29', 'description' => 'Jogo'])
            ->assertSessionHasNoErrors()->assertRedirect(route('transactions.index'));

        $atual = $despesa->fresh();
        $this->assertSame('14.29', (string) $atual->amount);
        $this->assertSame('Jogo', $atual->description);
        $this->assertSame(985.71, $this->conta->fresh()->available);
    }

    public function test_despesa_de_hoje_maior_respeita_o_piso_da_conta(): void
    {
        // Sem cheque especial: a despesa não pode passar do que a conta tem.
        $despesa = $this->lancamento('expense', 100, '2026-08-05');

        $this->editar($despesa, ['amount' => '1.500,00'])->assertSessionHasErrors();

        $this->assertSame('100.00', (string) $despesa->fresh()->amount);
        $this->assertSame(900.0, $this->conta->fresh()->available);
    }

    public function test_despesa_de_hoje_nao_muda_de_tipo(): void
    {
        $despesa = $this->lancamento('expense', 100, '2026-08-05');

        $erro = $this->erroDoValor($this->editar($despesa, ['type' => 'income']));

        $this->assertStringContainsString('o tipo não muda mais', $erro);
        $this->assertSame('expense', $despesa->fresh()->type);
        $this->assertSame(900.0, $this->conta->fresh()->available);
    }

    public function test_despesa_de_hoje_muda_descricao_categoria_e_data(): void
    {
        $despesa = $this->lancamento('expense', 100, '2026-08-05');
        $categoria = Category::factory()->for($this->user)->expense()->create();

        $this->editar($despesa, [
            'description' => 'Mercado do mês',
            'category_id' => $categoria->id,
            'date' => '2026-08-03',
        ])->assertSessionHasNoErrors()->assertRedirect(route('transactions.index'));

        $atual = $despesa->fresh();
        $this->assertSame('Mercado do mês', $atual->description);
        $this->assertSame($categoria->id, $atual->category_id);
        $this->assertSame('2026-08-03', $atual->date->toDateString());
        $this->assertSame('100.00', (string) $atual->amount);
        $this->assertSame(900.0, $this->conta->fresh()->available);
    }

    /** O valor reescrito com a mesma quantia (outra grafia) não é mudança. */
    public function test_mesmo_valor_com_outra_grafia_nao_e_mudanca(): void
    {
        $despesa = $this->lancamento('expense', 1234.5, '2026-08-05');

        $this->editar($despesa, ['amount' => '1234,50', 'description' => 'Reescrita'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Reescrita', $despesa->fresh()->description);
        $this->assertSame('1234.50', (string) $despesa->fresh()->amount);
    }

    // ============================================================ (b) receita de ontem

    public function test_receita_de_ontem_muda_de_valor_mas_nao_de_tipo(): void
    {
        $receita = $this->lancamento('income', 500, '2026-08-04');

        $this->editar($receita, ['amount' => '450,00'])->assertSessionHasNoErrors();
        $this->assertSame('450.00', (string) $receita->fresh()->amount);
        $this->assertSame(1450.0, $this->conta->fresh()->available);

        $erro = $this->erroDoValor($this->editar($receita->fresh(), ['type' => 'expense']));
        $this->assertStringContainsString('já foi recebida', $erro);
        $this->assertSame('income', $receita->fresh()->type);
    }

    public function test_receita_de_ontem_muda_descricao_e_data(): void
    {
        $receita = $this->lancamento('income', 500, '2026-08-04');

        $this->editar($receita, ['description' => 'Salário', 'date' => '2026-08-01'])
            ->assertSessionHasNoErrors();

        $atual = $receita->fresh();
        $this->assertSame('Salário', $atual->description);
        $this->assertSame('2026-08-01', $atual->date->toDateString());
        $this->assertSame('500.00', (string) $atual->amount);
    }

    // ============================================================ (c) agendada

    public function test_despesa_agendada_para_amanha_muda_de_valor_e_de_tipo(): void
    {
        $despesa = $this->lancamento('expense', 100, '2026-08-06');

        $this->editar($despesa, ['amount' => '250,00'])->assertSessionHasNoErrors();
        $this->assertSame('250.00', (string) $despesa->fresh()->amount);
        $this->assertSame(750.0, $this->conta->fresh()->available);

        $this->editar($despesa->fresh(), ['type' => 'income'])->assertSessionHasNoErrors();
        $this->assertSame('income', $despesa->fresh()->type);
        $this->assertSame(1250.0, $this->conta->fresh()->available);
    }

    // ============================================================ (d) cartão em aberto

    public function test_compra_de_cartao_em_aberto_muda_de_valor_mesmo_com_data_passada(): void
    {
        $cartao = Account::factory()->for($this->user)->creditCard()->create([
            'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
        $compra = $this->lancamento('expense', 200, '2026-08-01', $cartao);
        $this->assertNull($compra->paid_at);
        $this->assertFalse($compra->jaFoiPagaOuRecebida());

        $this->editar($compra, ['amount' => '250,00'])->assertSessionHasNoErrors();

        $this->assertSame('250.00', (string) $compra->fresh()->amount);
        $this->assertSame(250.0, $cartao->fresh()->committed);
    }

    public function test_compra_de_cartao_ja_paga_numa_fatura_nao_muda_de_valor(): void
    {
        $cartao = Account::factory()->for($this->user)->creditCard()->create([
            'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
        $compra = $this->lancamento('expense', 200, '2026-07-01', $cartao);
        $compra->forceFill(['paid_at' => '2026-07-20 10:00:00'])->save();

        // A guarda do topo do `update` (compra de cartão já paga) recusa antes de chegar ao valor.
        $this->editar($compra->fresh(), ['amount' => '250,00'])->assertSessionHasErrors('transaction');
        $this->assertStringContainsString('Estorne o pagamento', (string) session('errors')->first('transaction'));
        $this->assertSame('200.00', (string) $compra->fresh()->amount);

        $html = $this->actingAs($this->user)->get(route('transactions.edit', $compra))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*id="amount"[^>]*\sreadonly[\s>]/s', $html);
    }

    // ============================================================ (e) conta fixa paga

    public function test_pagamento_de_conta_fixa_muda_de_valor_e_o_saldo_acompanha(): void
    {
        $bill = FixedBill::create([
            'user_id' => $this->user->id,
            'name' => 'Condomínio',
            'amount' => 800,
            'due_day' => 10,
            'account_id' => $this->conta->id,
            'starts_on' => '2026-08-01',
            'active' => true,
        ]);

        $this->actingAs($this->user)
            ->post(route('contas-fixas.pagar', [$bill, '2026-08']), [
                'account_id' => $this->conta->id,
                'amount' => '800,00',
            ])->assertSessionHasNoErrors();

        $pagamento = Transaction::where('fixed_bill_id', $bill->id)->sole();

        $this->editar($pagamento, ['amount' => '850,00'])->assertSessionHasNoErrors();

        $atual = $pagamento->fresh();
        $this->assertSame('850.00', (string) $atual->amount);
        $this->assertSame('2026-08-01', $atual->competence->toDateString(), 'A competência paga continua a mesma.');
        $this->assertSame(150.0, $this->conta->fresh()->available);
    }

    // ============================================================ (f) a tela

    public function test_a_edicao_de_movimentacao_paga_deixa_o_valor_editavel_e_avisa_do_saldo(): void
    {
        $despesa = $this->lancamento('expense', 100, '2026-08-05');

        $html = $this->actingAs($this->user)->get(route('transactions.edit', $despesa))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="amount"[^>]*\sreadonly[\s>]/s', $html);
        $this->assertStringContainsString('Ao salvar, o saldo da conta acompanha o valor novo.', $html);
        // O tipo vai num hidden (a edição não oferece trocar receita por despesa).
        $this->assertStringContainsString('<input type="hidden" name="type" value="expense">', $html);
    }

    public function test_a_edicao_de_movimentacao_agendada_deixa_o_valor_editavel(): void
    {
        $despesa = $this->lancamento('expense', 100, '2026-08-06');

        $html = $this->actingAs($this->user)->get(route('transactions.edit', $despesa))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*id="amount"[^>]*>/s', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="amount"[^>]*\sreadonly[\s>]/s', $html);
        $this->assertStringNotContainsString('o valor não muda mais', $html);
        // O painel "Detalhes" diz que ainda não aconteceu (antes dizia "Paga — saiu da conta").
        $this->assertStringContainsString('Agendada — sai da conta em 06/08/2026', $html);
    }
}
