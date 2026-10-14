<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Parcelamento;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Compra no cartão com data no PASSADO (13/10/2026 — pedido do Victor: "cadastrei uma data
 * retroativa do Mercado Pago e ela já veio como vencida"). Com "Já paguei essa fatura fora do
 * app" (a caixa da tela, marcada), o que cai numa fatura que JÁ VENCEU entra pago, no vencimento
 * dela, sem saída de caixa nenhuma; o resto fica em aberto, como sempre.
 */
class CompraComDataNoPassadoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $corrente;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(new \DateTimeImmutable('2026-10-13 10:00:00'));
        $this->user = User::factory()->create();
        $this->corrente = Account::factory()->for($this->user)->create(['type' => 'checking', 'name' => 'Itaú', 'bank' => 'itau', 'initial_balance' => 1000]);
        // Fecha dia 2, vence dia 9: a fatura que fechou em 02/10 venceu em 09/10 (já passou).
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create([
            'name' => 'Mercado Pago', 'bank' => 'mercado_pago', 'closing_day' => 2, 'due_day' => 9, 'credit_limit' => 400,
        ]);
    }

    private function lancar(array $campos)
    {
        return $this->actingAs($this->user)->post(route('transactions.store'), $campos + [
            'type' => 'expense', 'account_id' => $this->cartao->id, 'description' => 'Celular',
        ]);
    }

    public function test_o_corte_e_o_fechamento_da_fatura_vencida_mais_recente(): void
    {
        $this->assertSame('2026-10-02', $this->cartao->corteDasFaturasVencidas()->toDateString());
        // Dia 05/10 a fatura de 02/10 ainda não venceu (vence dia 9): o corte é a de 02/09.
        $this->assertSame('2026-09-02', $this->cartao->corteDasFaturasVencidas(CarbonImmutable::parse('2026-10-05'))->toDateString());
        $this->assertNull($this->corrente->corteDasFaturasVencidas());
    }

    public function test_parcelado_antigo_entra_com_as_faturas_vencidas_pagas_e_o_resto_em_aberto(): void
    {
        $this->lancar(['amount' => '600,00', 'date' => '2026-07-10', 'installments' => 6, 'faturas_vencidas_pagas' => '1'])
            ->assertSessionHasNoErrors();

        $parcelas = Transaction::orderBy('installment_no')->get();
        $this->assertCount(6, $parcelas);
        $this->assertSame([true, true, true, false, false, false], $parcelas->pluck('pago_fora_do_app')->all());
        $this->assertSame(['2026-08-09', '2026-09-09', '2026-10-09'], $parcelas->take(3)->map(fn ($p) => $p->paid_at->toDateString())->all());
        $this->assertTrue($parcelas->slice(3)->every(fn ($p) => $p->paid_at === null));

        $cartao = $this->cartao->fresh();
        $this->assertSame(300.0, $cartao->committed, 'Só as parcelas em aberto pesam no limite.');
        $this->assertNull($cartao->closedInvoice, 'Nenhuma fatura vencida.');
        $this->assertSame(1000.0, $this->corrente->fresh()->available, 'Nada sai de conta nenhuma.');
        $this->assertSame(0, Transaction::whereNotNull('settles_account_id')->count());
    }

    public function test_sem_a_caixa_continua_como_antes_e_o_limite_confere_o_total(): void
    {
        // Limite 400: a compra de 600 inteira não cabe.
        $this->lancar(['amount' => '600,00', 'date' => '2026-07-10', 'installments' => 6])
            ->assertSessionHasErrors('amount');

        // Com a caixa, só os 300 em aberto pesam — cabe.
        $this->lancar(['amount' => '600,00', 'date' => '2026-07-10', 'installments' => 6, 'faturas_vencidas_pagas' => '1'])
            ->assertSessionHasNoErrors();
    }

    public function test_sem_a_caixa_a_compra_antiga_aparece_vencida(): void
    {
        $this->lancar(['amount' => '100,00', 'date' => '2026-08-15'])->assertSessionHasNoErrors();

        $this->assertNull(Transaction::first()->paid_at);
        $this->assertTrue($this->cartao->fresh()->closedInvoice['vencida']);
    }

    public function test_a_vista_antiga_pelo_contas_a_pagar_tambem(): void
    {
        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'description' => 'Tênis', 'amount' => '150,00', 'date' => '2026-08-15',
            'account_id' => $this->cartao->id, 'mode' => 'avista', 'faturas_vencidas_pagas' => '1',
        ])->assertSessionHasNoErrors();

        $compra = Transaction::firstOrFail();
        $this->assertTrue($compra->pago_fora_do_app);
        $this->assertSame('2026-09-09', $compra->paid_at->toDateString());
        $this->assertSame(0.0, $this->cartao->fresh()->committed);
    }

    public function test_compra_de_hoje_nao_e_tocada_mesmo_com_a_caixa(): void
    {
        $this->lancar(['amount' => '50,00', 'date' => '2026-10-13', 'faturas_vencidas_pagas' => '1'])->assertSessionHasNoErrors();

        $this->assertNull(Transaction::first()->paid_at);
        $this->assertFalse(Transaction::first()->pago_fora_do_app);
        $this->assertSame([], Parcelamento::pagaForaDoApp($this->cartao, CarbonImmutable::parse('2026-10-03')), 'Fatura que fecha em 02/11: em aberto.');
    }

    public function test_receita_na_conta_ignora_a_caixa(): void
    {
        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'income', 'account_id' => $this->corrente->id, 'amount' => '10,00', 'date' => '2026-07-10',
            'faturas_vencidas_pagas' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(Transaction::first()->pago_fora_do_app);
        $this->assertNull(Transaction::first()->paid_at);
    }

    public function test_a_paga_fora_do_app_se_exclui_e_so_muda_o_que_nao_mexe_em_dinheiro(): void
    {
        $this->lancar(['amount' => '100,00', 'date' => '2026-08-15', 'faturas_vencidas_pagas' => '1'])->assertSessionHasNoErrors();
        $compra = Transaction::firstOrFail();

        // Descrição muda; data e cartão não.
        $campos = ['type' => 'expense', 'amount' => '100,00', 'account_id' => $this->cartao->id, 'date' => '2026-08-15'];
        $this->actingAs($this->user)->put(route('transactions.update', $compra), $campos + ['description' => 'Celular novo'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Celular novo', $compra->fresh()->description);

        $this->actingAs($this->user)->from(route('transactions.edit', $compra))
            ->put(route('transactions.update', $compra), ['date' => '2026-10-10'] + $campos)
            ->assertSessionHasErrors('transaction');
        $this->assertSame('2026-08-15', $compra->fresh()->date->toDateString());

        // Excluir: não há pagamento no extrato a deixar sem origem.
        $this->actingAs($this->user)->delete(route('transactions.destroy', $compra))->assertSessionHasNoErrors();
        $this->assertSame(0, Transaction::count());
        $this->assertSame(1000.0, $this->corrente->fresh()->available);
    }

    public function test_a_tela_oferece_a_caixa_so_para_o_cartao(): void
    {
        $html = $this->actingAs($this->user)->get(route('transactions.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<option value="'.$this->cartao->id.'"[^>]*data-corte-vencidas="2026-10-02"#', $html);
        $this->assertDoesNotMatchRegularExpression('#<option value="'.$this->corrente->id.'"[^>]*data-corte-vencidas#', $html);
        $this->assertStringContainsString('data-vencidas-pagas data-conta="account_id" data-data="date"', $html);
        $this->assertMatchesRegularExpression('#name="faturas_vencidas_pagas" value="1" checked disabled#', $html);

        // O modal Lançar (no shell) também — e ele é o "Lançar despesa" de Contas a pagar (14/10/2026).
        $this->assertStringContainsString('data-conta="lm-account" data-data="lm-date"', $html);
        $faturas = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-conta="lm-account" data-data="lm-date"', $faturas);
    }
}
