<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\FaturaService;
use App\Services\SidebarService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Modelo de dinheiro do app — os invariantes que a auditoria de 27/07/2026
 * levantou e a spec do cheque especial fechou.
 *
 * Nasceu como prova empírica dos bugs (cada teste afirmava o comportamento
 * ERRADO, para mostrar que existia) e virou a rede de regressão deles. Os
 * poucos que ainda documentam comportamento aceito-mas-não-ideal estão
 * marcados no docblock — hoje: despesa futura e recorrência não paga já
 * entram no saldo (ver §4.5 e a decisão D-4 da spec).
 *
 * Spec: docs/superpowers/specs/2026-07-27-cheque-especial-segregacao-e-contas-fixas.md
 */
class ModeloDeDinheiroTest extends TestCase
{
    use RefreshDatabase;

    private function titular(): User
    {
        return User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
    }

    /** A-01: despesa com data FUTURA e não paga já derruba o saldo de hoje. */
    public function test_despesa_futura_ja_desconta_do_saldo(): void
    {
        $u = $this->titular();
        $conta = Account::factory()->for($u)->create(['type' => 'checking', 'initial_balance' => 1000]);

        Transaction::create([
            'user_id' => $u->id, 'account_id' => $conta->id, 'type' => 'expense',
            'amount' => 400, 'date' => CarbonImmutable::today()->addMonths(6)->toDateString(),
        ]);

        fwrite(STDERR, "\n[A-01] saldo com despesa daqui 6 meses: " . $conta->fresh()->balance . " (esperado intuitivo: 1000)\n");
        $this->assertSame(600.0, $conta->fresh()->balance);
    }

    /** A-02: recorrência NÃO paga (paid_at null) também já desconta. */
    public function test_recorrencia_nao_paga_ja_desconta_do_saldo(): void
    {
        $u = $this->titular();
        $conta = Account::factory()->for($u)->create(['type' => 'checking', 'initial_balance' => 1000]);

        Transaction::create([
            'user_id' => $u->id, 'account_id' => $conta->id, 'type' => 'expense',
            'amount' => 800, 'date' => CarbonImmutable::today()->toDateString(),
            'recurring' => true, 'paid_at' => null, 'group_id' => 'g1',
        ]);

        fwrite(STDERR, "[A-02] saldo com condominio NAO pago: " . $conta->fresh()->balance . "\n");
        $this->assertSame(200.0, $conta->fresh()->balance);
    }

    /** B-01: despesa no cartão de DÉBITO não reduz as contas espelhadas — o dinheiro some. */
    public function test_despesa_no_cartao_de_debito_nao_reduz_a_conta_espelhada(): void
    {
        $u = $this->titular();
        $corrente = Account::factory()->for($u)->create(['type' => 'checking', 'initial_balance' => 1000]);
        $debito = Account::factory()->for($u)->create([
            'type' => 'debit_card', 'initial_balance' => null, 'checking_account_id' => $corrente->id,
        ]);

        Transaction::create([
            'user_id' => $u->id, 'account_id' => $debito->id, 'type' => 'expense',
            'amount' => 300, 'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $saldoCorrente = $corrente->fresh()->balance;
        $saldoDebito = Account::find($debito->id)->balance;

        fwrite(STDERR, "[B-01] gastou 300 no cartao de DEBITO -> corrente: {$saldoCorrente} | debito(espelho): {$saldoDebito}\n");
        $this->assertSame(1000.0, $saldoCorrente, 'a corrente NAO foi debitada');
        $this->assertSame(1000.0, $saldoDebito, 'o espelho tambem nao muda');
    }

    /** B-02 [CORRIGIDO] — sidebar e dashboard agora somam a MESMA coisa. */
    public function test_sidebar_e_dashboard_concordam_com_cartao_de_debito(): void
    {
        $u = $this->titular();
        $corrente = Account::factory()->for($u)->create(['type' => 'checking', 'initial_balance' => 1000]);
        $debito = Account::factory()->for($u)->create([
            'type' => 'debit_card', 'initial_balance' => null, 'checking_account_id' => $corrente->id,
        ]);

        Transaction::create([
            'user_id' => $u->id, 'account_id' => $debito->id, 'type' => 'expense',
            'amount' => 300, 'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $sidebar = app(SidebarService::class)->build($u->id);
        $dash = app(DashboardService::class)->build($u->id);

        fwrite(STDERR, "[B-02] sidebar patrimonio: {$sidebar['saldoTotal']} | dashboard saldo: {$dash['totalBalance']}\n");
        // Os dois excluem crédito E débito — nenhuma tela conta o mesmo dinheiro duas vezes.
        $this->assertSame($sidebar['saldoTotal'], $dash['totalBalance'], 'os dois totais precisam bater');
    }

    /** C-01 [CORRIGIDO] — sem cheque especial nem investimento, a despesa é recusada. */
    public function test_despesa_maior_que_o_saldo_e_recusada(): void
    {
        $u = $this->titular();
        $conta = Account::factory()->for($u)->create(['type' => 'checking', 'initial_balance' => 100]);

        $this->actingAs($u)->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => '99.999,99',
            'account_id' => $conta->id, 'date' => CarbonImmutable::today()->toDateString(),
        ])->assertSessionHasErrors('amount');

        fwrite(STDERR, "[C-01] saldo intacto apos tentar gastar 99.999,99 tendo 100: " . $conta->fresh()->balance . "\n");
        $this->assertSame(100.0, $conta->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    /** C-01b — com cheque especial, vai até o limite e nem um centavo além. */
    public function test_cheque_especial_deixa_ficar_negativo_ate_o_limite(): void
    {
        $u = $this->titular();
        $conta = Account::factory()->for($u)->create([
            'type' => 'checking', 'initial_balance' => 2500, 'overdraft_limit' => 2500,
        ]);

        // Zera o saldo.
        $this->actingAs($u)->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => '2.500,00',
            'account_id' => $conta->id, 'date' => CarbonImmutable::today()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(0.0, Account::find($conta->id)->available);

        // Sem escolher a fonte: 409 pedindo a escolha (não é erro de formulário).
        $this->actingAs($u)->postJson(route('transactions.store'), [
            'type' => 'expense', 'amount' => '300,00',
            'account_id' => $conta->id, 'date' => CarbonImmutable::today()->toDateString(),
        ])->assertStatus(409)->assertJsonPath('precisa_fonte', true);

        // Escolhendo o cheque especial: passa e fica negativo.
        $this->actingAs($u)->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => '300,00', 'funding_source' => 'cheque_especial',
            'account_id' => $conta->id, 'date' => CarbonImmutable::today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $c = Account::find($conta->id);
        fwrite(STDERR, "[C-01b] disponivel: {$c->available} | cheque usado: {$c->overdraftUsed} de {$c->overdraftLimitValue} | ainda da p/ gastar: {$c->spendable}\n");
        $this->assertSame(-300.0, $c->available);
        $this->assertSame(2200.0, $c->spendable);

        // Estourar o limite é recusado.
        $this->actingAs($u)->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => '2.200,01', 'funding_source' => 'cheque_especial',
            'account_id' => $conta->id, 'date' => CarbonImmutable::today()->toDateString(),
        ])->assertSessionHasErrors('amount');

        $this->assertSame(-300.0, Account::find($conta->id)->available);
    }

    /** C-02 [CORRIGIDO] — gastar não come mais o investido: o app pergunta a fonte. */
    public function test_gasto_nao_consome_o_investido_sem_perguntar(): void
    {
        $u = $this->titular();
        $conta = Account::factory()->for($u)->create(['type' => 'checking', 'initial_balance' => 2500]);
        $inv = Investment::create(['user_id' => $u->id, 'name' => 'CDB', 'classe' => 'renda_fixa']);
        $inv->contributions()->create([
            'account_id' => $conta->id, 'type' => 'aporte', 'amount' => 1000,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        // Disponível = 2500 − 1000 investidos = 1500. Gastar 2000 comeria o investido:
        // sem cheque especial e sem escolher a fonte, o app oferece o resgate (409).
        $r = $this->actingAs($u)->postJson(route('transactions.store'), [
            'type' => 'expense', 'amount' => '2.000,00',
            'account_id' => $conta->id, 'date' => CarbonImmutable::today()->toDateString(),
        ])->assertStatus(409);

        $fonte = $r->json('fonte');
        fwrite(STDERR, "[C-02] faltante oferecido: {$fonte['faltante']} | fontes: " . implode(',', array_column($fonte['fontes'], 'id')) . "\n");
        $this->assertEqualsWithDelta(500.0, $fonte['faltante'], 0.001, 'resgata so o que falta, nao o total');

        // Escolhendo o resgate: o investido cai 500 e o saldo NÃO fica negativo.
        $this->actingAs($u)->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => '2.000,00',
            'funding_source' => 'resgate_investimento', 'funding_investment_id' => $inv->id,
            'account_id' => $conta->id, 'date' => CarbonImmutable::today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $c = Account::find($conta->id);
        fwrite(STDERR, "[C-02] apos resgate -> disponivel: {$c->available} | investido: {$inv->fresh()->aplicado}\n");
        $this->assertSame(0.0, $c->available, 'o saldo nao fica negativo');
        $this->assertSame(500.0, $inv->fresh()->aplicado, 'o investido caiu exatamente o faltante');
    }

    /** C-03 [CORRIGIDO] — não dá mais para estourar o limite do cartão. */
    public function test_nao_da_para_estourar_o_limite_do_cartao(): void
    {
        $u = $this->titular();
        $card = Account::factory()->for($u)->creditCard()->create(['credit_limit' => 1000]);

        $this->actingAs($u)->post(route('faturas.lancar'), [
            'description' => 'TV', 'amount' => '5.000,00', 'account_id' => $card->id,
            'date' => CarbonImmutable::today()->toDateString(), 'mode' => 'avista',
        ])->assertSessionHasErrors('amount');

        $c = $card->fresh();
        fwrite(STDERR, "[C-03] limite 1000 -> compra de 5000 recusada. comprometido: {$c->committed}\n");
        $this->assertSame(0.0, $c->committed);
        $this->assertDatabaseCount('transactions', 0);
    }

    /** D-01 [CORRIGIDO] — fatura de ciclo fechado e não paga aparece como VENCIDA. */
    public function test_fatura_do_ciclo_anterior_nao_paga_aparece_como_vencida(): void
    {
        // Hoje = 25/07. Cartão fecha dia 10, vence dia 20.
        // Compra em 05/07 pertence ao ciclo (10/06, 10/07] -> venceu em 20/07 e não foi paga.
        $this->travelTo(CarbonImmutable::parse('2026-07-25'));

        $u = $this->titular();
        $card = Account::factory()->for($u)->creditCard()->create(['closing_day' => 10, 'due_day' => 20]);

        Transaction::create([
            'user_id' => $u->id, 'account_id' => $card->id, 'type' => 'expense',
            'amount' => 750, 'date' => '2026-07-05', 'paid_at' => null,
        ]);

        $c = $card->fresh();
        $atrasada = $c->overdueInvoice;
        $sino = app(FaturaService::class)->upcomingDue($u->id, 7);

        fwrite(STDERR, "[D-01] fatura de 05/07 nao paga | ciclo fechado devendo: {$c->closedInvoiceDue} | venceu em: "
            . $atrasada['vencimento']->toDateString() . " | atraso: {$atrasada['diasAtraso']} dias | itens no sino: " . $sino->count() . "\n");

        $this->assertSame(750.0, $c->closedInvoiceDue, 'a divida do ciclo fechado nao some mais');
        $this->assertSame('2026-07-20', $atrasada['vencimento']->toDateString());
        $this->assertSame(5, $atrasada['diasAtraso']);

        // E o sino avisa, marcada como vencida.
        $this->assertCount(1, $sino);
        $this->assertTrue((bool) $sino->first()['vencida']);
        $this->assertSame(-5, $sino->first()['diasRestantes']);

        $this->travelBack();
    }

    /** B-03 [CORRIGIDO] — aporte pelo cartão de débito é recusado. */
    public function test_aporte_pelo_cartao_de_debito_e_recusado(): void
    {
        $u = $this->titular();
        $corrente = Account::factory()->for($u)->create(['type' => 'checking', 'initial_balance' => 5000]);
        $debito = Account::factory()->for($u)->create([
            'type' => 'debit_card', 'initial_balance' => null, 'checking_account_id' => $corrente->id,
        ]);
        $inv = Investment::create(['user_id' => $u->id, 'name' => 'CDB', 'classe' => 'renda_fixa']);

        // Aportar "pelo cartão de débito" agora é recusado: ele não tem saldo
        // próprio, e aceitar isso permitia guardar o dobro do que existia.
        $this->actingAs($u)->post(route('investimentos.aportes.store', $inv), [
            'amount' => '5.000,00', 'account_id' => $debito->id,
        ])->assertSessionHasErrors('account_id');

        // Pela corrente funciona, e só até o que ela tem.
        $this->actingAs($u)->post(route('investimentos.aportes.store', $inv), [
            'amount' => '5.000,00', 'account_id' => $corrente->id,
        ])->assertSessionHasNoErrors();

        $aplicado = $inv->fresh()->aplicado;
        fwrite(STDERR, "[B-03] dinheiro real: 5000 | APLICADO no investimento: {$aplicado}\n");
        $this->assertSame(5000.0, $aplicado, 'guardou exatamente o que existia');
    }

    /** E-01 [CORRIGIDO] — parcelar uma compra do dia 31 cai no último dia dos meses curtos. */
    public function test_parcelamento_no_dia_31_respeita_fevereiro(): void
    {
        $u = $this->titular();
        $card = Account::factory()->for($u)->creditCard()->create();

        $this->actingAs($u)->post(route('faturas.lancar'), [
            'description' => 'Geladeira', 'amount' => '3.000,00', 'account_id' => $card->id,
            'date' => '2027-01-31', 'mode' => 'parcelado', 'installments' => 3,
        ])->assertRedirect();

        $datas = Transaction::where('account_id', $card->id)->orderBy('installment_no')->pluck('date')
            ->map(fn ($d) => $d->toDateString())->all();

        fwrite(STDERR, "[E-01] parcelas de uma compra em 31/01/2027: " . implode(' | ', $datas) . "\n");
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $datas);
    }

    /** E-02: aportar da conta A e resgatar para a conta B cria dinheiro do nada. */
    public function test_resgatar_para_outra_conta_cria_dinheiro_fantasma(): void
    {
        $u = $this->titular();
        $a = Account::factory()->for($u)->create(['type' => 'checking', 'initial_balance' => 1000]);
        $b = Account::factory()->for($u)->create(['type' => 'checking', 'initial_balance' => 0]);
        $inv = Investment::create(['user_id' => $u->id, 'name' => 'CDB', 'classe' => 'renda_fixa']);

        // aporta 1.000 saindo de A
        $this->actingAs($u)->post(route('investimentos.aportes.store', $inv), [
            'amount' => '1.000,00', 'account_id' => $a->id,
        ])->assertRedirect();

        // resgata 1.000 mas manda para B
        $this->actingAs($u)->post(route('investimentos.resgates.store', $inv), [
            'amount' => '1.000,00', 'account_id' => $b->id,
        ])->assertRedirect();

        $ra = Account::find($a->id);
        $rb = Account::find($b->id);
        $total = $ra->available + $rb->available;

        fwrite(STDERR, "[E-02] A: saldo {$ra->balance} reservado {$ra->reserved} disponivel {$ra->available} | B: saldo {$rb->balance} reservado {$rb->reserved} disponivel {$rb->available} | DISPONIVEL TOTAL: {$total}\n");

        // O TOTAL se conserva (1000) — o plano da varredura errou nesse ponto.
        // O defeito real e a DISTRIBUICAO: a conta B passa a oferecer R$ 1.000
        // "disponiveis" tendo R$ 0,00 de saldo, porque o reservado dela ficou
        // NEGATIVO (-1000). Se o limite de gasto olhar o disponivel por conta,
        // da para gastar 1.000 de uma conta vazia.
        $this->assertSame(1000.0, $total, 'o total se conserva');
        $this->assertSame(0.0, $rb->balance, 'B nao tem dinheiro nenhum');
        $this->assertSame(-1000.0, $rb->reserved, 'reservado NEGATIVO em B');
        $this->assertSame(1000.0, $rb->available, 'mas B oferece 1.000 disponiveis');
    }

    /**
     * G-01: parcelamento no cartão — as parcelas são geradas? O total fica retido
     * no limite? O limite volta conforme PAGA (ou só conforme o tempo passa)?
     */
    public function test_parcelamento_retencao_de_limite_e_liberacao_ao_pagar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-05'));

        $u = $this->titular();
        $caixa = Account::factory()->for($u)->create(['type' => 'checking', 'initial_balance' => 10000]);
        $card = Account::factory()->for($u)->creditCard()->create([
            'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);

        // Compra de R$ 600 em 6x = 6 parcelas de R$ 100
        $this->actingAs($u)->post(route('faturas.lancar'), [
            'description' => 'Celular', 'amount' => '600,00', 'account_id' => $card->id,
            'date' => '2027-01-05', 'mode' => 'parcelado', 'installments' => 6,
        ])->assertRedirect();

        $parcelas = Transaction::where('account_id', $card->id)->orderBy('installment_no')->get();
        fwrite(STDERR, "\n[G-01] parcelas geradas: " . $parcelas->count()
            . " | datas: " . $parcelas->pluck('date')->map(fn ($d) => $d->format('d/m/y'))->implode(' '));

        $this->assertCount(6, $parcelas, 'gerou 1 transacao por mes');
        $this->assertEqualsWithDelta(600.0, (float) $parcelas->sum('amount'), 0.01);

        // 1) O total da compra fica RETIDO no limite?
        $c = $card->fresh();
        fwrite(STDERR, "\n[G-01] logo apos a compra -> comprometido: {$c->committed} | limite disponivel: {$c->availableLimit} | fatura do ciclo: {$c->currentInvoice}");
        $this->assertSame(600.0, $c->committed, 'o total das 6 parcelas fica retido');
        $this->assertSame(4400.0, $c->availableLimit);
        $this->assertSame(100.0, $c->currentInvoice, 'a fatura do mes cobra so 1 parcela');

        // 2) O limite volta ao PAGAR a fatura: cai exatamente 1 parcela.
        $this->actingAs($u)->post(route('faturas.fatura.pagar', $card), ['pay_account_id' => $caixa->id]);
        $c = Account::find($card->id);
        fwrite(STDERR, "\n[G-01] DEPOIS DE PAGAR a 1a fatura -> comprometido: {$c->committed} | limite disponivel: {$c->availableLimit}");
        $this->assertSame(500.0, $c->committed, 'pagar devolve o valor da parcela paga');
        $this->assertSame(4500.0, $c->availableLimit);

        // 3) O tempo passando SEM pagar NÃO devolve limite nenhum.
        $this->travelTo(CarbonImmutable::parse('2027-02-15')); // dois ciclos depois
        $c2 = Account::find($card->id);
        fwrite(STDERR, "\n[G-01] um mes depois, sem pagar de novo -> comprometido: {$c2->committed} | limite disponivel: {$c2->availableLimit}\n");
        $this->assertSame(500.0, $c2->committed, 'so o pagamento libera limite, nunca o calendario');

        $this->travelBack();
    }

    /**
     * D-02 — pagar fatura é OBRIGAÇÃO: sem cheque especial nem investimento, o
     * pagamento passa e a conta fica negativa (não se recusa um boleto).
     */
    public function test_pagar_fatura_sem_fonte_negativa_a_conta(): void
    {
        $u = $this->titular();
        $conta = Account::factory()->for($u)->create(['type' => 'checking', 'initial_balance' => 50]);
        $card = Account::factory()->for($u)->creditCard()->create(['closing_day' => 10, 'due_day' => 20]);

        Transaction::create([
            'user_id' => $u->id, 'account_id' => $card->id, 'type' => 'expense',
            'amount' => 900, 'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $this->actingAs($u)->post(route('faturas.fatura.pagar', $card), ['pay_account_id' => $conta->id])
            ->assertSessionHasNoErrors();

        fwrite(STDERR, "[D-02] conta com 50 pagou fatura de 900 -> saldo: " . $conta->fresh()->available . " (negativado, sem cheque especial)\n");
        $this->assertSame(-850.0, $conta->fresh()->available);
    }

    /**
     * D-02b — o app NUNCA usa o cheque especial sozinho: havendo fonte, o
     * pagamento devolve 409 pedindo a escolha.
     */
    public function test_pagar_fatura_com_cheque_especial_pergunta_antes(): void
    {
        $u = $this->titular();
        $conta = Account::factory()->for($u)->create([
            'type' => 'checking', 'initial_balance' => 50, 'overdraft_limit' => 2000,
        ]);
        $card = Account::factory()->for($u)->creditCard()->create(['closing_day' => 10, 'due_day' => 20]);

        Transaction::create([
            'user_id' => $u->id, 'account_id' => $card->id, 'type' => 'expense',
            'amount' => 900, 'date' => CarbonImmutable::today()->toDateString(),
        ]);

        // Tem cheque especial que cobre -> 409, não paga sozinho.
        $r = $this->actingAs($u)->postJson(route('faturas.fatura.pagar', $card), [
            'pay_account_id' => $conta->id,
        ])->assertStatus(409);

        fwrite(STDERR, "[D-02b] fatura de 900 com 50 em conta e 2000 de cheque -> 409, faltante: "
            . $r->json('fonte.faltante') . " | fatura segue em aberto\n");

        // Nada foi pago enquanto ele não escolhe.
        $this->assertSame(50.0, Account::find($conta->id)->available);
        $this->assertNull(Transaction::where('account_id', $card->id)->first()->paid_at);

        // Escolhendo o cheque especial, aí sim paga.
        $this->actingAs($u)->post(route('faturas.fatura.pagar', $card), [
            'pay_account_id' => $conta->id, 'funding_source' => 'cheque_especial',
        ])->assertSessionHasNoErrors();

        $this->assertSame(-850.0, Account::find($conta->id)->available);
    }
}
