<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O invariante I1 do modelo de dinheiro — `available >= −overdraft_limit` numa conta de
 * caixa, "dura" na spec de 27/07 — só era conferido quando o dinheiro SAI por uma despesa
 * NOVA (`FundingService::spend`). Quando ele sai porque algo que já estava lá deixa de
 * existir, nada o conferia:
 *
 *  - excluir uma RECEITA pelo Histórico (`TransactionController::destroy`) — ou pela URL
 *    de `faturas.compra.destroy`;
 *  - baixar o valor dela, ou mudá-la de conta (`TransactionController::update`, ramo de
 *    receita — grava com `update()` cru);
 *  - excluir uma transferência cujo destino já gastou o dinheiro (a ponta de ENTRADA some);
 *  - excluir a despesa paga com RESGATE quando o resgate cobriu também o vermelho que a
 *    conta já tinha (o faltante): o `estornarFonte` tira da conta mais do que a despesa
 *    devolve.
 *
 * É o mesmo buraco que a auditoria fechou duas vezes por outros caminhos: o 1.6 de 28/07
 * (receita virando despesa, "ia a −R$ 500 sem cheque especial") e o F-4 de 02/09 (reduzir
 * o `initial_balance`, "um estado que nenhum lançamento consegue produzir, porque o
 * SpendingGuard o recusaria"). Esses caminhos produziam exatamente esse estado: a conta
 * abaixo do piso — e o guardado da meta virava dinheiro que a conta não tem.
 *
 * A correção segue a escolha do F-4: recusa só o que deixaria a conta ABAIXO do piso; o
 * resto (inclusive entrar no cheque especial) passa como antes.
 */
class TirarDinheiroDaContaRespeitaOPisoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================ cenário

    /** Conta corrente; sem cheque especial, o piso do disponível é R$ 0,00. */
    private function conta(float $inicial = 0, string $nome = 'Corrente', float $cheque = 0): Account
    {
        return Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => $nome, 'initial_balance' => $inicial, 'overdraft_limit' => $cheque,
        ]);
    }

    private function receita(Account $conta, string $valor): Transaction
    {
        $this->post(route('transactions.store'), [
            'type' => 'income', 'amount' => $valor, 'account_id' => $conta->id,
            'date' => '2026-09-05', 'description' => 'Salário',
        ])->assertSessionHasNoErrors();

        return Transaction::where('type', 'income')->latest('id')->firstOrFail();
    }

    private function despesa(Account $conta, string $valor, array $extra = []): void
    {
        // Pelo caminho de verdade: passa pela trava (FundingService::spend).
        $this->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => $valor, 'account_id' => $conta->id,
            'date' => '2026-09-10', 'description' => 'Aluguel',
        ] + $extra)->assertSessionHasNoErrors();
    }

    private function editarReceita(Transaction $receita, string $valor, ?Account $conta = null, string $tipo = 'income'): TestResponse
    {
        return $this->from(route('transactions.edit', $receita))->put(route('transactions.update', $receita), [
            'type' => $tipo, 'amount' => $valor, 'account_id' => ($conta ?? $receita->account)->id,
            'date' => '2026-09-05', 'description' => 'Salário',
        ]);
    }

    private function excluir(Transaction $linha): TestResponse
    {
        return $this->from(route('transactions.edit', $linha))->delete(route('transactions.destroy', $linha));
    }

    private function disponivel(Account $conta): float
    {
        return Account::find($conta->id)->available;
    }

    private function assertNoPiso(Account $conta): void
    {
        $fresca = Account::find($conta->id);

        $this->assertGreaterThanOrEqual(
            -$fresca->overdraftLimitValue,
            $fresca->available,
            'A conta '.$fresca->name.' ficou em '.$fresca->available.' com cheque especial de '
                .$fresca->overdraftLimitValue.': abaixo do piso que o modelo promete.',
        );
    }

    private function erro(TestResponse $resposta): string
    {
        $resposta->assertSessionHasErrors('transaction');

        return (string) session('errors')->first('transaction');
    }

    // ================================================================ o buraco

    /** Espelho do cenário A do F-4, com o dinheiro vindo de uma receita em vez do saldo inicial. */
    public function test_excluir_a_receita_que_sustenta_o_guardado_nao_fura_o_piso(): void
    {
        $conta = $this->conta();
        $salario = $this->receita($conta, '1.000,00');
        Goal::factory()->for($this->user)->create()->contributions()->create([
            'account_id' => $conta->id, 'type' => 'aporte', 'amount' => 800, 'date' => '2026-09-06',
        ]);
        $this->assertSame(200.0, $this->disponivel($conta));

        $erro = $this->erro($this->excluir($salario));

        // Antes: −R$ 800,00 sem cheque especial — e a meta seguia "guardando" R$ 800
        // que a conta não tinha mais.
        $this->assertNoPiso($conta);
        $this->assertNotNull($salario->fresh(), 'A receita continua lá.');
        $this->assertSame(200.0, $this->disponivel($conta));
        $this->assertStringContainsString('−R$ 800,00', $erro, 'Diz em quanto a conta ficaria.');
        $this->assertStringContainsString('não tem cheque especial', $erro);
    }

    public function test_excluir_a_receita_ja_gasta_nao_fura_o_piso(): void
    {
        $conta = $this->conta();
        $salario = $this->receita($conta, '1.000,00');
        $this->despesa($conta, '900,00');
        $this->assertSame(100.0, $this->disponivel($conta));

        $this->erro($this->excluir($salario));

        $this->assertNoPiso($conta); // antes: −R$ 900,00
        $this->assertNotNull($salario->fresh());
    }

    public function test_baixar_o_valor_da_receita_ja_gasta_nao_fura_o_piso(): void
    {
        $conta = $this->conta();
        $salario = $this->receita($conta, '1.000,00');
        $this->despesa($conta, '900,00');

        $erro = $this->erro($this->editarReceita($salario, '100,00'));

        $this->assertNoPiso($conta); // antes: −R$ 800,00
        $this->assertSame(1000.0, (float) $salario->fresh()->amount, 'A edição recusada não gravou nada.');
        $this->assertStringContainsString('R$ 100,00', $erro);
        $this->assertStringContainsString('−R$ 800,00', $erro);
    }

    public function test_mudar_a_receita_ja_gasta_de_conta_nao_fura_o_piso(): void
    {
        $conta = $this->conta(nome: 'Corrente');
        $outra = $this->conta(nome: 'Poupança');
        $salario = $this->receita($conta, '1.000,00');
        $this->despesa($conta, '1.000,00');

        $this->erro($this->editarReceita($salario, '1.000,00', $outra));

        $this->assertNoPiso($conta); // antes: −R$ 1.000,00 na corrente
        $this->assertSame($conta->id, $salario->fresh()->account_id);
    }

    /** Receita virando DESPESA em OUTRA conta: a antiga perde a receita sem passar pela trava. */
    public function test_virar_despesa_em_outra_conta_tambem_confere_a_conta_antiga(): void
    {
        $conta = $this->conta(nome: 'Corrente');
        $outra = $this->conta(5000, 'Poupança');
        $salario = $this->receita($conta, '1.000,00');
        $this->despesa($conta, '1.000,00');

        $this->erro($this->editarReceita($salario, '50,00', $outra, 'expense'));

        $this->assertNoPiso($conta);
        $this->assertSame('income', $salario->fresh()->type);
    }

    public function test_excluir_transferencia_ja_gasta_no_destino_nao_fura_o_piso(): void
    {
        $origem = $this->conta(1000, 'Corrente');
        $destino = $this->conta(0, 'Poupança');

        $this->post(route('transactions.transfer'), [
            'amount' => '1.000,00', 'account_id' => $origem->id, 'to_account_id' => $destino->id,
            'date' => '2026-09-05',
        ])->assertSessionHasNoErrors();
        $this->despesa($destino, '1.000,00');
        $entrada = Transaction::whereNotNull('transfer_group_id')->where('type', 'income')->sole();

        $erro = $this->erro($this->excluir($entrada));

        $this->assertNoPiso($destino); // antes: −R$ 1.000,00 na poupança
        $this->assertSame(2, Transaction::whereNotNull('transfer_group_id')->count(), 'As duas pontas ficam.');
        $this->assertStringContainsString('Poupança', $erro);
    }

    /** A outra porta: a rota de Pagar despesas apaga qualquer linha pela URL. */
    public function test_pela_url_de_pagar_despesas_tambem_nao_fura_o_piso(): void
    {
        $conta = $this->conta();
        $salario = $this->receita($conta, '1.000,00');
        $this->despesa($conta, '900,00');

        $this->erro($this->from(route('faturas.index'))->delete(route('faturas.compra.destroy', $salario)));

        $this->assertNoPiso($conta);
        $this->assertNotNull($salario->fresh());
    }

    // ================================================================ resgate que cobriu o vermelho

    /**
     * Corrente com cheque especial de 500 e R$ 1.000 num CDB (disponível 0). A conta vai a
     * −300 no cheque especial; a despesa de 200 escolhe o resgate, que traz o FALTANTE — 500,
     * incluindo os 300 que ela já devia. Depois a conta volta ao piso (−500) no cheque.
     *
     * @return array{0: Account, 1: Transaction} [a conta, a despesa paga com resgate]
     */
    private function despesaCujoResgateCobriuOVermelho(): array
    {
        $conta = $this->conta(1000, cheque: 500);
        $cdb = Investment::create([
            'user_id' => $this->user->id, 'name' => 'CDB', 'classe' => 'renda_fixa', 'indexador' => 'cdi', 'taxa' => 100,
        ]);
        $cdb->contributions()->create([
            'account_id' => $conta->id, 'type' => 'aporte', 'amount' => 1000, 'date' => '2026-09-01',
        ]);

        $this->despesa($conta, '300,00', ['funding_source' => FundingSource::CHEQUE_ESPECIAL]);
        $this->despesa($conta, '200,00', [
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO, 'funding_investment_id' => $cdb->id,
        ]);
        $paga = Transaction::latest('id')->firstOrFail();
        $this->assertSame(500.0, (float) $paga->funding_amount, 'O resgate trouxe o faltante, com o vermelho de antes.');
        $this->assertSame(0.0, $this->disponivel($conta));

        $this->despesa($conta, '500,00', ['funding_source' => FundingSource::CHEQUE_ESPECIAL]);
        $this->assertSame(-500.0, $this->disponivel($conta), 'No piso, e só.');

        return [$conta, $paga];
    }

    public function test_excluir_despesa_cujo_resgate_cobriu_o_vermelho_nao_fura_o_piso(): void
    {
        [$conta, $paga] = $this->despesaCujoResgateCobriuOVermelho();

        $erro = $this->erro($this->excluir($paga));

        // Antes: +200 da despesa, −500 do resgate estornado = −R$ 800,00 com limite de 500.
        $this->assertNoPiso($conta);
        $this->assertNotNull($paga->fresh());
        $this->assertSame(1, InvestmentContribution::where('transaction_id', $paga->id)->count(), 'O resgate continua ligado.');
        $this->assertStringContainsString('−R$ 800,00', $erro);
        $this->assertStringContainsString('R$ 500,00', $erro, 'Cita o limite que seria furado.');
    }

    public function test_pela_url_de_pagar_despesas_a_despesa_com_resgate_tambem_nao_fura_o_piso(): void
    {
        [$conta, $paga] = $this->despesaCujoResgateCobriuOVermelho();

        $this->erro($this->from(route('faturas.index'))->delete(route('faturas.compra.destroy', $paga)));

        $this->assertNoPiso($conta);
        $this->assertNotNull($paga->fresh());
    }

    /** O caso comum — resgate só do que a despesa precisava — continua saindo, como sempre. */
    public function test_despesa_com_resgate_do_proprio_valor_sai_normalmente(): void
    {
        $conta = $this->conta(1000);
        $cdb = Investment::create([
            'user_id' => $this->user->id, 'name' => 'CDB', 'classe' => 'renda_fixa', 'indexador' => 'cdi', 'taxa' => 100,
        ]);
        $cdb->contributions()->create([
            'account_id' => $conta->id, 'type' => 'aporte', 'amount' => 800, 'date' => '2026-09-01',
        ]);
        $this->despesa($conta, '500,00', [
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO, 'funding_investment_id' => $cdb->id,
        ]);
        $paga = Transaction::latest('id')->firstOrFail();

        $this->excluir($paga)->assertSessionHasNoErrors();

        $this->assertNull($paga->fresh());
        $this->assertSame(0, InvestmentContribution::where('transaction_id', $paga->id)->count());
        $this->assertSame(200.0, $this->disponivel($conta), 'De volta ao que era antes da despesa.');
    }

    // ================================================================ o que continua livre

    public function test_receita_que_nao_foi_gasta_sai_normalmente(): void
    {
        // 1.000 de saldo inicial + 1.000 de receita − 900 de despesa = 1.100: sem a
        // receita, a conta fica em 100 — acima do piso.
        $conta = $this->conta(1000);
        $salario = $this->receita($conta, '1.000,00');
        $this->despesa($conta, '900,00');
        $this->assertSame(1100.0, $this->disponivel($conta));

        $this->excluir($salario)->assertSessionHasNoErrors();

        $this->assertNull($salario->fresh());
        $this->assertSame(100.0, $this->disponivel($conta));
    }

    /** Como no F-4: dentro do cheque especial, passa — o piso é −limite. */
    public function test_dentro_do_cheque_especial_a_receita_sai(): void
    {
        $conta = $this->conta(cheque: 1000);
        $salario = $this->receita($conta, '1.000,00');
        $this->despesa($conta, '1.000,00');

        $this->excluir($salario)->assertSessionHasNoErrors();

        $this->assertSame(-1000.0, $this->disponivel($conta), 'Exatamente no piso: aceito.');
    }

    public function test_aumentar_a_receita_ou_baixar_sem_passar_do_piso_passa(): void
    {
        $conta = $this->conta();
        $salario = $this->receita($conta, '1.000,00');
        $this->despesa($conta, '900,00');

        $this->editarReceita($salario, '1.500,00')->assertSessionHasNoErrors();
        $this->assertSame(600.0, $this->disponivel($conta));

        $this->editarReceita($salario, '900,00')->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->disponivel($conta), 'Baixar até exatamente o piso é legítimo.');
    }

    public function test_mudar_de_conta_a_receita_que_nao_foi_gasta_passa(): void
    {
        // 300 de saldo inicial + 1.000 de receita − 300 de despesa: sem a receita, a
        // corrente fica em 0 — exatamente no piso, o que é legítimo.
        $conta = $this->conta(300, 'Corrente');
        $outra = $this->conta(nome: 'Poupança');
        $salario = $this->receita($conta, '1.000,00');
        $this->despesa($conta, '300,00');

        $this->editarReceita($salario, '1.000,00', $outra)->assertSessionHasNoErrors();

        $this->assertSame($outra->id, $salario->fresh()->account_id);
        $this->assertSame(0.0, $this->disponivel($conta));
        $this->assertSame(1000.0, $this->disponivel($outra));
    }

    /** Receita virando despesa na MESMA conta continua com a trava de sempre (o `spend`, 1.6). */
    public function test_virar_despesa_na_mesma_conta_segue_pela_trava_de_despesa(): void
    {
        $conta = $this->conta(100);
        $salario = $this->receita($conta, '500,00');

        $this->editarReceita($salario, '600,00', tipo: 'expense')->assertSessionHasErrors('amount');

        $this->assertSame('income', $salario->fresh()->type);
        $this->assertSame(600.0, $this->disponivel($conta));
    }

    /** Receita num cartão é estorno: não há piso de saldo ali — excluir segue livre. */
    public function test_estorno_no_cartao_nao_e_afetado(): void
    {
        $cartao = Account::factory()->for($this->user)->creditCard()->create();
        $estorno = Transaction::factory()->for($this->user)->for($cartao)->income()->create([
            'amount' => 300, 'date' => '2026-09-12',
        ]);

        $this->excluir($estorno)->assertSessionHasNoErrors();

        $this->assertNull($estorno->fresh());
    }

    public function test_transferencia_cujo_destino_ainda_tem_o_dinheiro_sai_normalmente(): void
    {
        $origem = $this->conta(1000, 'Corrente');
        $destino = $this->conta(0, 'Poupança');

        $this->post(route('transactions.transfer'), [
            'amount' => '1.000,00', 'account_id' => $origem->id, 'to_account_id' => $destino->id,
            'date' => '2026-09-05',
        ])->assertSessionHasNoErrors();
        $saida = Transaction::whereNotNull('transfer_group_id')->where('type', 'expense')->sole();

        $this->excluir($saida)->assertSessionHasNoErrors();

        $this->assertSame(0, Transaction::whereNotNull('transfer_group_id')->count());
        $this->assertSame(1000.0, $this->disponivel($origem));
        $this->assertSame(0.0, $this->disponivel($destino));
    }
}
