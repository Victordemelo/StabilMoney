<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GUARDAS DE EDIÇÃO DO HISTÓRICO (01/08/2026).
 *
 * O `destroy` do Histórico já recusava a quitação de fatura; o `update` não
 * tinha guarda NENHUMA. Como ele edita qualquer linha de `transactions` —
 * inclusive as que outros fluxos escreveram —, dava para corromper dinheiro de
 * cinco maneiras, todas com o mesmo formato: a linha tem contrapartida em outro
 * lugar (compras marcadas como pagas, limite do cartão, parcelas irmãs, resgate
 * de investimento) e mexer só num lado cria ou destrói saldo.
 *
 * Havia ainda um BYPASS estrutural: o ramo `type !== 'expense'` gravava com
 * `$transaction->update()` cru, sem passar pelo FundingService — bastava
 * transformar a despesa em receita para escapar de tudo. Por isso as guardas
 * ficam no TOPO do método, antes dos dois ramos de gravação.
 */
class GuardsDeEdicaoNoHistoricoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        // Dia 5: o ciclo aberto do cartão (fecha dia 10) é (10/07, 10/08], então
        // uma compra em 01/08 cai nele e a fatura é pagável.
        Carbon::setTestNow('2026-08-05');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Corrente',
            'initial_balance' => 1000,
            'overdraft_limit' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ============================ helpers ============================

    private function cartao(): Account
    {
        return Account::factory()->for($this->user)->create([
            'type' => 'credit_card',
            'name' => 'Nubank',
            'credit_limit' => 5000,
            'closing_day' => 10,
            'due_day' => 20,
        ]);
    }

    /** Investimento com `$aplicado` aportado a partir da conta corrente. */
    private function investimentoCom(float $aplicado): Investment
    {
        $inv = Investment::create([
            'user_id' => $this->user->id,
            'name' => 'CDB',
            'classe' => 'renda_fixa',
            'indexador' => 'cdi',
            'taxa' => 100,
        ]);

        $inv->contributions()->create([
            'account_id' => $this->conta->id,
            'made_by_user_id' => $this->user->id,
            'type' => 'aporte',
            'amount' => $aplicado,
            'date' => '2026-08-01',
        ]);

        return $inv;
    }

    /** Payload de edição VÁLIDO — o Form Request roda antes das guardas. */
    private function edicao(array $overrides = []): array
    {
        return array_merge([
            'type' => 'expense',
            'amount' => '100,00',
            'account_id' => $this->conta->id,
            'date' => '2026-08-05',
            'description' => 'Editado',
        ], $overrides);
    }

    private function editar(Transaction $transaction, array $overrides = [])
    {
        return $this->actingAs($this->user)
            ->from(route('transactions.index'))
            ->patch(route('transactions.update', $transaction), $this->edicao($overrides));
    }

    /**
     * Compra de R$ 300 no cartão + fatura paga pela corrente.
     *
     * @return array{0: Account, 1: Transaction, 2: Transaction} [cartão, compra, quitação]
     */
    private function faturaPaga(): array
    {
        $cartao = $this->cartao();

        $compra = Transaction::factory()->for($this->user)->for($cartao)->expense()
            ->create(['amount' => 300, 'date' => '2026-08-01', 'description' => 'Mercado']);

        $this->actingAs($this->user)
            ->post(route('faturas.fatura.pagar', $cartao), ['pay_account_id' => $this->conta->id])
            ->assertSessionHasNoErrors();

        return [$cartao, $compra->fresh(), Transaction::whereNotNull('settles_account_id')->firstOrFail()];
    }

    /** Condomínio de R$ 800 com a competência de agosto já paga pela corrente. */
    private function condominioPago(): array
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

        return [$bill, Transaction::where('fixed_bill_id', $bill->id)->firstOrFail()];
    }

    /** Compra parcelada em 12x de R$ 1.200 no cartão. */
    private function parcelado(): Account
    {
        $cartao = $this->cartao();

        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'description' => 'Geladeira',
            'amount' => '1.200,00',
            'date' => '2026-08-01',
            'account_id' => $cartao->id,
            'mode' => 'parcelado',
            'installments' => 12,
        ])->assertSessionHasNoErrors();

        return $cartao;
    }

    /**
     * Despesa de R$ 500 que só coube resgatando R$ 300 do CDB (que tinha
     * R$ 800 aportados a partir da corrente).
     *
     * @return array{0: Transaction, 1: Investment}
     */
    private function despesaComResgate(): array
    {
        $inv = $this->investimentoCom(800);

        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '500,00',
            'account_id' => $this->conta->id,
            'date' => '2026-08-05',
            'description' => 'Conserto do carro',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $inv->id,
        ])->assertSessionHasNoErrors();

        return [Transaction::where('description', 'Conserto do carro')->firstOrFail(), $inv];
    }

    // ================= 1) Quitação de fatura =================

    public function test_caso1_editar_a_quitacao_de_uma_fatura_e_recusado(): void
    {
        [$cartao, $compra, $quitacao] = $this->faturaPaga();

        $this->assertSame(700.0, $this->conta->fresh()->available, '1000 − 300 da fatura');

        $this->editar($quitacao, ['amount' => '10,00'])
            ->assertSessionHasErrors('transaction');

        // ANTES: a quitação virava R$ 10, o saldo voltava para R$ 990 e a compra
        // seguia quitada com o limite do cartão livre — R$ 290 nascidos do nada.
        $this->assertSame('300.00', (string) $quitacao->fresh()->amount);
        $this->assertSame(700.0, $this->conta->fresh()->available);
        $this->assertNotNull($compra->fresh()->paid_at);
        $this->assertSame(0.0, $cartao->fresh()->committed);
    }

    // ================= Bypass do ramo de receita =================

    public function test_bypass_quitacao_de_fatura_virando_receita_e_recusada(): void
    {
        [, , $quitacao] = $this->faturaPaga();

        $this->editar($quitacao, ['type' => 'income', 'amount' => '300,00'])
            ->assertSessionHasErrors('transaction');

        // ANTES: o ramo de receita gravava direto, sem FundingService nenhum —
        // a quitação virava uma receita de R$ 300, a conta ia a R$ 1.300 (mais
        // do que ela tinha ANTES da compra) e a fatura continuava paga.
        $this->assertSame('expense', $quitacao->fresh()->type);
        $this->assertSame(700.0, $this->conta->fresh()->available);
    }

    public function test_bypass_despesa_comum_continua_podendo_virar_receita(): void
    {
        $despesa = Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 200, 'date' => '2026-08-01']);

        $this->assertSame(800.0, $this->conta->fresh()->available);

        $this->editar($despesa, ['type' => 'income', 'amount' => '200,00'])
            ->assertSessionHasNoErrors();

        // A guarda não pode travar o caso legítimo: corrigir o tipo de um
        // lançamento comum segue sendo edição normal.
        $this->assertSame('income', $despesa->fresh()->type);
        $this->assertSame(1200.0, $this->conta->fresh()->available);
    }

    // ================= 2) Pagamento de conta fixa =================

    public function test_caso2_pagamento_de_conta_fixa_nao_pode_virar_receita(): void
    {
        [$bill, $pagamento] = $this->condominioPago();

        $this->assertSame(200.0, $this->conta->fresh()->available, '1000 − 800 do condomínio');

        $this->editar($pagamento, ['type' => 'income', 'amount' => '800,00'])
            ->assertSessionHasErrors('transaction');

        // ANTES: virava receita, o saldo subia para R$ 1.800 (R$ 800 acima do
        // inicial) e a competência de agosto continuava marcada como paga — o
        // que marca a competência é a EXISTÊNCIA da linha, não o tipo dela.
        $this->assertSame('expense', $pagamento->fresh()->type);
        $this->assertSame(200.0, $this->conta->fresh()->available);
        $this->assertSame(1, $bill->payments()->count());
    }

    public function test_caso2_corrigir_o_valor_pago_da_conta_fixa_continua_permitido(): void
    {
        [$bill, $pagamento] = $this->condominioPago();

        // Conta de luz varia: o valor REAL é editável; o vínculo com a
        // competência (fixed_bill_id + competence) não sai do Form Request.
        $this->editar($pagamento, ['amount' => '850,00'])
            ->assertSessionHasNoErrors();

        $atual = $pagamento->fresh();
        $this->assertSame('850.00', (string) $atual->amount);
        $this->assertSame($bill->id, $atual->fixed_bill_id);
        $this->assertSame('2026-08-01', $atual->competence->toDateString());
        $this->assertSame(150.0, $this->conta->fresh()->available);
    }

    // ================= 3) Compra de cartão já paga =================

    public function test_caso3_compra_de_cartao_ja_paga_nao_pode_ser_editada(): void
    {
        [$cartao, $compra] = $this->faturaPaga();

        $this->editar($compra, ['account_id' => $cartao->id, 'amount' => '1.000,00'])
            ->assertSessionHasErrors('transaction');

        // ANTES: a compra virava R$ 1.000 e o cartão não registrava nada —
        // `committed` ignora linhas com `paid_at` —, enquanto a quitação que a
        // pagou seguia valendo R$ 300. A dívida real subia R$ 700 em silêncio.
        $this->assertSame('300.00', (string) $compra->fresh()->amount);
        $this->assertSame(0.0, $cartao->fresh()->committed);
        $this->assertSame(700.0, $this->conta->fresh()->available);
    }

    public function test_caso3_compra_de_cartao_em_aberto_continua_editavel(): void
    {
        [$cartao] = $this->faturaPaga();

        $nova = Transaction::factory()->for($this->user)->for($cartao)->expense()
            ->create(['amount' => 200, 'date' => '2026-08-01', 'description' => 'Farmácia']);

        $this->assertSame(200.0, $cartao->fresh()->committed);

        $this->editar($nova, ['account_id' => $cartao->id, 'amount' => '250,00'])
            ->assertSessionHasNoErrors();

        // Em aberto o limite acompanha a edição — é justamente por isso que a
        // linha JÁ PAGA não pode ser editada.
        $this->assertSame(250.0, $cartao->fresh()->committed);
    }

    // ================= 4) Parcela isolada =================

    public function test_caso4_editar_uma_parcela_isolada_e_recusado(): void
    {
        $cartao = $this->parcelado();
        $segunda = Transaction::where('installment_no', 2)->firstOrFail();

        $this->editar($segunda, ['account_id' => $cartao->id, 'amount' => '500,00'])
            ->assertSessionHasErrors('transaction');

        // ANTES: a soma da compra ia a R$ 1.600 com as 12 linhas continuando a
        // dizer "de 12" — nenhuma tela conseguia explicar o total.
        $parcelas = Transaction::where('group_id', $segunda->group_id)->get();
        $this->assertCount(12, $parcelas);
        $this->assertSame(1200.0, round($parcelas->sum(fn ($t) => (float) $t->amount), 2));
        $this->assertSame(1200.0, $cartao->fresh()->committed);
    }

    public function test_caso4_excluir_uma_parcela_isolada_e_recusado(): void
    {
        $cartao = $this->parcelado();
        $segunda = Transaction::where('installment_no', 2)->firstOrFail();

        $this->actingAs($this->user)->from(route('transactions.index'))
            ->delete(route('transactions.destroy', $segunda))
            ->assertSessionHasErrors('transaction');

        // ANTES: sobravam 11 linhas com "1/12" e "3/12" órfãs no histórico.
        $this->assertSame(12, Transaction::where('group_id', $segunda->group_id)->count());
        $this->assertSame(1200.0, $cartao->fresh()->committed);
    }

    public function test_caso4_a_compra_inteira_continua_removivel_em_pagar_despesas(): void
    {
        $cartao = $this->parcelado();
        $segunda = Transaction::where('installment_no', 2)->firstOrFail();

        // A saída que a mensagem indica precisa existir de verdade.
        $this->actingAs($this->user)
            ->delete(route('faturas.compra.destroy', $segunda))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Transaction::where('group_id', $segunda->group_id)->count());
        $this->assertSame(0.0, $cartao->fresh()->committed);
    }

    public function test_caso4_recorrencia_nao_e_parcela_e_segue_editavel(): void
    {
        // Recorrente tem `group_id` mas NÃO tem `installments`: a guarda de
        // parcela não pode pegá-la (não há irmãs com valor a manter coerente).
        $cartao = $this->cartao();

        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'description' => 'Streaming',
            'amount' => '49,90',
            'date' => '2026-08-01',
            'account_id' => $cartao->id,
            'mode' => 'recorrente',
        ])->assertSessionHasNoErrors();

        $ocorrencia = Transaction::where('description', 'Streaming')->firstOrFail();
        $this->assertNotNull($ocorrencia->group_id);
        $this->assertNull($ocorrencia->installments);

        $this->editar($ocorrencia, ['account_id' => $cartao->id, 'amount' => '59,90'])
            ->assertSessionHasNoErrors();

        $this->assertSame('59.90', (string) $ocorrencia->fresh()->amount);
    }

    // ================= 5) Despesa financiada por resgate =================

    public function test_caso5_baixar_a_despesa_devolve_o_resgate_ao_investimento(): void
    {
        [$despesa, $inv] = $this->despesaComResgate();

        // Estado inicial: 500 de despesa = 200 disponíveis + 300 resgatados.
        $this->assertSame('300.00', (string) $despesa->funding_amount);
        $this->assertSame(500.0, $inv->fresh()->aplicado);
        $this->assertSame(0.0, $this->conta->fresh()->available);

        $resposta = $this->editar($despesa, ['amount' => '100,00'])
            ->assertSessionHasNoErrors();

        // ANTES: `estornarFonte` só rodava no delete, então o resgate de R$ 300
        // continuava de pé numa despesa de R$ 100 — o aplicado ficava em 500
        // para sempre e a conta exibia 400 de disponível. R$ 300 fora de todo bolso.
        $this->assertSame(800.0, $inv->fresh()->aplicado);
        $this->assertSame(0, $inv->contributions()->where('type', 'resgate')->count());

        $conta = $this->conta->fresh();
        $this->assertSame(900.0, $conta->balance);
        $this->assertSame(800.0, $conta->reserved);
        $this->assertSame(100.0, $conta->available);

        $atual = $despesa->fresh();
        $this->assertNull($atual->funding_source);
        $this->assertNull($atual->funding_amount);

        // O usuário precisa SABER que voltou dinheiro para o investimento.
        $this->assertStringContainsString(
            'voltaram para o investimento',
            (string) $resposta->getSession()->get('status'),
        );
    }

    public function test_caso5_baixar_so_um_pouco_pergunta_a_fonte_e_nao_deixa_residuo(): void
    {
        [$despesa, $inv] = $this->despesaComResgate();

        // R$ 400 ainda não cabe nos R$ 200 livres: o app PERGUNTA (409), nunca
        // decide sozinho de onde tirar o dinheiro.
        $this->actingAs($this->user)
            ->patchJson(route('transactions.update', $despesa), $this->edicao(['amount' => '400,00']))
            ->assertStatus(409)
            ->assertJsonPath('precisa_fonte', true);

        // E a reconciliação inteira volta atrás: nada gravado pela metade.
        $this->assertSame(500.0, $inv->fresh()->aplicado);
        $this->assertSame('500.00', (string) $despesa->fresh()->amount);
        $this->assertSame(1, $inv->contributions()->where('type', 'resgate')->count());

        // Escolhendo o resgate de novo, o valor é recalculado do ZERO: 200
        // (o que falta), e não 300 + 200 acumulados.
        $this->editar($despesa, [
            'amount' => '400,00',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $inv->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('200.00', (string) $despesa->fresh()->funding_amount);
        $this->assertSame(600.0, $inv->fresh()->aplicado);
        $this->assertSame(1, $inv->contributions()->where('type', 'resgate')->count());

        $conta = $this->conta->fresh();
        $this->assertSame(600.0, $conta->balance);
        $this->assertSame(600.0, $conta->reserved);
        $this->assertSame(0.0, $conta->available);
    }

    public function test_caso5_aumentar_a_despesa_resgata_a_diferenca_e_avisa(): void
    {
        [$despesa, $inv] = $this->despesaComResgate();

        $resposta = $this->editar($despesa, [
            'amount' => '700,00',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $inv->id,
        ])->assertSessionHasNoErrors();

        // 700 com 200 livres = 500 resgatados — um resgate só, recalculado, e
        // não os 300 antigos somados a um novo.
        $this->assertSame('500.00', (string) $despesa->fresh()->funding_amount);
        $this->assertSame(300.0, $inv->fresh()->aplicado);
        $this->assertSame(1, $inv->contributions()->where('type', 'resgate')->count());
        $this->assertSame(0.0, $this->conta->fresh()->available);

        $this->assertStringContainsString(
            'Resgatamos mais',
            (string) $resposta->getSession()->get('status'),
        );
    }

    public function test_caso5_despesa_com_resgate_virando_receita_tambem_estorna(): void
    {
        [$despesa, $inv] = $this->despesaComResgate();

        $this->editar($despesa, ['type' => 'income', 'amount' => '500,00'])
            ->assertSessionHasNoErrors();

        // ANTES (bypass + caso 5 juntos): o ramo de receita gravava direto, o
        // resgate de R$ 300 sobrevivia e o aplicado ficava em R$ 500 para sempre.
        $this->assertSame(800.0, $inv->fresh()->aplicado);
        $this->assertSame(0, $inv->contributions()->where('type', 'resgate')->count());

        $conta = $this->conta->fresh();
        $this->assertSame(1500.0, $conta->balance);
        $this->assertSame(800.0, $conta->reserved);
        $this->assertSame(700.0, $conta->available);
        $this->assertNull($despesa->fresh()->funding_source);
    }

    public function test_caso5_auditoria_de_cheque_especial_nao_fica_para_tras(): void
    {
        $magra = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Magra',
            'initial_balance' => 100,
            'overdraft_limit' => 1000,
        ]);

        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '600,00',
            'account_id' => $magra->id,
            'date' => '2026-08-05',
            'description' => 'Digitou errado',
            'funding_source' => FundingSource::CHEQUE_ESPECIAL,
        ])->assertSessionHasNoErrors();

        $despesa = Transaction::where('description', 'Digitou errado')->firstOrFail();
        $this->assertSame('500.00', (string) $despesa->funding_amount);

        // Corrigido para um valor que cabe no disponível: a auditoria some.
        // ANTES, `spend` devolvia auditoria vazia e as colunas antigas sobreviviam
        // — uma despesa de R$ 50 marcada como "cheque especial R$ 500".
        $this->editar($despesa, ['account_id' => $magra->id, 'amount' => '50,00'])
            ->assertSessionHasNoErrors();

        $atual = $despesa->fresh();
        $this->assertNull($atual->funding_source);
        $this->assertNull($atual->funding_amount);
        $this->assertSame(50.0, $magra->fresh()->available);
    }

    // ================= As edições legítimas continuam passando =================

    public function test_edicao_comum_de_despesa_continua_passando(): void
    {
        $despesa = Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 200, 'date' => '2026-08-01']);

        $this->editar($despesa, ['amount' => '150,00'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.index'));

        $this->assertSame('150.00', (string) $despesa->fresh()->amount);
        $this->assertSame(850.0, $this->conta->fresh()->available);
    }

    public function test_despesa_avulsa_continua_excluivel_pelo_historico(): void
    {
        $despesa = Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 90, 'date' => '2026-08-01']);

        $this->actingAs($this->user)->from(route('transactions.index'))
            ->delete(route('transactions.destroy', $despesa))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('transactions', ['id' => $despesa->id]);
        $this->assertSame(1000.0, $this->conta->fresh()->available);
    }

    // ================= Casos 5 e 6 (achados durante a correção) =================

    /**
     * Mover uma linha JÁ PAGA para um cartão fazia a dívida evaporar: `committed`
     * ignora linha com `paid_at`, então o cartão nunca cobrava — e o dinheiro
     * voltava para a conta de origem.
     */
    public function test_nao_move_despesa_ja_paga_para_um_cartao(): void
    {
        $cartao = Account::factory()->for($this->user)->create([
            'type' => 'credit_card', 'name' => 'Nubank',
            'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);

        $paga = Transaction::factory()->for($this->user)->for($this->conta)->expense()->create([
            'amount' => 800, 'date' => '2026-08-01', 'paid_at' => now(),
        ]);

        $saldoAntes = $this->conta->fresh()->available;

        $this->actingAs($this->user)
            ->patch(route('transactions.update', $paga), [
                'type' => 'expense',
                'amount' => '800,00',
                'account_id' => $cartao->id,
                'date' => '2026-08-01',
            ])->assertSessionHasErrors('transaction');

        $paga->refresh();
        $this->assertSame($this->conta->id, $paga->account_id, 'a linha não pode migrar para o cartão');
        $this->assertSame($saldoAntes, $this->conta->fresh()->available, 'o dinheiro não pode voltar');
        $this->assertSame(0.0, $cartao->fresh()->committed);
    }

    /** Mover para outra conta de CAIXA continua permitido (paguei pela outra conta). */
    public function test_mover_despesa_paga_entre_contas_de_caixa_continua_permitido(): void
    {
        $outra = Account::factory()->for($this->user)->create([
            'type' => 'savings', 'name' => 'Poupança', 'initial_balance' => 5000,
        ]);

        $paga = Transaction::factory()->for($this->user)->for($this->conta)->expense()->create([
            'amount' => 300, 'date' => '2026-08-01', 'paid_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->patch(route('transactions.update', $paga), [
                'type' => 'expense',
                'amount' => '300,00',
                'account_id' => $outra->id,
                'date' => '2026-08-01',
            ])->assertSessionHasNoErrors();

        $this->assertSame($outra->id, $paga->fresh()->account_id);
    }

    /**
     * Excluir pelo Histórico uma compra de cartão JÁ QUITADA deixava a saída de
     * caixa órfã — a tela Pagar despesas já recusava, o Histórico não.
     */
    public function test_nao_exclui_compra_de_cartao_ja_quitada_pelo_historico(): void
    {
        $cartao = Account::factory()->for($this->user)->create([
            'type' => 'credit_card', 'name' => 'Nubank',
            'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);

        Transaction::factory()->for($this->user)->for($cartao)->expense()
            ->create(['amount' => 400, 'date' => '2026-08-01', 'description' => 'Mercado']);

        $this->actingAs($this->user)
            ->post(route('faturas.fatura.pagar', $cartao), ['pay_account_id' => $this->conta->id])
            ->assertSessionHasNoErrors();

        $compra = Transaction::where('description', 'Mercado')->firstOrFail();
        $saldoDepoisDoPagamento = $this->conta->fresh()->available;

        $this->actingAs($this->user)
            ->delete(route('transactions.destroy', $compra))
            ->assertSessionHasErrors('transaction');

        $this->assertDatabaseHas('transactions', ['id' => $compra->id]);
        $this->assertSame($saldoDepoisDoPagamento, $this->conta->fresh()->available);
    }

    /** Compra de cartão EM ABERTO continua excluível pelo Histórico. */
    public function test_compra_de_cartao_em_aberto_continua_excluivel(): void
    {
        $cartao = Account::factory()->for($this->user)->create([
            'type' => 'credit_card', 'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);

        $compra = Transaction::factory()->for($this->user)->for($cartao)->expense()
            ->create(['amount' => 400, 'date' => '2026-08-01']);

        $this->actingAs($this->user)
            ->delete(route('transactions.destroy', $compra))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('transactions', ['id' => $compra->id]);
    }
}
