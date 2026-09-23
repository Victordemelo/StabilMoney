<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use App\Support\FundingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * R2-7 e R2-8 da auditoria de 02/09/2026 (rodada 2): a data do resgate que cobre uma
 * despesa.
 *
 * - R2-8: o resgate nascia com a data da despesa, então uma despesa lançada ADIANTADA
 *   (datada em setembro, lançada em agosto) gravava um resgate no FUTURO — a "sexta
 *   porta" de data futura, que as cinco portas de aporte/resgate recusam. O `aplicado`
 *   do investimento (que não olha data) caía hoje, e a spark do saldo (que olha)
 *   discordava do stat.
 * - R2-7: editar SÓ a data de uma despesa financiada (edição neutra, que preserva o
 *   resgate) deixava o resgate na data antiga. A spark mostrava a saída num dia e a
 *   reposição em outro — um vermelho que a conta nunca teve entre as duas datas.
 *
 * Regra única, na criação e na edição: a data do resgate é a MENOR entre a data da
 * despesa e hoje. Na edição, o resgate acompanha também o AUTOR da despesa.
 */
class ResgateAcompanhaADataDaDespesaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Investment $cdb;

    protected function setUp(): void
    {
        parent::setUp();
        // Quarta-feira: a janela da spark é 06..12/08.
        Carbon::setTestNow('2026-08-12 10:00:00');

        $this->user = User::factory()->create(['is_admin' => true]);
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);

        // R$ 800 aplicados ANTES da janela: disponível R$ 200.
        $this->cdb = Investment::factory()->for($this->user)->create(['name' => 'CDB']);
        $this->cdb->contributions()->create([
            'account_id' => $this->conta->id, 'type' => 'aporte', 'amount' => 800, 'date' => '2026-08-01',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Despesa de R$ 500 que só cabe resgatando R$ 300. */
    private function lancarComResgate(string $data, float $valor = 500, ?Account $conta = null): Transaction
    {
        $conta ??= $this->conta;

        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => number_format($valor, 2, ',', '.'),
            'account_id' => $conta->id,
            'date' => $data,
            'description' => 'Conserto do carro',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $this->cdb->id,
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function editar(Transaction $despesa, array $mudancas): TestResponse
    {
        return $this->actingAs($this->user)->from(route('transactions.index'))
            ->put(route('transactions.update', $despesa), $mudancas + [
                'type' => 'expense',
                'amount' => number_format((float) $despesa->amount, 2, ',', '.'),
                'account_id' => $despesa->account_id,
                'date' => $despesa->date->toDateString(),
                'description' => $despesa->description,
            ]);
    }

    private function resgateDe(Transaction $despesa): InvestmentContribution
    {
        return InvestmentContribution::where('transaction_id', $despesa->id)->sole();
    }

    private function sparkDoSaldo(): array
    {
        return app(DashboardService::class)->build($this->user->id)['payload']['sparks']['saldo'];
    }

    // ================= R2-8 · na criação =================

    /** O cenário do relatório: despesa adiantada gravava um resgate no futuro. */
    public function test_despesa_datada_no_futuro_grava_o_resgate_com_a_data_de_hoje(): void
    {
        $despesa = $this->lancarComResgate('2026-09-01');

        $resgate = $this->resgateDe($despesa);
        $this->assertSame(300.0, (float) $resgate->amount);
        $this->assertSame('2026-08-12', $resgate->date->toDateString(), 'Resgate nunca nasce no futuro.');
        // O aplicado cai hoje — e agora a data diz a mesma coisa.
        $this->assertSame(500.0, $this->cdb->fresh()->aplicado);
    }

    /** No passado (ou hoje), o resgate fica no dia da despesa, como sempre. */
    public function test_despesa_datada_no_passado_grava_o_resgate_na_data_dela(): void
    {
        $despesa = $this->lancarComResgate('2026-08-05');

        $this->assertSame('2026-08-05', $this->resgateDe($despesa)->date->toDateString());
    }

    // ================= R2-7 · na edição neutra =================

    /** O cenário do relatório: mudar só a data deixava o resgate para trás, e a spark inventava vermelho. */
    public function test_editar_so_a_data_leva_o_resgate_junto_e_a_spark_nao_inventa_vermelho(): void
    {
        $despesa = $this->lancarComResgate('2026-08-10');
        $this->assertSame([200.0, 200.0, 200.0, 200.0, 0.0, 0.0, 0.0], $this->sparkDoSaldo());

        $this->editar($despesa, ['date' => '2026-08-07'])->assertSessionHasNoErrors();

        $this->assertSame('2026-08-07', $despesa->fresh()->date->toDateString());
        $this->assertSame('2026-08-07', $this->resgateDe($despesa)->date->toDateString());
        // Saída e reposição no MESMO dia: a linha vai de 200 a 0 sem passar por −300.
        $this->assertSame([200.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $this->sparkDoSaldo());

        // A edição continua neutra: o resgate é o MESMO, com o mesmo valor.
        $this->assertSame(300.0, (float) $this->resgateDe($despesa)->amount);
        $this->assertSame(FundingSource::RESGATE_INVESTIMENTO, $despesa->fresh()->funding_source);
        $this->assertSame(500.0, $this->cdb->fresh()->aplicado);
    }

    /** Mudar a data para o futuro leva o resgate só até hoje (a mesma regra da criação). */
    public function test_editar_a_data_para_o_futuro_leva_o_resgate_so_ate_hoje(): void
    {
        $despesa = $this->lancarComResgate('2026-08-10');

        $this->editar($despesa, ['date' => '2026-09-01'])->assertSessionHasNoErrors();

        $this->assertSame('2026-09-01', $despesa->fresh()->date->toDateString());
        $this->assertSame('2026-08-12', $this->resgateDe($despesa)->date->toDateString());
    }

    /** Corrigir o autor corrige também quem aparece no resgate que a cobriu. */
    public function test_editar_so_o_autor_leva_o_autor_do_resgate_junto(): void
    {
        $filha = User::factory()->create(['account_owner_id' => $this->user->id, 'is_admin' => false]);
        $despesa = $this->lancarComResgate('2026-08-10');
        $this->assertSame($this->user->id, $this->resgateDe($despesa)->made_by_user_id);

        $this->editar($despesa, ['made_by_user_id' => $filha->id])->assertSessionHasNoErrors();

        $this->assertSame($filha->id, $despesa->fresh()->made_by_user_id);
        $this->assertSame($filha->id, $this->resgateDe($despesa)->made_by_user_id);
        $this->assertSame('2026-08-10', $this->resgateDe($despesa)->date->toDateString(), 'A data não mudou: o resgate fica onde estava.');
    }

    /**
     * Editar só a descrição não mexe no resgate — nem na data dele. Sem esta trava, uma
     * despesa adiantada teria o resgate empurrado para "hoje" a cada correção de texto.
     */
    public function test_editar_so_a_descricao_nao_mexe_na_data_do_resgate(): void
    {
        $despesa = $this->lancarComResgate('2026-09-01');
        $this->assertSame('2026-08-12', $this->resgateDe($despesa)->date->toDateString());

        Carbon::setTestNow('2026-08-15 10:00:00');
        $this->editar($despesa, ['description' => 'Conserto do carro (oficina)'])->assertSessionHasNoErrors();

        $this->assertSame('Conserto do carro (oficina)', $despesa->fresh()->description);
        $this->assertSame('2026-08-12', $this->resgateDe($despesa)->date->toDateString());
    }

    /** A reconciliação (valor mudou) recria o resgate — e ele também não nasce no futuro. */
    public function test_reconciliar_com_data_futura_tambem_nao_grava_resgate_no_futuro(): void
    {
        $despesa = $this->lancarComResgate('2026-08-10');

        $this->editar($despesa, [
            'amount' => '600,00',
            'date' => '2026-09-01',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $this->cdb->id,
        ])->assertSessionHasNoErrors();

        $resgate = $this->resgateDe($despesa);
        $this->assertSame(400.0, (float) $resgate->amount);
        $this->assertSame('2026-08-12', $resgate->date->toDateString());
    }

    /** Transferência coberta por resgate: a ponta de saída leva o resgate junto ao mudar de data. */
    public function test_transferencia_coberta_por_resgate_leva_o_resgate_junto_ao_mudar_a_data(): void
    {
        $poupanca = Account::factory()->for($this->user)->create([
            'type' => 'savings', 'name' => 'Poupança', 'initial_balance' => 0,
        ]);

        $this->actingAs($this->user)->post(route('transactions.transfer'), [
            'type' => 'transfer',
            'amount' => '500,00',
            'account_id' => $this->conta->id,
            'to_account_id' => $poupanca->id,
            'date' => '2026-08-10',
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $this->cdb->id,
        ])->assertSessionHasNoErrors();

        $saida = Transaction::where('account_id', $this->conta->id)->whereNotNull('transfer_group_id')->sole();
        $this->assertSame('2026-08-10', $this->resgateDe($saida)->date->toDateString());

        $this->editar($saida, ['date' => '2026-08-08', 'description' => ''])->assertSessionHasNoErrors();

        $this->assertSame('2026-08-08', $saida->fresh()->date->toDateString());
        $this->assertSame('2026-08-08', $saida->fresh()->contrapartida()->date->toDateString());
        $this->assertSame('2026-08-08', $this->resgateDe($saida)->date->toDateString());
    }
}
