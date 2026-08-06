<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card "Contas a pagar" do dashboard — ele escondia dívida real (revisão de 06/08/2026).
 *
 * Dois defeitos independentes, os dois fazendo o card discordar do sino da MESMA tela:
 *
 * 1. A dívida do cartão era medida só por `openInvoiceDue` (ciclo ABERTO), então a
 *    fatura já fechada e não paga — que rola para os meses seguintes — não entrava no
 *    total, nem na lista, nem no vencimento exibido.
 * 2. O card era escondido por um contador que só enxergava cartões, enquanto o total
 *    já somava contas fixas: quem devia três aluguéis e não tinha cartão via
 *    "Nada a pagar 🎉".
 *
 * Cada teste aqui FALHAVA antes da correção.
 */
class DashboardContasAPagarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $corrente;

    private Category $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        // Data fixa: os cenários dependem de onde hoje cai em relação ao fechamento
        // do cartão (dia 10) e ao vencimento das contas fixas.
        $this->travelTo(CarbonImmutable::parse('2026-08-06'));

        $this->user = User::factory()->create(['is_admin' => true]);

        $this->corrente = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'initial_balance' => 5000,
            'overdraft_limit' => 0,
        ]);

        $this->categoria = Category::factory()->for($this->user)->expense()->create();

        $this->actingAs($this->user);
    }

    /** Cartão fecha dia 10 e vence dia 20 — com hoje em 06/08, o ciclo aberto é (10/07, 10/08]. */
    private function cartao(): Account
    {
        return Account::factory()->for($this->user)->create([
            'type' => 'credit_card',
            'initial_balance' => null,
            'credit_limit' => 20000,
            'closing_day' => 10,
            'due_day' => 20,
        ]);
    }

    private function compra(Account $cartao, string $data, float $valor, ?string $pagaEm = null): Transaction
    {
        return Transaction::factory()->for($this->user)->create([
            'account_id' => $cartao->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => $valor,
            'date' => $data,
            'paid_at' => $pagaEm,
        ]);
    }

    private function resumo(): array
    {
        return app(DashboardService::class)->build($this->user->ownerId())['faturasResumo'];
    }

    /**
     * DEFEITO 1 — a compra de 05/06 não paga caiu na janela FECHADA quando o ciclo
     * virou. Medindo só o ciclo aberto, o card mostrava R$ 0,00 enquanto o sino
     * anunciava "vencida há 47 dias".
     */
    public function test_fatura_ja_fechada_e_nao_paga_entra_no_total(): void
    {
        $cartao = $this->cartao();
        $this->compra($cartao, '2026-06-05', 2000.00);

        $resumo = $this->resumo();

        $this->assertSame(
            2000.00,
            round((float) $resumo['total'], 2),
            'O card mede a dívida do cartão só pelo ciclo aberto: a fatura já fechada '
                .'e não paga some da tela, mas continua sendo devida.',
        );
        $this->assertSame(1, $resumo['count']);
    }

    /**
     * DEFEITO 1 (exibição) — o vencimento mostrado vinha do ciclo ABERTO, então uma
     * dívida vencida em 20/06 aparecia como "Vence 20/08", em dia.
     */
    public function test_vencimento_exibido_e_o_da_fatura_mais_antiga_em_aberto(): void
    {
        $cartao = $this->cartao();
        $this->compra($cartao, '2026-06-05', 2000.00);

        $item = $this->resumo()['top'][0];

        $this->assertSame('20/06', $item['due'], 'O vencimento exibido é o do ciclo aberto, não o da dívida real.');
        $this->assertTrue($item['vencida'], 'A fatura venceu em 20/06 e o card não a marca como vencida.');
    }

    /**
     * DEFEITO 2 — sem cartão nenhum, o contador zerava e o card caía no estado vazio,
     * mesmo com o total já somando as contas fixas. O usuário via "Nada a pagar 🎉"
     * devendo três aluguéis.
     */
    public function test_contas_fixas_sozinhas_renderizam_o_card(): void
    {
        FixedBill::create([
            'user_id' => $this->user->id,
            'name' => 'Aluguel',
            'amount' => 1800.00,
            'due_day' => 5,
            'account_id' => $this->corrente->id,
            'category_id' => $this->categoria->id,
            'starts_on' => '2026-06-01',
            'active' => true,
        ]);

        $resumo = $this->resumo();

        $this->assertGreaterThan(0, $resumo['count'], 'O contador só enxerga cartões, então o card com contas fixas nunca renderiza.');
        $this->assertGreaterThan(0.0, round((float) $resumo['total'], 2));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Nada a pagar')
            ->assertSee('Aluguel');
    }

    /**
     * As duas janelas do cartão são DISJUNTAS (a fechada termina no início do ciclo
     * aberto; a aberta começa logo depois), então somá-las não pode contar a mesma
     * compra duas vezes.
     */
    public function test_compra_do_ciclo_aberto_conta_uma_vez_so(): void
    {
        $cartao = $this->cartao();
        $this->compra($cartao, '2026-08-01', 500.00);

        $this->assertSame(500.00, round((float) $this->resumo()['total'], 2));
    }

    /** Cartão com dívida nas DUAS janelas: o total é a soma, sem sobreposição. */
    public function test_soma_o_ciclo_aberto_com_o_ja_fechado(): void
    {
        $cartao = $this->cartao();
        $this->compra($cartao, '2026-06-05', 2000.00);   // fechada e vencida
        $this->compra($cartao, '2026-08-01', 500.00);    // ciclo aberto

        $resumo = $this->resumo();

        $this->assertSame(2500.00, round((float) $resumo['total'], 2));
        $this->assertSame(1, $resumo['count'], 'Um cartão é UMA linha na lista, mesmo devendo em dois ciclos.');
    }

    /**
     * REGRESSÃO (achado CRÍTICA-2 da auditoria de 28/07): fatura já quitada não pode
     * voltar a ser cobrada. `paid_at` sai das duas janelas.
     */
    public function test_fatura_paga_nao_volta_a_ser_cobrada(): void
    {
        $cartao = $this->cartao();
        $this->compra($cartao, '2026-06-05', 2000.00, '2026-06-18 10:00:00');
        $this->compra($cartao, '2026-08-01', 500.00, '2026-08-02 10:00:00');

        $resumo = $this->resumo();

        $this->assertSame(0.0, round((float) $resumo['total'], 2));
        $this->assertSame(0, $resumo['count']);

        $this->get(route('dashboard'))->assertOk()->assertSee('Nada a pagar');
    }
}
