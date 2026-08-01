<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correções da 2ª rodada da auditoria (01/08/2026) — faturas e dashboard.
 *
 * Cada teste aqui FALHAVA antes da correção correspondente. Achados no relatório
 * docs/auditoria-completa-2026-07-28.md.
 */
class CorrecoesFaturaDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $corrente;

    private Account $cartao;

    private Category $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_admin' => true]);

        $this->corrente = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'initial_balance' => 5000,
            'overdraft_limit' => 0,
        ]);

        $this->cartao = Account::factory()->for($this->user)->create([
            'type' => 'credit_card',
            'initial_balance' => null,
            'credit_limit' => 5000,
            'closing_day' => 10,
            'due_day' => 20,
        ]);

        $this->categoria = Category::factory()->for($this->user)->expense()->create();

        $this->actingAs($this->user);
    }

    private function disponivel(): float
    {
        return round(Account::find($this->corrente->id)->available, 2);
    }

    /**
     * ACHADO A-4 — a próxima ocorrência da recorrência é criada com `Transaction::create`
     * cru, sem passar pelo FundingService. Alcançável em 2 passos: mover a recorrência do
     * cartão para uma conta de caixa e clicar em pagar — cada clique gera uma despesa nova
     * SEM TETO (disponível 0 → −100 → −200 → −300).
     */
    public function test_achado_a4_proxima_ocorrencia_respeita_a_trava_de_gasto(): void
    {
        // Recorrência numa CONTA DE CAIXA, com saldo exatamente suficiente para uma.
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'initial_balance' => 100,
            'overdraft_limit' => 0,
        ]);

        $recorrente = Transaction::factory()->for($this->user)->create([
            'account_id' => $conta->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => 100.00,
            'date' => now()->toDateString(),
            'recurring' => true,
            'paid_at' => null,
        ]);

        // Disponível já está em 0 (os 100 iniciais menos a despesa de 100).
        $this->assertSame(0.0, round(Account::find($conta->id)->available, 2));

        // Cada clique geraria uma ocorrência nova de R$ 100 sem saldo para cobrir.
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('faturas.recorrente.pagar', $recorrente));
        }

        $this->assertGreaterThanOrEqual(
            0.0,
            round(Account::find($conta->id)->available, 2),
            'A conta ficou negativa sem cheque especial: a próxima ocorrência não passa pela trava de gasto.',
        );
    }

    /**
     * ACHADO M-9 — excluir uma compra parcelada já parcialmente paga apagava a parcela
     * PAGA junto, deixando o pagamento órfão no extrato (saída de caixa sem dívida
     * correspondente). Histórico financeiro não se apaga.
     */
    public function test_achado_m9_excluir_parcelada_preserva_as_parcelas_ja_pagas(): void
    {
        $this->post(route('faturas.lancar'), [
            'account_id' => $this->cartao->id,
            'category_id' => $this->categoria->id,
            'description' => 'Compra 3x',
            'amount' => '300,00',
            'date' => now()->toDateString(),
            'mode' => 'parcelado',
            'installments' => 3,
        ])->assertSessionHasNoErrors();

        $parcelas = Transaction::where('account_id', $this->cartao->id)->orderBy('date')->get();
        $this->assertCount(3, $parcelas);

        // Paga a primeira parcela (simula fatura quitada).
        $parcelas[0]->update(['paid_at' => now()]);

        // Exclui a compra inteira.
        $this->delete(route('faturas.compra.destroy', $parcelas[1]));

        $pagaAindaExiste = Transaction::whereKey($parcelas[0]->id)->exists();

        $this->assertTrue(
            $pagaAindaExiste,
            'A parcela JÁ PAGA foi apagada — o pagamento fica órfão no extrato, sem dívida correspondente.',
        );

        // E as não pagas saem (é o propósito da exclusão).
        $this->assertFalse(Transaction::whereKey($parcelas[1]->id)->exists());
        $this->assertFalse(Transaction::whereKey($parcelas[2]->id)->exists());
    }

    /**
     * ACHADO M-6 — `paid_on` aceitava qualquer data desde 2000, inclusive anos antes da
     * compra. O saldo descontava hoje, mas a despesa saía do mês nos relatórios: some do
     * fluxo de caixa e do "gasto do mês".
     */
    public function test_achado_m6_paid_on_nao_aceita_data_anterior_a_divida(): void
    {
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->cartao->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => 300.00,
            'date' => now()->subDays(3)->toDateString(),
        ]);

        $this->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->corrente->id,
            'paid_on' => '2001-03-04',
        ])->assertSessionHasErrors('paid_on');

        $this->assertSame(
            5000.0,
            $this->disponivel(),
            'Pagamento com data absurda foi recusado, mas o saldo mudou.',
        );
    }

    /**
     * ACHADO A-1 — fatura de ciclo FECHADO (vencida) não tinha caminho de pagamento:
     * `payInvoice` só conhecia o ciclo aberto e respondia "já estava quitada", deixando a
     * dívida e o limite travados para sempre.
     */
    public function test_achado_a1_fatura_do_ciclo_fechado_pode_ser_paga(): void
    {
        // Compra dentro do ciclo JÁ FECHADO (antes do último fechamento).
        $fechamentoAnterior = now()->day > 10
            ? now()->startOfMonth()->addDays(9)      // dia 10 deste mês
            : now()->subMonth()->startOfMonth()->addDays(9);

        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->cartao->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => 750.00,
            'date' => $fechamentoAnterior->copy()->subDays(3)->toDateString(),
        ]);

        $cartao = Account::find($this->cartao->id);
        $this->assertSame(
            750.0,
            round((float) $cartao->closedInvoiceDue, 2),
            'O cenário deveria ter uma fatura fechada de R$ 750,00.',
        );

        $caixaAntes = $this->disponivel();

        $this->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->corrente->id,
            'ciclo' => 'fechado',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            round($caixaAntes - 750.0, 2),
            $this->disponivel(),
            'Pagar a fatura vencida tem de descontar R$ 750,00 do caixa.',
        );

        $this->assertSame(
            0.0,
            round((float) Account::find($this->cartao->id)->closedInvoiceDue, 2),
            'A fatura vencida deveria ficar quitada.',
        );
    }

    /**
     * ACHADO C-4 — pagar a fatura DOBRAVA as despesas do período: a transação de quitação
     * entrava nos agregados como se fosse um gasto novo. Compra de R$ 300 + fatura paga
     * mostrava R$ 600 de despesa no mês, e o donut ganhava uma fatia fantasma.
     *
     * O saldo sempre esteve certo — o problema é só de RELATÓRIO.
     */
    public function test_achado_c4_pagar_fatura_nao_dobra_as_despesas_do_periodo(): void
    {
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->cartao->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => 300.00,
            'date' => now()->toDateString(),
        ]);

        $antes = app(DashboardService::class)->build($this->user->ownerId());
        $despesasAntes = round((float) $antes['payload']['periods']['mes']['stats']['despesas'], 2);
        $this->assertSame(300.0, $despesasAntes);

        $this->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->corrente->id,
        ])->assertSessionHasNoErrors();

        $depois = app(DashboardService::class)->build($this->user->ownerId());
        $despesasDepois = round((float) $depois['payload']['periods']['mes']['stats']['despesas'], 2);

        $this->assertSame(
            300.0,
            $despesasDepois,
            "Pagar a fatura dobrou a despesa do mês (antes: {$despesasAntes}, depois: {$despesasDepois}) — "
                .'a quitação está sendo contada como gasto novo.',
        );

        // O donut também não pode ganhar fatia fantasma.
        $somaDonut = round(collect($depois['cats'])->sum('value'), 2);
        $this->assertSame(300.0, $somaDonut, 'O donut somou a quitação como se fosse gasto.');

        // Mas o SALDO tem de refletir a saída de verdade.
        $this->assertSame(4700.0, $this->disponivel(), 'O caixa tem de ter pago os R$ 300.');
    }

    /**
     * ACHADO A-11 — o card "Minhas contas" do dashboard mostrava o saldo CRU enquanto
     * `/accounts` mostrava o DISPONÍVEL. No mesmo card, o headline dizia R$ 1.000 e as
     * contas listadas somavam R$ 1.400.
     */
    public function test_achado_a11_dashboard_lista_contas_pelo_disponivel(): void
    {
        // Reserva R$ 400 numa meta: cru = 5.000, disponível = 4.600.
        $meta = \App\Models\Goal::factory()->for($this->user)->create();
        $this->post(route('metas.aportes.store', $meta), [
            'amount' => '400,00',
            'account_id' => $this->corrente->id,
        ])->assertSessionHasNoErrors();

        $dados = app(DashboardService::class)->build($this->user->ownerId());

        $linha = collect($dados['accounts'] ?? [])->firstWhere('id', $this->corrente->id);
        $this->assertNotNull($linha, 'A conta deveria aparecer na lista do dashboard.');

        $this->assertSame(
            4600.0,
            round((float) $linha['current_balance'], 2),
            'A lista do dashboard mostra o saldo cru; /accounts mostra o disponível. '
                .'Os dois têm de mostrar o disponível.',
        );
    }
    /**
     * ACHADO A-9 — o card "Contas a pagar" só somava faturas de cartão, então o
     * dashboard mostrava "Nada a pagar 🎉" com três meses de aluguel vencidos,
     * enquanto o sino da mesma tela contava 3.
     */
    public function test_achado_a9_dashboard_conta_as_contas_fixas_em_aberto(): void
    {
        if (! class_exists(\App\Models\FixedBill::class)) {
            $this->markTestSkipped('Contas fixas não disponíveis.');
        }

        \App\Models\FixedBill::create([
            'user_id' => $this->user->id,
            'name' => 'Aluguel',
            'amount' => 1800.00,
            'due_day' => 5,
            'account_id' => $this->corrente->id,
            'category_id' => $this->categoria->id,
            'starts_on' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'active' => true,
        ]);

        $dados = app(DashboardService::class)->build($this->user->ownerId());

        $this->assertGreaterThan(
            0.0,
            round((float) $dados['faturasResumo']['total'], 2),
            'O card "Contas a pagar" ignora contas fixas vencidas — mostra "Nada a pagar" '
                .'enquanto o sino avisa que há dívida.',
        );
    }
    /**
     * REGRESSÃO desta rodada — estorno lançado no cartão passou a abater a fatura
     * (achado A-6), mas o pagamento somava tudo como despesa: uma compra de R$ 1.000 com
     * estorno de R$ 300 cobrava R$ 1.300 do caixa. A soma do pagamento tem de usar o
     * mesmo sinal que o `committed` do cartão.
     */
    public function test_pagar_fatura_com_estorno_cobra_o_valor_liquido(): void
    {
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->cartao->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => 1000.00,
            'date' => now()->toDateString(),
        ]);

        // Estorno da loja: entra como receita NO CARTÃO.
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->cartao->id,
            'category_id' => null,
            'type' => 'income',
            'amount' => 300.00,
            'date' => now()->toDateString(),
        ]);

        $caixaAntes = $this->disponivel();

        $this->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->corrente->id,
        ])->assertSessionHasNoErrors();

        $cobrado = round($caixaAntes - $this->disponivel(), 2);

        $this->assertSame(
            700.00,
            $cobrado,
            "A fatura era de R$ 1.000 com R$ 300 de estorno; foram cobrados R$ {$cobrado}.",
        );
    }

    /**
     * REGRESSÃO desta rodada — ao tirar a quitação das somas de DESPESA (achado C-4), ela
     * saiu junto da série do SALDO, que usa a mesma consulta. Resultado: a linha do
     * patrimônio ignorava o dinheiro que saiu para pagar a fatura. Quitação não é gasto
     * novo, mas É saída de caixa.
     */
    public function test_spark_do_saldo_inclui_o_pagamento_da_fatura(): void
    {
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->cartao->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => 500.00,
            'date' => now()->toDateString(),
        ]);

        $this->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->corrente->id,
        ])->assertSessionHasNoErrors();

        $dados = app(DashboardService::class)->build($this->user->ownerId());
        $spark = $dados['payload']['sparks']['saldo'] ?? [];

        $this->assertNotEmpty($spark, 'A sparkline do saldo deveria ter pontos.');

        $ultimo = round((float) end($spark), 2);

        $this->assertSame(
            $this->disponivel(),
            $ultimo,
            'O último ponto da linha do saldo tem de bater com o saldo real depois do pagamento.',
        );
    }
}
