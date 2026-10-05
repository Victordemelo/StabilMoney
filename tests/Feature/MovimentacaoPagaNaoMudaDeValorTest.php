<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Atividade;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Movimentação JÁ PAGA ou JÁ RECEBIDA não muda de valor nem de tipo (out/2026 — regra do
 * Victor).
 *
 * O dinheiro já saiu ou já entrou de verdade: mudar o número dela pelo Histórico era
 * reescrever o passado da conta (e, numa despesa financiada, refazer a escolha da fonte
 * em cima de um resgate que já aconteceu no banco). Para corrigir o valor, a pessoa exclui
 * e lança de novo. O resto — descrição, categoria, data, autor, conta — continua editável.
 *
 * "Já paga/recebida" é `Transaction::jaFoiPagaOuRecebida()`: compra de cartão de crédito
 * quando tem `paid_at` (a fatura dela foi paga); qualquer outra movimentação quando a data
 * dela já chegou (`date <= hoje`). Lançamento AGENDADO e compra de cartão em aberto seguem
 * editáveis por inteiro — é por eles que a reconciliação de valor/tipo continua alcançável.
 */
class MovimentacaoPagaNaoMudaDeValorTest extends TestCase
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

    public function test_despesa_de_hoje_nao_muda_de_valor_e_nada_e_gravado(): void
    {
        $despesa = $this->lancamento('expense', 100, '2026-08-05');
        Atividade::query()->delete();

        $erro = $this->erroDoValor($this->editar($despesa, ['amount' => '150,00', 'description' => 'Nova']));

        $this->assertStringContainsString('já foi paga', $erro);
        $this->assertStringContainsString('exclua a movimentação e lance de novo', $erro);

        $atual = $despesa->fresh();
        $this->assertSame('100.00', (string) $atual->amount);
        $this->assertSame('Original', $atual->description, 'A edição recusada não grava nada, nem os campos livres.');
        $this->assertSame(900.0, $this->conta->fresh()->available);
        $this->assertSame(0, Atividade::where('acao', 'transacao.editada')->count());
    }

    public function test_despesa_de_hoje_nao_muda_de_tipo(): void
    {
        $despesa = $this->lancamento('expense', 100, '2026-08-05');

        $erro = $this->erroDoValor($this->editar($despesa, ['type' => 'income']));

        $this->assertStringContainsString('o valor e o tipo não mudam mais', $erro);
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

    public function test_receita_de_ontem_nao_muda_de_valor_nem_de_tipo_e_a_mensagem_diz_recebida(): void
    {
        $receita = $this->lancamento('income', 500, '2026-08-04');

        $erro = $this->erroDoValor($this->editar($receita, ['amount' => '50,00']));
        $this->assertStringContainsString('já foi recebida', $erro);
        $this->assertStringNotContainsString('já foi paga', $erro);
        $this->assertSame('500.00', (string) $receita->fresh()->amount);

        $erro = $this->erroDoValor($this->editar($receita, ['type' => 'expense']));
        $this->assertStringContainsString('já foi recebida', $erro);
        $this->assertSame('income', $receita->fresh()->type);

        $this->assertSame(1500.0, $this->conta->fresh()->available);
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

    // ============================================================ (e) conta fixa paga

    public function test_pagamento_de_conta_fixa_nao_muda_de_valor(): void
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

        $erro = $this->erroDoValor($this->editar($pagamento, ['amount' => '850,00']));
        $this->assertStringContainsString('já foi paga', $erro);

        $atual = $pagamento->fresh();
        $this->assertSame('800.00', (string) $atual->amount);
        $this->assertSame('2026-08-01', $atual->competence->toDateString());
        $this->assertSame(200.0, $this->conta->fresh()->available);

        // A descrição continua corrigível.
        $this->editar($pagamento, ['description' => 'Condomínio de agosto'])->assertSessionHasNoErrors();
        $this->assertSame('Condomínio de agosto', $pagamento->fresh()->description);
        $this->assertSame('800.00', (string) $pagamento->fresh()->amount);
    }

    // ============================================================ (f) a tela

    public function test_a_edicao_de_movimentacao_paga_mostra_o_valor_so_leitura(): void
    {
        $despesa = $this->lancamento('expense', 100, '2026-08-05');

        $html = $this->actingAs($this->user)->get(route('transactions.edit', $despesa))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*id="amount"[^>]*\sreadonly[\s>]/s', $html);
        $this->assertStringContainsString('o valor não muda mais', $html);
        // O tipo vai num hidden (os rádios ficam desabilitados e não seriam enviados).
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
