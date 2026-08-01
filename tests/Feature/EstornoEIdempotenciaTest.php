<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quatro buracos de integridade fechados em 01/08/2026 — todos no tema
 * "escrever dinheiro é fácil, desescrever não existia":
 *
 * 1) Excluir despesa financiada por RESGATE não estornava o resgate: o aplicado
 *    do investimento encolhia para sempre, sem contrapartida nenhuma.
 * 2) `faturas.lancar` não tinha `client_uuid`: duplo clique lançava a compra
 *    duas vezes — e no parcelado, as N parcelas duas vezes.
 * 3) `gerarProximaOcorrencia` decidia fora do lock: dois POSTs simultâneos
 *    criavam duas ocorrências do mesmo mês.
 * 4) Não havia estorno de pagamento de fatura: o clique era irreversível.
 */
class EstornoEIdempotenciaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-01');

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

    private function cartao(array $overrides = []): Account
    {
        return Account::factory()->for($this->user)->create(array_merge([
            'type' => 'credit_card',
            'name' => 'Nubank',
            'credit_limit' => 5000,
            'closing_day' => 10,
            'due_day' => 20,
        ], $overrides));
    }

    /** Investimento com R$ 800 aplicados a partir da conta corrente. */
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
            'date' => '2026-07-01',
        ]);

        return $inv;
    }

    // ================= 1) Estorno do resgate ao excluir a despesa =================

    /**
     * Cria uma despesa que só cabe resgatando do investimento e devolve
     * [despesa, investimento].
     *
     * Disponível = 1000 − 800 reservados = 200. A despesa de 500 precisa de
     * 300 vindos do resgate.
     */
    private function despesaFinanciadaPorResgate(): array
    {
        $inv = $this->investimentoCom(800);

        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '500,00',
            'account_id' => $this->conta->id,
            'date' => '2026-08-01',
            'description' => 'Conserto do carro',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $inv->id,
        ])->assertSessionHasNoErrors();

        return [Transaction::where('description', 'Conserto do carro')->firstOrFail(), $inv];
    }

    public function test_resgate_nasce_ligado_a_despesa_que_ele_financiou(): void
    {
        [$despesa, $inv] = $this->despesaFinanciadaPorResgate();

        $this->assertSame(FundingSource::RESGATE_INVESTIMENTO, $despesa->funding_source);
        $this->assertSame('300.00', $despesa->funding_amount);

        $resgate = $inv->contributions()->where('type', 'resgate')->firstOrFail();
        $this->assertSame($despesa->id, $resgate->transaction_id);
        $this->assertSame(500.0, $inv->fresh()->aplicado, 'aplicado = 800 − 300 resgatados');
    }

    public function test_excluir_despesa_financiada_por_resgate_devolve_o_dinheiro_ao_investimento(): void
    {
        [$despesa, $inv] = $this->despesaFinanciadaPorResgate();

        $this->actingAs($this->user)
            ->delete(route('transactions.destroy', $despesa))
            ->assertSessionHasNoErrors();

        // Antes da correção o resgate sobrevivia: aplicado ficava em 500 para
        // sempre e o reservado da conta, 300 menor — dinheiro fora de todo lugar.
        $this->assertSame(800.0, $inv->fresh()->aplicado, 'o resgate tem de ser desfeito junto');
        $this->assertSame(0, $inv->contributions()->where('type', 'resgate')->count());

        $conta = $this->conta->fresh();
        $this->assertSame(1000.0, $conta->balance);
        $this->assertSame(800.0, $conta->reserved);
        $this->assertSame(200.0, $conta->available, 'volta exatamente ao estado inicial');
    }

    public function test_excluir_pela_tela_de_faturas_tambem_estorna_o_resgate(): void
    {
        [$despesa, $inv] = $this->despesaFinanciadaPorResgate();

        $this->actingAs($this->user)
            ->delete(route('faturas.compra.destroy', $despesa))
            ->assertSessionHasNoErrors();

        $this->assertSame(800.0, $inv->fresh()->aplicado);
    }

    public function test_excluir_compra_parcelada_estorna_o_resgate_do_grupo(): void
    {
        // Delete em MASSA (o grupo inteiro) não dispara evento de model nenhum —
        // é exatamente o caminho onde um hook de Eloquent teria falhado.
        $inv = $this->investimentoCom(800);

        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'description' => 'Geladeira',
            'amount' => '500,00',
            'date' => '2026-08-01',
            'account_id' => $this->conta->id,
            'mode' => 'avista',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $inv->id,
        ])->assertSessionHasNoErrors();

        $despesa = Transaction::where('description', 'Geladeira')->firstOrFail();
        $this->assertSame(500.0, $inv->fresh()->aplicado);

        $this->actingAs($this->user)
            ->delete(route('faturas.compra.destroy', $despesa))
            ->assertSessionHasNoErrors();

        $this->assertSame(800.0, $inv->fresh()->aplicado);
    }

    // ================= 2) client_uuid em faturas.lancar =================

    /** @return array<string, mixed> */
    private function payloadLancar(array $overrides = []): array
    {
        return array_merge([
            'client_uuid' => (string) Str::uuid(),
            'description' => 'Notebook',
            'amount' => '1.200,00',
            'date' => '2026-08-01',
            'account_id' => $this->cartao()->id,
            'mode' => 'avista',
        ], $overrides);
    }

    public function test_lancar_a_mesma_despesa_duas_vezes_com_o_mesmo_uuid_nao_duplica(): void
    {
        $payload = $this->payloadLancar();

        $this->actingAs($this->user)->post(route('faturas.lancar'), $payload)
            ->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('faturas.lancar'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::where('description', 'Notebook')->count());
        $this->assertSame($payload['client_uuid'], Transaction::first()->client_uuid);
    }

    public function test_duplo_clique_num_parcelado_nao_gera_o_dobro_das_parcelas(): void
    {
        $cartao = $this->cartao();
        $payload = $this->payloadLancar([
            'account_id' => $cartao->id,
            'mode' => 'parcelado',
            'installments' => 12,
        ]);

        $this->actingAs($this->user)->post(route('faturas.lancar'), $payload);
        $this->actingAs($this->user)->post(route('faturas.lancar'), $payload);

        // Antes: 24 linhas e R$ 2.400 comendo o limite do cartão.
        $parcelas = Transaction::where('description', 'Notebook')->get();
        $this->assertCount(12, $parcelas);
        $this->assertSame(1200.0, round($parcelas->sum(fn ($t) => (float) $t->amount), 2));
        $this->assertSame(1200.0, $cartao->fresh()->committed);

        // O uuid identifica a COMPRA: vive só na primeira parcela (o índice é
        // único em user_id+client_uuid).
        $this->assertSame(1, $parcelas->whereNotNull('client_uuid')->count());
    }

    public function test_uuids_diferentes_continuam_lancando_despesas_diferentes(): void
    {
        $cartao = $this->cartao();

        $this->actingAs($this->user)->post(route('faturas.lancar'), $this->payloadLancar(['account_id' => $cartao->id]));
        $this->actingAs($this->user)->post(route('faturas.lancar'), $this->payloadLancar(['account_id' => $cartao->id]));

        $this->assertSame(2, Transaction::where('description', 'Notebook')->count());
    }

    public function test_lancamento_sem_uuid_continua_funcionando(): void
    {
        $payload = $this->payloadLancar();
        unset($payload['client_uuid']);

        $this->actingAs($this->user)->post(route('faturas.lancar'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Transaction::where('description', 'Notebook')->count());
    }

    // ================= 3) Recorrência não duplica a próxima ocorrência =================

    public function test_pagar_a_recorrencia_do_cartao_duas_vezes_gera_uma_unica_proxima(): void
    {
        $cartao = $this->cartao();

        $this->actingAs($this->user)->post(route('faturas.lancar'), $this->payloadLancar([
            'description' => 'Streaming',
            'amount' => '49,90',
            'account_id' => $cartao->id,
            'mode' => 'recorrente',
        ]))->assertSessionHasNoErrors();

        $ocorrencia = Transaction::where('description', 'Streaming')->firstOrFail();

        $this->actingAs($this->user)->post(route('faturas.recorrente.pagar', $ocorrencia));
        $this->actingAs($this->user)->post(route('faturas.recorrente.pagar', $ocorrencia));
        $this->actingAs($this->user)->post(route('faturas.recorrente.pagar', $ocorrencia));

        // 1 original + 1 próxima. Antes: uma nova a cada clique, todas no mesmo mês.
        $linhas = Transaction::where('description', 'Streaming')->orderBy('date')->get();
        $this->assertCount(2, $linhas);
        $this->assertCount(2, $linhas->unique(fn ($t) => $t->date->toDateString()),
            'as duas ocorrências precisam estar em meses diferentes');

        $this->assertSame(
            $ocorrencia->date->copy()->addMonthNoOverflow()->toDateString(),
            $linhas->last()->date->toDateString(),
        );
    }

    public function test_recorrencia_em_conta_corrente_paga_e_gera_a_proxima_uma_vez_so(): void
    {
        $recorrente = Transaction::factory()->for($this->user)->for($this->conta)->expense()->create([
            'description' => 'Academia',
            'amount' => 100,
            'date' => '2026-08-05',
            'group_id' => (string) Str::uuid(),
            'recurring' => true,
        ]);

        $this->actingAs($this->user)->post(route('faturas.recorrente.pagar', $recorrente));
        $this->actingAs($this->user)->post(route('faturas.recorrente.pagar', $recorrente));

        $this->assertSame(2, Transaction::where('description', 'Academia')->count());
        $this->assertNotNull($recorrente->fresh()->paid_at);
    }

    // ================= 4) Estorno do pagamento de fatura =================

    /** Cria uma compra no cartão, paga a fatura e devolve [cartão, quitação]. */
    private function faturaPaga(): array
    {
        $cartao = $this->cartao();

        Transaction::factory()->for($this->user)->for($cartao)->expense()
            ->create(['amount' => 300, 'date' => '2026-08-01', 'description' => 'Mercado']);

        $this->actingAs($this->user)
            ->post(route('faturas.fatura.pagar', $cartao), ['pay_account_id' => $this->conta->id])
            ->assertSessionHasNoErrors();

        return [$cartao, Transaction::whereNotNull('settles_account_id')->firstOrFail()];
    }

    public function test_pagar_a_fatura_liga_cada_compra_a_quitacao(): void
    {
        [$cartao, $quitacao] = $this->faturaPaga();

        $compra = Transaction::where('description', 'Mercado')->firstOrFail();
        $this->assertSame($quitacao->id, $compra->settled_by_id);
        $this->assertNotNull($compra->paid_at);
        $this->assertSame(0.0, $cartao->fresh()->committed, 'pagar devolve o limite');
    }

    public function test_estornar_devolve_a_compra_para_a_fatura_e_o_dinheiro_para_a_conta(): void
    {
        [$cartao, $quitacao] = $this->faturaPaga();

        $this->assertSame(700.0, $this->conta->fresh()->available);

        $this->actingAs($this->user)
            ->delete(route('faturas.fatura.estornar', $quitacao))
            ->assertSessionHasNoErrors();

        // O dinheiro volta…
        $this->assertSame(1000.0, $this->conta->fresh()->available);
        $this->assertDatabaseMissing('transactions', ['id' => $quitacao->id]);

        // …e a dívida também: a compra volta a ser fatura em aberto e a consumir limite.
        $compra = Transaction::where('description', 'Mercado')->firstOrFail();
        $this->assertNull($compra->paid_at);
        $this->assertNull($compra->settled_by_id);
        $this->assertSame(300.0, $cartao->fresh()->committed);
        $this->assertSame(300.0, $cartao->fresh()->openInvoiceDue);
    }

    public function test_estorno_desfaz_tambem_o_resgate_que_pagou_a_fatura(): void
    {
        $inv = $this->investimentoCom(800);   // disponível na conta cai para 200
        $cartao = $this->cartao();

        Transaction::factory()->for($this->user)->for($cartao)->expense()
            ->create(['amount' => 300, 'date' => '2026-08-01', 'description' => 'Mercado']);

        // 300 de fatura com 200 disponíveis: faltam 100, resgatados do CDB.
        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $cartao), [
            'pay_account_id' => $this->conta->id,
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $inv->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(700.0, $inv->fresh()->aplicado);

        $quitacao = Transaction::whereNotNull('settles_account_id')->firstOrFail();

        $this->actingAs($this->user)
            ->delete(route('faturas.fatura.estornar', $quitacao))
            ->assertSessionHasNoErrors();

        $this->assertSame(800.0, $inv->fresh()->aplicado, 'o resgate volta junto com o pagamento');
        $this->assertSame(200.0, $this->conta->fresh()->available);
    }

    public function test_estornar_uma_linha_que_nao_e_quitacao_e_recusado(): void
    {
        $comum = Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 50, 'date' => '2026-08-01']);

        $this->actingAs($this->user)
            ->delete(route('faturas.fatura.estornar', $comum))
            ->assertSessionHasErrors('transaction');

        $this->assertDatabaseHas('transactions', ['id' => $comum->id]);
    }

    public function test_estorno_de_outra_familia_da_403(): void
    {
        [, $quitacao] = $this->faturaPaga();
        $intruso = User::factory()->create();

        $this->actingAs($intruso)
            ->delete(route('faturas.fatura.estornar', $quitacao))
            ->assertForbidden();

        $this->assertDatabaseHas('transactions', ['id' => $quitacao->id]);
    }

    public function test_pagar_e_estornar_em_sequencia_nao_deixa_residuo(): void
    {
        [$cartao, $quitacao] = $this->faturaPaga();

        $this->actingAs($this->user)->delete(route('faturas.fatura.estornar', $quitacao));

        // Paga de novo: tem de dar exatamente o mesmo resultado da primeira vez.
        $this->actingAs($this->user)
            ->post(route('faturas.fatura.pagar', $cartao), ['pay_account_id' => $this->conta->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(700.0, $this->conta->fresh()->available);
        $this->assertSame(1, Transaction::whereNotNull('settles_account_id')->count());
        $this->assertSame(0.0, $cartao->fresh()->committed);
    }
}
