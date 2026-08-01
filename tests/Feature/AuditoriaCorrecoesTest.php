<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressões dos bugs encontrados na auditoria completa de 27/07/2026.
 *
 * Cada teste aqui FALHAVA antes da correção correspondente. Os nomes citam o achado do
 * relatório (docs/auditoria-completa-2026-07-27.md) para dar rastreabilidade.
 */
class AuditoriaCorrecoesTest extends TestCase
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

    /**
     * ACHADO M-1 — parcelamento gerava parcela NEGATIVA.
     *
     * Com `round($total/$n, 2)` arredondando para cima, a última parcela absorvia resto
     * negativo: R$ 0,36 em 24x produzia uma parcela de −R$ 0,10. Linha negativa vira
     * crédito no extrato, DEVOLVE limite do cartão e viola a regra "dinheiro nunca é
     * negativo — o sinal vem do type".
     */
    public function test_achado_m1_parcelas_nunca_sao_negativas_e_somam_o_total(): void
    {
        $casos = [
            ['total' => '0,36', 'n' => 24],
            ['total' => '0,54', 'n' => 12],
            ['total' => '1,80', 'n' => 24],
            ['total' => '100,00', 'n' => 3],
            ['total' => '10,00', 'n' => 3],
            ['total' => '1.000,01', 'n' => 7],
            ['total' => '19,99', 'n' => 6],
        ];

        foreach ($casos as $caso) {
            Transaction::query()->delete();

            $this->post(route('faturas.lancar'), [
                'account_id' => $this->cartao->id,
                'category_id' => $this->categoria->id,
                'description' => 'Parcelado '.$caso['total'],
                'amount' => $caso['total'],
                'date' => now()->toDateString(),
                'mode' => 'parcelado',
                'installments' => $caso['n'],
            ])->assertSessionHasNoErrors();

            $parcelas = Transaction::where('account_id', $this->cartao->id)->get();
            $rotulo = "{$caso['total']} em {$caso['n']}x";

            $this->assertCount($caso['n'], $parcelas, "Deveria gerar {$caso['n']} parcelas ({$rotulo}).");

            $minima = $parcelas->min(fn (Transaction $t) => (float) $t->amount);
            $this->assertGreaterThanOrEqual(
                0.0,
                $minima,
                "Parcela NEGATIVA em {$rotulo}: menor parcela = {$minima}.",
            );

            $soma = round($parcelas->sum(fn (Transaction $t) => (float) $t->amount), 2);
            $esperado = (float) str_replace(',', '.', str_replace('.', '', $caso['total']));
            $this->assertSame(
                round($esperado, 2),
                $soma,
                "A soma das parcelas de {$rotulo} deu {$soma} e deveria dar {$esperado}.",
            );
        }
    }

    /**
     * ACHADO C-2 — "Pagar" numa recorrência de CARTÃO apagava a dívida sem sair dinheiro.
     *
     * `paid_at` é exatamente o que tira a despesa da fatura e devolve o limite. Marcando
     * sem debitar nada, a dívida evaporava: três cliques quitavam R$ 149,70 com R$ 0,00
     * saindo do caixa.
     *
     * A regra correta: despesa no cartão é quitada pela FATURA. O botão da recorrência
     * pode adiantar a próxima ocorrência, mas não pode declarar a atual paga.
     */
    public function test_achado_c2_pagar_recorrencia_de_cartao_nao_apaga_a_divida(): void
    {
        $recorrente = Transaction::factory()->for($this->user)->create([
            'account_id' => $this->cartao->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => 49.90,
            'date' => now()->toDateString(),
            'description' => 'Streaming',
            'recurring' => true,
            'paid_at' => null,
        ]);

        $caixaAntes = round(Account::find($this->corrente->id)->available, 2);
        $dividaAntes = round((float) Account::find($this->cartao->id)->committed, 2);

        $this->post(route('faturas.recorrente.pagar', $recorrente));

        $caixaDepois = round(Account::find($this->corrente->id)->available, 2);
        $dividaDepois = round((float) Account::find($this->cartao->id)->committed, 2);

        // Se o caixa não se moveu, a dívida do cartão NÃO pode ter diminuído.
        if ($caixaDepois === $caixaAntes) {
            $this->assertGreaterThanOrEqual(
                $dividaAntes,
                $dividaDepois,
                'A dívida do cartão diminuiu sem nenhum dinheiro sair do caixa — '
                    ."dívida antes: {$dividaAntes}, depois: {$dividaDepois}.",
            );
        }

        // A ocorrência original não pode ser declarada paga sem pagamento.
        $recorrente->refresh();
        $this->assertNull(
            $recorrente->paid_at,
            'A recorrência de cartão foi marcada como paga sem saída de caixa.',
        );
    }

    /**
     * ACHADO C-2 (parte 2) — repetir o clique não pode ir zerando a fatura.
     */
    public function test_achado_c2_cliques_repetidos_nao_zeram_a_fatura_do_cartao(): void
    {
        $recorrente = Transaction::factory()->for($this->user)->create([
            'account_id' => $this->cartao->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => 49.90,
            'date' => now()->toDateString(),
            'recurring' => true,
            'paid_at' => null,
        ]);

        $caixaAntes = round(Account::find($this->corrente->id)->available, 2);

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('faturas.recorrente.pagar', $recorrente));
        }

        $caixaDepois = round(Account::find($this->corrente->id)->available, 2);
        $pagas = Transaction::where('account_id', $this->cartao->id)->whereNotNull('paid_at')->count();
        $somaPaga = round(
            (float) Transaction::where('account_id', $this->cartao->id)->whereNotNull('paid_at')->sum('amount'),
            2,
        );

        $this->assertSame(
            $caixaAntes,
            $caixaDepois,
            'O caixa mudou sem o usuário escolher conta de pagamento.',
        );

        $this->assertSame(
            0.0,
            $somaPaga,
            "Foram quitados R$ {$somaPaga} em {$pagas} despesa(s) de cartão sem sair dinheiro do caixa.",
        );
    }

    /**
     * ACHADO C-1 — pagar a fatura duas vezes não pode duplicar a saída de caixa.
     *
     * O controller lia as despesas em aberto FORA da transação e criava a saída de caixa
     * incondicionalmente, mesmo quando o relock mostrava que nada restava para pagar.
     * Sequencialmente o app já se protegia; a janela era a concorrente. O teste abaixo
     * cobre o que dá para cobrir sem paralelismo real: a saída nunca é criada quando não
     * há despesa em aberto.
     */
    public function test_achado_c1_pagar_fatura_sem_despesa_aberta_nao_move_o_caixa(): void
    {
        // Compra já paga (simula "a outra requisição pagou primeiro").
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->cartao->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => 750.00,
            'date' => now()->subDays(5)->toDateString(),
            'paid_at' => now()->subDay(),
        ]);

        $caixaAntes = round(Account::find($this->corrente->id)->available, 2);

        $this->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->corrente->id,
        ]);

        $this->assertSame(
            $caixaAntes,
            round(Account::find($this->corrente->id)->available, 2),
            'Pagou uma fatura sem nada em aberto e ainda debitou o caixa.',
        );

        $saidas = Transaction::where('account_id', $this->corrente->id)
            ->where('description', 'like', '%atura%')
            ->count();

        $this->assertSame(0, $saidas, 'Criou saída de caixa para uma fatura que já estava quitada.');
    }

    /**
     * ACHADO ALTA-5 (funding) — editar RECEITA para DESPESA na mesma conta furava o saldo.
     *
     * O `$ignore` só devolvia folga quando a linha já era despesa. Sendo receita, o
     * disponível consultado ainda continha a receita que estava sendo destruída, então a
     * folga era contada duas vezes: conta com R$ 100 + receita de R$ 500 aceitava virar
     * despesa de R$ 600 e ia a −R$ 500 sem cheque especial.
     */
    public function test_achado_alta5_editar_receita_para_despesa_nao_fura_o_saldo(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'initial_balance' => 100,
            'overdraft_limit' => 0,
        ]);
        $catReceita = Category::factory()->for($this->user)->income()->create();

        $this->post(route('transactions.store'), [
            'type' => 'income',
            'amount' => '500,00',
            'date' => now()->toDateString(),
            'account_id' => $conta->id,
            'category_id' => $catReceita->id,
        ])->assertSessionHasNoErrors();

        $receita = Transaction::where('account_id', $conta->id)->where('type', 'income')->firstOrFail();
        $this->assertSame(600.0, round(Account::find($conta->id)->available, 2));

        // Converte a MESMA linha em despesa de R$ 600 — sem a receita, só há R$ 100.
        $this->patch(route('transactions.update', $receita), [
            'type' => 'expense',
            'amount' => '600,00',
            'date' => now()->toDateString(),
            'account_id' => $conta->id,
            'category_id' => $this->categoria->id,
        ]);

        $this->assertGreaterThanOrEqual(
            0.0,
            round(Account::find($conta->id)->available, 2),
            'A conta ficou negativa sem cheque especial ao converter receita em despesa.',
        );
    }

    /**
     * ACHADO CRÍTICA-2 (dashboard) — card "Contas a pagar" cobrava fatura já paga.
     *
     * Usava `currentInvoice` (soma do ciclo, ignora `paid_at`) em vez de
     * `openInvoiceDue`. O sino dizia "nada a vencer" e o card cobrava, na mesma tela.
     */
    public function test_achado_critica2_dashboard_nao_cobra_fatura_ja_paga(): void
    {
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->cartao->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => 400.00,
            'date' => now()->toDateString(),
            'paid_at' => now(),   // já paga
        ]);

        $dados = app(\App\Services\DashboardService::class)->build($this->user->ownerId());

        $this->assertSame(
            0.0,
            round((float) $dados['faturasResumo']['total'], 2),
            'O card "A pagar nas faturas" continua somando uma fatura já quitada.',
        );
    }

    /**
     * ACHADO MÉDIA-1 (contas fixas) — competência inválida na URL debitava a conta.
     *
     * `2026-13` virava janeiro/2027 por overflow do Carbon; `2026-00` virava dezembro/2025.
     * O dinheiro saía e a competência paga não aparecia em tela nenhuma.
     */
    public function test_achado_media1_competencia_invalida_e_recusada(): void
    {
        if (! \Illuminate\Support\Facades\Route::has('contas-fixas.pagar')) {
            $this->markTestSkipped('Contas fixas não disponíveis.');
        }

        $conta = \App\Models\FixedBill::create([
            'user_id' => $this->user->id,
            'name' => 'Aluguel',
            'amount' => 1800.00,
            'due_day' => 10,
            'account_id' => $this->corrente->id,
            'category_id' => $this->categoria->id,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'active' => true,
        ]);

        $caixaAntes = round(Account::find($this->corrente->id)->available, 2);

        foreach (['2026-13', '2026-00', '2026-99'] as $competencia) {
            $resposta = $this->post(
                url("/contas-fixas/{$conta->id}/pagar/{$competencia}"),
                ['account_id' => $this->corrente->id, 'amount' => '1.800,00'],
            );

            $this->assertLessThan(
                500,
                $resposta->getStatusCode(),
                "A competência {$competencia} causou erro de servidor (500).",
            );
        }

        $this->assertSame(
            $caixaAntes,
            round(Account::find($this->corrente->id)->available, 2),
            'Competência inválida debitou a conta.',
        );
    }

    /**
     * ACHADO MÉDIA-2 (contas fixas) — o segundo clique em "Pagar" dava 500 em sqlite.
     *
     * O tratamento de duplicidade procurava o NOME DO ÍNDICE na mensagem do driver, que
     * só o MySQL inclui. Em qualquer outro driver a exceção era relançada.
     */
    public function test_achado_media2_pagar_a_mesma_competencia_duas_vezes_nao_da_500(): void
    {
        if (! \Illuminate\Support\Facades\Route::has('contas-fixas.pagar')) {
            $this->markTestSkipped('Contas fixas não disponíveis.');
        }

        // Competência do mês PASSADO, portanto já vencida: a regra do app é que só se
        // paga o que já venceu (ou está próximo do vencimento), então um cenário com
        // vencimento distante seria recusado antes de chegar na trava de duplicidade —
        // que é o que este teste quer exercitar.
        $conta = \App\Models\FixedBill::create([
            'user_id' => $this->user->id,
            'name' => 'Condomínio',
            'amount' => 500.00,
            'due_day' => 10,
            'account_id' => $this->corrente->id,
            'category_id' => $this->categoria->id,
            'starts_on' => now()->subMonth()->startOfMonth()->toDateString(),
            'active' => true,
        ]);

        $competencia = now()->subMonth()->format('Y-m');
        $payload = ['account_id' => $this->corrente->id, 'amount' => '500,00'];

        $primeira = $this->post(url("/contas-fixas/{$conta->id}/pagar/{$competencia}"), $payload);
        $this->assertLessThan(400, $primeira->getStatusCode(), 'O primeiro pagamento deveria funcionar.');

        $segunda = $this->post(url("/contas-fixas/{$conta->id}/pagar/{$competencia}"), $payload);

        $this->assertLessThan(
            500,
            $segunda->getStatusCode(),
            'O segundo pagamento da mesma competência devolveu erro de servidor em vez de aviso.',
        );

        // E não pode ter debitado duas vezes.
        $pagamentos = Transaction::where('fixed_bill_id', $conta->id)->count();
        $this->assertSame(1, $pagamentos, 'A mesma competência foi paga duas vezes.');
    }
}
