<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CreditSettlement;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * R2-3, R2-4 e R2-5 da auditoria financeira (rodada 2): a fatura que um estorno cobre
 * EXATAMENTE — líquido zero — não tinha estado próprio.
 *
 *  - R2-5: o card dizia "Fatura paga" (bastava ter gasto no ciclo e nada a pagar), sem
 *    uma linha paga nem pagamento nenhum no extrato;
 *  - R2-3: não havia botão nenhum para encerrá-la, e as linhas nunca ganhavam
 *    `paid_at` — compra e estorno zerados de faturas antigas ficavam "em aberto" para
 *    sempre, prendendo no passado o piso do campo "data do pagamento";
 *  - R2-4: quitada pelo `payInvoice` (líquido 0), a compra não podia mais ser excluída:
 *    a trava mandava "estornar o pagamento", e não havia pagamento a estornar.
 *
 * O certo: a fatura COBERTA é dita como tal ("Coberta pelo estorno: nada a pagar"), tem
 * uma ação explícita ("Quitar pelo crédito") que marca as linhas pagas sem saída de
 * caixa e deixa um REGISTRO (`CreditSettlement`), e esse registro se desfaz ("Desfazer
 * quitação") como a quitação em caixa se estorna. O invariante verificado em todo passo:
 * nenhum caminho cria nem some com dinheiro — caixa, limite e fatura antes e depois.
 *
 * Cartão fecha dia 10 / vence dia 20; hoje = 20/09/2026 → ciclo aberto = (10/09, 10/10],
 * fechado = tudo até 10/09.
 */
class FaturaCobertaPeloEstornoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 10000, 'overdraft_limit' => 0,
        ]);
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create([
            'name' => 'Nubank', 'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================ cenário

    private function compra(string $data, float $valor, string $descricao = 'Compra'): Transaction
    {
        return Transaction::factory()->for($this->user)->for($this->cartao)->expense()->create([
            'amount' => $valor, 'date' => $data, 'description' => $descricao,
        ]);
    }

    private function estorno(string $data, float $valor, string $descricao = 'Estorno'): Transaction
    {
        return Transaction::factory()->for($this->user)->for($this->cartao)->income()->create([
            'amount' => $valor, 'date' => $data, 'description' => $descricao,
        ]);
    }

    /**
     * O cenário do R2-5: um estorno de agosto maior que as compras de agosto deixou
     * R$ 600 de crédito, e a compra de setembro é exatamente de R$ 600.
     */
    private function creditoFechadoCobrindoACompraDoMes(): void
    {
        $this->estorno('2026-08-15', 1000, 'Estorno do notebook');
        $this->compra('2026-08-16', 400, 'Mercado');
        $this->compra('2026-09-15', 600, 'Tênis');
    }

    /** Compra e estorno do mesmo valor no ciclo aberto. */
    private function compraEEstornoNoCiclo(): Transaction
    {
        $compra = $this->compra('2026-09-12', 300, 'Fone');
        $this->estorno('2026-09-15', 300, 'Estorno do fone');

        return $compra;
    }

    // ================================================================ ações

    private function quitarPeloCredito(?User $quem = null, ?int $cartaoId = null): TestResponse
    {
        return $this->actingAs($quem ?? $this->user)
            ->from(route('faturas.index'))
            ->post(route('faturas.fatura.quitar-pelo-credito', $cartaoId ?? $this->cartao->id));
    }

    private function desfazer(int $quitacaoId, ?User $quem = null): TestResponse
    {
        return $this->actingAs($quem ?? $this->user)
            ->from(route('faturas.index'))
            ->delete(route('faturas.fatura.desfazer-quitacao', $quitacaoId));
    }

    private function pagar(string $ciclo = 'aberto'): TestResponse
    {
        return $this->actingAs($this->user)
            ->from(route('faturas.index'))
            ->post(route('faturas.fatura.pagar', $this->cartao), [
                'pay_account_id' => $this->conta->id,
                'ciclo' => $ciclo,
            ]);
    }

    // ================================================================ leitura

    /** O card do cartão como a tela o recebe. */
    private function card(): array
    {
        return app(FaturaService::class)->build($this->user->id)['cards'][0]->toArray();
    }

    /**
     * Todo o dinheiro que um passo poderia mexer: o caixa (disponível), o limite do
     * cartão e as duas faturas, mais quantas saídas de caixa quitaram fatura.
     */
    private function dinheiro(): array
    {
        $cartao = Account::find($this->cartao->id);

        return [
            'caixa' => Account::find($this->conta->id)->available,
            'comprometido' => $cartao->committed,
            'limiteLivre' => $cartao->availableLimit,
            'aPagarNoAberto' => $cartao->openInvoiceDue,
            'aPagarNoFechado' => $cartao->closedInvoiceDue,
            'quitacoesEmCaixa' => Transaction::where('settles_account_id', $this->cartao->id)->count(),
        ];
    }

    private function linhasEmAberto(): int
    {
        return Transaction::where('account_id', $this->cartao->id)->whereNull('paid_at')->count();
    }

    private function erro(TestResponse $resposta): string
    {
        $resposta->assertSessionHasErrors('transaction');

        return (string) session('errors')->first('transaction');
    }

    // ================================================================ R2-5

    public function test_fatura_coberta_pelo_estorno_nao_aparece_como_paga(): void
    {
        $this->creditoFechadoCobrindoACompraDoMes();

        $card = $this->card();
        $this->assertSame('coberta', $card['estado']);
        $this->assertFalse($card['isPaid'], 'Antes: "Fatura paga" com nenhuma linha paga e nenhum pagamento.');
        $this->assertFalse($card['canPay'], 'Nada a pagar: o crédito cobre a compra inteira.');
        $this->assertSame(0.0, $card['invoiceDue']);
        $this->assertSame(600.0, $card['currentInvoice'], 'O gasto do ciclo continua sendo 600.');

        $this->actingAs($this->user)->get(route('faturas.index'))
            ->assertOk()
            ->assertSee('Coberta pelo estorno')
            ->assertSee('Quitar pelo crédito')
            ->assertDontSee('Fatura paga');
    }

    // ================================================================ R2-3

    public function test_quitar_pelo_credito_encerra_a_fatura_sem_mexer_em_dinheiro(): void
    {
        $this->creditoFechadoCobrindoACompraDoMes();

        $antes = $this->dinheiro();
        $this->assertSame([
            'caixa' => 10000.0,
            // As linhas se anulam (−1000 + 400 + 600): elas nunca comeram limite.
            'comprometido' => 0.0,
            'limiteLivre' => 5000.0,
            'aPagarNoAberto' => 0.0,
            'aPagarNoFechado' => 0.0,
            'quitacoesEmCaixa' => 0,
        ], $antes);
        $this->assertSame(3, $this->linhasEmAberto());
        // O piso do "data do pagamento" preso na compra mais antiga que ninguém quitou.
        $this->assertSame('2026-08-16', $this->card()['payFloor']);

        $this->quitarPeloCredito()
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'quitada pelo crédito') && str_contains($s, 'nada saiu da conta'));

        // O dinheiro antes de tudo: nada entrou nem saiu de lugar nenhum.
        $this->assertSame($antes, $this->dinheiro());

        // As três linhas saíram de "em aberto", todas apontando para o MESMO registro.
        $this->assertSame(0, $this->linhasEmAberto());
        $quitacao = CreditSettlement::sole();
        $this->assertSame($this->cartao->id, $quitacao->account_id);
        $this->assertSame($this->user->id, $quitacao->made_by_user_id);
        $this->assertSame(3, Transaction::where('credit_settlement_id', $quitacao->id)->count());
        $this->assertSame(0, Transaction::where('account_id', $this->cartao->id)->whereNotNull('settled_by_id')->count(),
            'Não houve pagamento em caixa: nenhuma linha aponta para quitação em caixa.');
        foreach (Transaction::where('account_id', $this->cartao->id)->get() as $linha) {
            $this->assertSame('2026-09-20', $linha->paid_at->toDateString());
        }

        // O card diz o que aconteceu — e não "Fatura paga".
        $card = $this->card();
        $this->assertSame('quitada_pelo_credito', $card['estado']);
        $this->assertFalse($card['isPaid']);
        $this->assertNull($card['payFloor'], 'Nada mais em aberto: o piso se solta do passado.');
        $this->assertTrue($quitacao->is($card['quitacaoPeloCredito']));

        $this->actingAs($this->user)->get(route('faturas.index'))
            ->assertOk()
            ->assertSee('Quitada pelo crédito do estorno')
            ->assertSee('Desfazer quitação')
            ->assertDontSee('Fatura paga')
            ->assertDontSee('Quitar pelo crédito');

        // O ciclo anda: a próxima compra é cobrada normalmente, e o piso é ela.
        $this->compra('2026-09-25', 100, 'Farmácia');
        $card = $this->card();
        $this->assertSame('a_pagar', $card['estado']);
        $this->assertSame(100.0, $card['invoiceDue']);
        $this->assertSame('2026-09-25', $card['payFloor']);
    }

    /**
     * Compra e estorno ZERADOS de uma fatura antiga não tinham botão nenhum: o fechado
     * só é oferecido com dívida, e o aberto só arrastava o fechado em crédito.
     */
    public function test_zerados_de_fatura_antiga_saem_de_aberto_junto_com_o_pagamento_do_mes(): void
    {
        $this->compra('2026-08-01', 500, 'Passagem');
        $this->estorno('2026-08-05', 500, 'Passagem cancelada');
        $this->compra('2026-09-15', 200, 'Mercado');

        $this->assertNull(Account::find($this->cartao->id)->closedInvoice, 'Sem dívida fechada, sem botão de fechada.');
        $card = $this->card();
        $this->assertSame('a_pagar', $card['estado']);
        $this->assertSame(200.0, $card['invoiceDue']);
        $this->assertSame('2026-08-01', $card['payFloor'], 'Antes: o piso ficava preso em agosto para sempre.');

        $this->pagar()->assertSessionHasNoErrors();

        // O caixa sai só os 200 — os zerados somam zero.
        $this->assertSame(9800.0, Account::find($this->conta->id)->available);
        $this->assertSame(0, $this->linhasEmAberto());
        $pagamento = Transaction::where('settles_account_id', $this->cartao->id)->sole();
        $this->assertSame(200.0, (float) $pagamento->amount);
        $this->assertSame(3, Transaction::where('settled_by_id', $pagamento->id)->count());
        $this->assertNull($this->card()['payFloor']);

        // E o estorno do pagamento devolve TUDO como estava — inclusive os zerados.
        $this->actingAs($this->user)->delete(route('faturas.fatura.estornar', $pagamento))->assertSessionHasNoErrors();
        $this->assertSame(10000.0, Account::find($this->conta->id)->available);
        $this->assertSame(3, $this->linhasEmAberto());
    }

    public function test_zerados_de_fatura_antiga_sem_compra_nova_viram_fatura_coberta(): void
    {
        $this->compra('2026-08-01', 500, 'Passagem');
        $this->estorno('2026-08-05', 500, 'Passagem cancelada');

        $this->assertSame('coberta', $this->card()['estado']);

        $antes = $this->dinheiro();
        $this->quitarPeloCredito()->assertSessionHasNoErrors();

        $this->assertSame($antes, $this->dinheiro());
        $this->assertSame(0, $this->linhasEmAberto());
        $this->assertNull($this->card()['payFloor']);
    }

    // ================================================================ R2-4

    public function test_compra_quitada_pelo_credito_so_sai_depois_de_desfazer_a_quitacao(): void
    {
        $compra = $this->compraEEstornoNoCiclo();
        $this->quitarPeloCredito()->assertSessionHasNoErrors();
        $quitacao = CreditSettlement::sole();
        $antes = $this->dinheiro();

        // As três portas recusam — apontando o caminho que EXISTE.
        $pelasFaturas = $this->erro($this->actingAs($this->user)->from(route('faturas.index'))
            ->delete(route('faturas.compra.destroy', $compra)));
        $peloHistorico = $this->erro($this->actingAs($this->user)->from(route('transactions.index'))
            ->delete(route('transactions.destroy', $compra)));
        $editando = $this->erro($this->actingAs($this->user)->from(route('transactions.index'))
            ->put(route('transactions.update', $compra), [
                'type' => 'expense', 'amount' => '250,00', 'account_id' => $this->cartao->id,
                'date' => '2026-09-12', 'description' => 'Fone',
            ]));

        foreach ([$pelasFaturas, $peloHistorico, $editando] as $mensagem) {
            $this->assertStringContainsString('Desfazer quitação', $mensagem);
            $this->assertStringNotContainsString('Estornar pagamento', $mensagem,
                'Antes: mandava estornar um pagamento que não existe.');
        }
        $this->assertStringContainsString('não pode ser excluída', $pelasFaturas);
        $this->assertStringContainsString('não pode ser editada', $editando);
        $this->assertSame(300.0, (float) $compra->fresh()->amount, 'A compra ficou — e a edição recusada não gravou nada.');

        // Desfazer: as duas linhas voltam a ficar em aberto — sem dinheiro se mover.
        $this->desfazer($quitacao->id)
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'desfeita') && str_contains($s, '2 linhas'));

        $this->assertSame($antes, $this->dinheiro());
        $this->assertSame(2, $this->linhasEmAberto());
        $this->assertSame(0, CreditSettlement::count());
        $this->assertSame(0, Transaction::whereNotNull('credit_settlement_id')->count());
        $this->assertSame('coberta', $this->card()['estado'], 'De volta ao estado de antes da quitação.');

        // Agora a compra sai — e o estorno fica, como CRÉDITO para a próxima fatura.
        $this->actingAs($this->user)->from(route('faturas.index'))
            ->delete(route('faturas.compra.destroy', $compra))
            ->assertSessionHasNoErrors();

        $this->assertNull($compra->fresh());
        $card = $this->card();
        $this->assertSame('credito_sobrando', $card['estado']);
        $this->assertSame(300.0, $card['creditoSobrando']);
        $this->assertSame([
            'caixa' => 10000.0,
            'comprometido' => 0.0,
            // O limite não passa do teto do cartão com o crédito (desenho do F-2).
            'limiteLivre' => 5000.0,
            'aPagarNoAberto' => 0.0,
            'aPagarNoFechado' => 0.0,
            'quitacoesEmCaixa' => 0,
        ], $this->dinheiro());
    }

    public function test_estorno_quitado_pelo_credito_tambem_so_sai_depois_de_desfazer(): void
    {
        $this->compraEEstornoNoCiclo();
        $estorno = Transaction::where('type', 'income')->sole();
        $this->quitarPeloCredito()->assertSessionHasNoErrors();

        $mensagem = $this->erro($this->actingAs($this->user)->from(route('faturas.index'))
            ->delete(route('faturas.compra.destroy', $estorno)));

        $this->assertStringContainsString('Este estorno', $mensagem);
        $this->assertStringContainsString('não pode ser excluído', $mensagem);
        $this->assertStringContainsString('Desfazer quitação', $mensagem);
        // Apagar só o estorno deixaria a compra quitada sem nada que a pagasse.
        $this->assertNotNull($estorno->fresh());
        $this->assertSame(0, $this->linhasEmAberto());
    }

    /** O `payInvoice` numa fatura de líquido zero chega ao mesmo desfecho — e se desfaz igual. */
    public function test_marcar_como_paga_com_liquido_zero_registra_a_quitacao_e_ela_se_desfaz(): void
    {
        $this->compraEEstornoNoCiclo();
        $antes = $this->dinheiro();

        $this->pagar()->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'quitada pelo crédito'));

        $this->assertSame($antes, $this->dinheiro(), 'Nada sai do caixa numa fatura de líquido zero.');
        $quitacao = CreditSettlement::sole();
        $this->assertSame(2, Transaction::where('credit_settlement_id', $quitacao->id)->count());
        $this->assertSame('quitada_pelo_credito', $this->card()['estado']);

        $this->desfazer($quitacao->id)->assertSessionHasNoErrors();

        $this->assertSame($antes, $this->dinheiro());
        $this->assertSame(2, $this->linhasEmAberto());
    }

    // ================================================================ crédito que sobra

    public function test_credito_que_sobra_nao_aparece_como_paga_nem_oferece_quitar(): void
    {
        $this->estorno('2026-08-15', 1000, 'Estorno do notebook');
        $this->compra('2026-08-16', 400, 'Mercado');
        $this->compra('2026-09-15', 400, 'Tênis');

        $card = $this->card();
        $this->assertSame('credito_sobrando', $card['estado']);
        $this->assertSame(200.0, $card['creditoSobrando']);
        $this->assertFalse($card['isPaid'], 'Antes: "Fatura paga" aqui também.');

        $this->actingAs($this->user)->get(route('faturas.index'))
            ->assertOk()
            ->assertSee('sobram')
            ->assertSee('R$ 200,00')
            ->assertDontSee('Fatura paga')
            ->assertDontSee('Quitar pelo crédito');

        // Mesmo pela URL, as linhas ficam em aberto: são elas que levam o crédito adiante.
        $antes = $this->dinheiro();
        $this->quitarPeloCredito()->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'sobram') && str_contains($s, '200,00'));

        $this->assertSame($antes, $this->dinheiro());
        $this->assertSame(0, CreditSettlement::count());
        $this->assertSame(3, $this->linhasEmAberto());
    }

    /** Quitar pelo crédito não tira dinheiro de conta nenhuma — então não exige uma. */
    public function test_familia_so_com_cartao_tambem_quita_pelo_credito(): void
    {
        $this->conta->delete();
        $this->compraEEstornoNoCiclo();

        $this->actingAs($this->user)->get(route('faturas.index'))
            ->assertOk()
            ->assertSee('Quitar pelo crédito')
            ->assertDontSee('Cadastre uma conta corrente/poupança para pagar.');

        $this->quitarPeloCredito()->assertSessionHasNoErrors();
        $this->assertSame(0, $this->linhasEmAberto());
        $this->assertSame('quitada_pelo_credito', $this->card()['estado']);
    }

    // ================================================================ recusas e repetição

    public function test_quitar_pelo_credito_recusa_fatura_que_tem_o_que_pagar(): void
    {
        $this->compra('2026-09-15', 300, 'Mercado');
        $antes = $this->dinheiro();

        $mensagem = $this->erro($this->quitarPeloCredito());

        $this->assertStringContainsString('Marcar como paga', $mensagem);
        $this->assertStringContainsString('R$ 300,00', $mensagem);
        $this->assertSame($antes, $this->dinheiro());
        $this->assertSame(0, CreditSettlement::count());
        $this->assertSame(1, $this->linhasEmAberto());
    }

    public function test_dois_cliques_quitam_uma_vez_e_desfazer_de_novo_nao_acha_nada(): void
    {
        $this->compraEEstornoNoCiclo();

        $this->quitarPeloCredito()->assertSessionHasNoErrors();
        $this->quitarPeloCredito()->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Esta fatura já estava quitada.');

        $quitacao = CreditSettlement::sole();

        $this->desfazer($quitacao->id)->assertSessionHasNoErrors();
        // A quitação não existe mais: o segundo clique recebe o 404 de qualquer id que não existe.
        $this->desfazer($quitacao->id)->assertNotFound();
        $this->assertSame(2, $this->linhasEmAberto());
    }

    // ================================================================ família

    public function test_cartao_e_quitacao_de_outra_familia_dao_o_mesmo_404_de_um_id_inexistente(): void
    {
        $this->compraEEstornoNoCiclo();
        $intruso = User::factory()->create();

        // Quitar a fatura alheia: 404, e nada muda.
        $this->quitarPeloCredito($intruso)->assertNotFound();
        $this->assertSame(0, CreditSettlement::count());
        $this->assertSame(2, $this->linhasEmAberto());
        // Id que não existe e conta da família que não é cartão: a mesma resposta.
        $this->quitarPeloCredito(cartaoId: 999999)->assertNotFound();
        $this->quitarPeloCredito(cartaoId: $this->conta->id)->assertNotFound();

        // Desfazer a quitação alheia: 404, e ela continua de pé.
        $this->quitarPeloCredito()->assertSessionHasNoErrors();
        $quitacao = CreditSettlement::sole();

        $this->desfazer($quitacao->id, $intruso)->assertNotFound();
        $this->desfazer(999999, $intruso)->assertNotFound();
        $this->assertNotNull($quitacao->fresh());
        $this->assertSame(0, $this->linhasEmAberto());
    }

    public function test_dependente_quita_e_desfaz_pela_familia(): void
    {
        $this->compraEEstornoNoCiclo();
        $dependente = User::factory()->create(['account_owner_id' => $this->user->id, 'is_admin' => false]);

        $this->quitarPeloCredito($dependente)->assertSessionHasNoErrors();
        $quitacao = CreditSettlement::sole();
        // O dono é o titular (a família); quem fez fica registrado.
        $this->assertSame($this->user->id, $quitacao->user_id);
        $this->assertSame($dependente->id, $quitacao->made_by_user_id);

        $this->desfazer($quitacao->id, $dependente)->assertSessionHasNoErrors();
        $this->assertSame(2, $this->linhasEmAberto());
    }

    // ================================================================ o estorno de pagamento antigo

    /**
     * Pagamento anterior ao `settled_by_id` é estornado pelo par (cartão, instante do
     * pagamento). Uma quitação pelo crédito do MESMO dia tem o mesmo `paid_at` — e era
     * arrastada junto (observação da auditoria). Não é: ela se desfaz pela dela.
     */
    public function test_estornar_pagamento_antigo_nao_reabre_o_que_foi_quitado_pelo_credito_no_mesmo_dia(): void
    {
        // Pagamento "legado": a compra paga sem vínculo linha a linha, e a saída de
        // caixa que a pagou — no mesmo instante da quitação pelo crédito logo abaixo.
        $compraAntiga = $this->compra('2026-09-12', 200, 'Compra paga antes do vínculo');
        $compraAntiga->forceFill(['paid_at' => Carbon::today()])->save();
        $this->compraEEstornoNoCiclo();
        $this->quitarPeloCredito()->assertSessionHasNoErrors();

        $pagamento = Transaction::factory()->for($this->user)->for($this->conta)->expense()->create([
            'amount' => 200, 'date' => '2026-09-20', 'paid_at' => Carbon::today(),
            'settles_account_id' => $this->cartao->id, 'description' => 'Pagamento da fatura — Nubank',
        ]);

        $this->actingAs($this->user)->delete(route('faturas.fatura.estornar', $pagamento))
            ->assertSessionHasNoErrors();

        $this->assertNull($compraAntiga->fresh()->paid_at, 'A compra daquele pagamento volta a ficar em aberto.');
        $this->assertSame(2, Transaction::whereNotNull('credit_settlement_id')->whereNotNull('paid_at')->count(),
            'As linhas quitadas pelo crédito continuam quitadas.');
    }

    // ================================================================ migration

    public function test_a_migration_da_quitacao_pelo_credito_e_reversivel(): void
    {
        $migration = require base_path('database/migrations/2026_09_23_100000_create_credit_settlements_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('credit_settlements'));
        $this->assertFalse(Schema::hasColumn('transactions', 'credit_settlement_id'));

        $migration->down(); // reentrante: um rollback que parou no meio roda de novo

        $migration->up();
        $this->assertTrue(Schema::hasTable('credit_settlements'));
        $this->assertTrue(Schema::hasColumn('transactions', 'credit_settlement_id'));
        // É por esta coluna que o "Desfazer quitação" acha as linhas.
        $this->assertTrue(Schema::hasIndex('transactions', ['credit_settlement_id']));
    }
}
