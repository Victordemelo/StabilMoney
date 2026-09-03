<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Auditoria de 02/09/2026: editar SÓ a descrição de uma despesa financiada por
 * resgate desfazia o resgate.
 *
 * `TransactionController::update` reconciliava a fonte do zero em TODA edição
 * (`estornarFonte` + `spend`). Corrigir um typo devolvia R$ 300 ao investimento
 * e avisava "voltaram para o investimento" — coerente, mas o resgate já tinha
 * acontecido no banco de verdade. Regra nova: reconciliar APENAS quando valor,
 * conta ou tipo mudarem. Campo neutro (descrição, categoria, data, autor) grava
 * direto e preserva `funding_*` e o resgate ligado.
 */
class EdicaoNeutraPreservaResgateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Investment $inv;

    private Transaction $despesa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-05');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Corrente',
            'initial_balance' => 1000,
            'overdraft_limit' => 0,
        ]);

        // R$ 800 aplicados a partir da corrente → 200 disponíveis.
        $this->inv = Investment::create([
            'user_id' => $this->user->id,
            'name' => 'CDB',
            'classe' => 'renda_fixa',
            'indexador' => 'cdi',
            'taxa' => 100,
        ]);
        $this->inv->contributions()->create([
            'account_id' => $this->conta->id,
            'made_by_user_id' => $this->user->id,
            'type' => 'aporte',
            'amount' => 800,
            'date' => '2026-08-01',
        ]);

        // Despesa de R$ 500 que só coube resgatando R$ 300.
        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '500,00',
            'account_id' => $this->conta->id,
            'date' => '2026-08-05',
            'description' => 'Conserto do carro',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $this->inv->id,
        ])->assertSessionHasNoErrors();

        $this->despesa = Transaction::where('description', 'Conserto do carro')->firstOrFail();

        $this->assertSame('300.00', (string) $this->despesa->funding_amount);
        $this->assertSame(500.0, $this->inv->fresh()->aplicado);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function edicao(array $overrides = []): array
    {
        return array_merge([
            'type' => 'expense',
            'amount' => '500,00',
            'account_id' => $this->conta->id,
            'date' => '2026-08-05',
            'description' => 'Conserto do carro',
        ], $overrides);
    }

    private function editar(array $overrides = [])
    {
        return $this->actingAs($this->user)
            ->from(route('transactions.index'))
            ->patch(route('transactions.update', $this->despesa), $this->edicao($overrides));
    }

    private function resgateLigado(): int
    {
        return $this->inv->contributions()
            ->where('type', 'resgate')
            ->where('transaction_id', $this->despesa->id)
            ->count();
    }

    /** O cenário do relatório: corrigir um typo devolvia R$ 300 ao investimento. */
    public function test_mudar_so_a_descricao_preserva_o_resgate(): void
    {
        $resposta = $this->editar(['description' => 'Conserto do carro (oficina do Zé)'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'));

        $atual = $this->despesa->fresh();
        $this->assertSame('Conserto do carro (oficina do Zé)', $atual->description);
        $this->assertSame(FundingSource::RESGATE_INVESTIMENTO, $atual->funding_source);
        $this->assertSame('300.00', (string) $atual->funding_amount);

        $this->assertSame(500.0, $this->inv->fresh()->aplicado, 'O resgate foi desfeito numa edição neutra.');
        $this->assertSame(1, $this->resgateLigado(), 'O resgate perdeu o vínculo com a despesa.');
        $this->assertSame(0.0, $this->conta->fresh()->available);

        // E o flash não anuncia movimento nenhum de investimento.
        $this->assertSame('Transação atualizada.', (string) $resposta->getSession()->get('status'));
    }

    /** Categoria, data e autor também são neutros. */
    public function test_mudar_categoria_data_e_autor_preserva_o_resgate(): void
    {
        $categoria = Category::factory()->for($this->user)->expense()->create();

        $this->editar([
            'category_id' => $categoria->id,
            'date' => '2026-08-03',
            'made_by_user_id' => $this->user->id,
        ])->assertSessionHasNoErrors();

        $atual = $this->despesa->fresh();
        $this->assertSame($categoria->id, $atual->category_id);
        $this->assertSame('2026-08-03', $atual->date->toDateString());
        $this->assertSame(FundingSource::RESGATE_INVESTIMENTO, $atual->funding_source);
        $this->assertSame(500.0, $this->inv->fresh()->aplicado);
        $this->assertSame(1, $this->resgateLigado());
    }

    /**
     * Edição neutra não passa pelo guard: com a conta no vermelho por uma
     * obrigação, corrigir o texto de uma despesa não pode ser recusado por
     * "saldo insuficiente".
     */
    public function test_edicao_neutra_nao_e_barrada_pela_trava_de_gasto(): void
    {
        // Uma obrigação levou a conta a −100 (o guard aceitaria, com obrigacao: true).
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->conta->id,
            'type' => 'expense',
            'amount' => 100,
            'date' => '2026-08-05',
            'description' => 'Boleto vencido',
        ]);
        $this->assertSame(-100.0, $this->conta->fresh()->available);

        $this->editar(['description' => 'Conserto do carro — pago'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Conserto do carro — pago', $this->despesa->fresh()->description);
        $this->assertSame(500.0, $this->inv->fresh()->aplicado);
    }

    // ================= mudou o dinheiro → reconcilia como antes =================

    public function test_mudar_o_valor_continua_reconciliando(): void
    {
        $resposta = $this->editar(['amount' => '100,00'])->assertSessionHasNoErrors();

        // 100 cabe nos 200 livres: o resgate inteiro volta.
        $this->assertSame(800.0, $this->inv->fresh()->aplicado);
        $this->assertSame(0, $this->resgateLigado());
        $this->assertNull($this->despesa->fresh()->funding_source);
        $this->assertStringContainsString(
            'voltaram para o investimento',
            (string) $resposta->getSession()->get('status'),
        );
    }

    public function test_mudar_a_conta_continua_reconciliando(): void
    {
        $outra = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Poupança',
            'initial_balance' => 2000,
            'overdraft_limit' => 0,
        ]);

        $this->editar(['account_id' => $outra->id])->assertSessionHasNoErrors();

        // Na outra conta os 500 cabem: o resgate da corrente é desfeito.
        $this->assertSame(800.0, $this->inv->fresh()->aplicado);
        $this->assertSame(0, $this->resgateLigado());
        $this->assertNull($this->despesa->fresh()->funding_source);
        $this->assertSame(1000.0, $this->conta->fresh()->balance);
        $this->assertSame(1500.0, $outra->fresh()->balance);
    }

    public function test_virar_receita_continua_reconciliando(): void
    {
        $this->editar(['type' => 'income'])->assertSessionHasNoErrors();

        $this->assertSame(800.0, $this->inv->fresh()->aplicado);
        $this->assertSame(0, $this->resgateLigado());
        $this->assertNull($this->despesa->fresh()->funding_source);
        $this->assertSame(1500.0, $this->conta->fresh()->balance);
    }
}
