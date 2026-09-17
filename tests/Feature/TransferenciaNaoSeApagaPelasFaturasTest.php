<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SidebarService;
use App\Support\FundingSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * R2-1 da auditoria financeira (rodada 2, 05/09/2026): `faturas.compra.destroy`
 * apagava UMA ponta de uma transferência entre contas.
 *
 * Uma transferência são duas linhas ligadas por `transfer_group_id` — a saída na
 * origem e a entrada no destino. O Histórico (`TransactionController::destroy`) já
 * apagava as duas juntas; a rota da tela Pagar despesas, não: apagar a entrada
 * sumia com R$ 500 do patrimônio (e o resgate que financiou a saída ficava de pé),
 * apagar a saída criava R$ 500. A tela não lista as pontas, mas a rota aceita
 * qualquer id da família — e o id aparece no Histórico.
 *
 * A rota agora RECUSA e aponta o Histórico, que é o único caminho que desfaz uma
 * transferência. O invariante verificado: o patrimônio da família nunca muda por
 * uma ponta só.
 */
class TransferenciaNaoSeApagaPelasFaturasTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private Account $corrente;

    private Account $poupanca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $this->corrente = Account::factory()->for($this->titular)->create([
            'name' => 'Corrente', 'type' => 'checking', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
        $this->poupanca = Account::factory()->for($this->titular)->create([
            'name' => 'Poupança', 'type' => 'savings', 'initial_balance' => 1000,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function transferir(array $extra = []): TestResponse
    {
        return $this->actingAs($this->titular)->postJson(route('transactions.transfer'), array_merge([
            'amount' => '500,00',
            'account_id' => $this->corrente->id,
            'to_account_id' => $this->poupanca->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ], $extra));
    }

    private function saida(): Transaction
    {
        return Transaction::whereNotNull('transfer_group_id')->where('type', 'expense')->firstOrFail();
    }

    private function entrada(): Transaction
    {
        return Transaction::whereNotNull('transfer_group_id')->where('type', 'income')->firstOrFail();
    }

    private function apagarPelasFaturas(Transaction $ponta): TestResponse
    {
        return $this->actingAs($this->titular)
            ->from(route('faturas.index'))
            ->delete(route('faturas.compra.destroy', $ponta));
    }

    /** Patrimônio, investido e disponível da família, como a sidebar mostra. */
    private function retrato(): array
    {
        $s = app(SidebarService::class)->build($this->titular->id);

        return [
            'patrimonio' => $s['saldoTotal'],
            'investido' => $s['investido'],
            'disponivel' => $s['disponivel'],
            'corrente' => $this->corrente->fresh()->available,
            'poupanca' => $this->poupanca->fresh()->available,
            'linhas' => Transaction::count(),
        ];
    }

    private function assertRecusadoApontandoOHistorico(TestResponse $r): void
    {
        $r->assertRedirect(route('faturas.index'))->assertSessionHasErrors('transaction');
        $this->assertStringContainsString('Histórico', session('errors')->first('transaction'));
    }

    public function test_apagar_a_entrada_pelas_faturas_e_recusado_e_o_patrimonio_nao_muda(): void
    {
        $this->transferir()->assertCreated();
        $antes = $this->retrato();
        $this->assertSame(2000.0, $antes['patrimonio']);
        $this->assertSame(500.0, $antes['corrente']);
        $this->assertSame(1500.0, $antes['poupanca']);

        $r = $this->apagarPelasFaturas($this->entrada());

        // O dinheiro antes da mensagem: é a conta que tem de fechar.
        $this->assertSame($antes, $this->retrato(), 'Apagar só a entrada sumia com R$ 500 do patrimônio.');
        $this->assertRecusadoApontandoOHistorico($r);
    }

    public function test_apagar_a_saida_pelas_faturas_e_recusado_e_o_patrimonio_nao_muda(): void
    {
        $this->transferir()->assertCreated();
        $antes = $this->retrato();

        $r = $this->apagarPelasFaturas($this->saida());

        $this->assertSame($antes, $this->retrato(), 'Apagar só a saída criava R$ 500 do nada.');
        $this->assertRecusadoApontandoOHistorico($r);
    }

    /**
     * O caso mais caro: a saída foi financiada por RESGATE de investimento. Apagar a
     * entrada deixava o resgate de pé (o aplicado encolhia sem contrapartida); apagar
     * a saída estornava o resgate e ainda deixava a entrada inflando o destino.
     */
    public function test_transferencia_financiada_por_resgate_fica_intacta_pelas_duas_pontas(): void
    {
        $inv = Investment::create([
            'user_id' => $this->titular->id,
            'name' => 'CDB',
            'classe' => 'renda_fixa',
            'indexador' => 'cdi',
            'taxa' => 100,
        ]);
        $inv->contributions()->create([
            'account_id' => $this->corrente->id,
            'made_by_user_id' => $this->titular->id,
            'type' => 'aporte',
            'amount' => 800,
            'date' => CarbonImmutable::today()->subMonth()->toDateString(),
        ]);

        // Disponível da corrente = 1.000 − 800 aplicados = 200: faltam 300.
        $uuid = (string) Str::uuid();
        $this->transferir(['client_uuid' => $uuid])->assertStatus(409);
        $this->transferir([
            'client_uuid' => $uuid,
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $inv->id,
        ])->assertCreated();

        $resgate = $inv->contributions()->where('type', 'resgate')->firstOrFail();
        $this->assertSame($this->saida()->id, $resgate->transaction_id);
        $this->assertSame(500.0, $inv->fresh()->aplicado);

        $antes = $this->retrato();
        $this->assertSame(2000.0, $antes['patrimonio']);

        $r = $this->apagarPelasFaturas($this->entrada());
        $this->assertSame($antes, $this->retrato(), 'Pela entrada: o destino perdia R$ 500 e o resgate ficava de pé.');
        $this->assertRecusadoApontandoOHistorico($r);

        $r = $this->apagarPelasFaturas($this->saida());
        $this->assertSame($antes, $this->retrato(), 'Pela saída: o resgate era estornado e a entrada seguia inflando o destino.');
        $this->assertRecusadoApontandoOHistorico($r);

        $this->assertSame(500.0, $inv->fresh()->aplicado, 'O resgate que financiou a saída continua ligado a ela.');
        $this->assertNotNull($resgate->fresh());
    }

    /** A saída que a mensagem aponta existe de verdade: pelo Histórico, as duas pontas saem juntas. */
    public function test_depois_da_recusa_o_historico_desfaz_a_transferencia_inteira(): void
    {
        $this->transferir()->assertCreated();
        $this->apagarPelasFaturas($this->entrada())->assertSessionHasErrors('transaction');

        $this->actingAs($this->titular)
            ->delete(route('transactions.destroy', $this->entrada()))
            ->assertRedirect(route('transactions.index'));

        $this->assertSame(0, Transaction::count());
        $this->assertSame(1000.0, $this->corrente->fresh()->available);
        $this->assertSame(1000.0, $this->poupanca->fresh()->available);
        $this->assertSame(2000.0, app(SidebarService::class)->build($this->titular->id)['saldoTotal']);
    }
}
