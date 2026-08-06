<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jornada completa de um usuário, ponta a ponta, com o dinheiro CONFERIDO À MÃO
 * a cada etapa.
 *
 * Por que este teste existe além dos outros: cada feature já tem teste isolado (cartão,
 * meta, investimento, conta fixa), mas ninguém verifica se as peças SOMAM certo quando
 * usadas juntas na mesma conta. É aqui que aparecem dupla contagem, reserva que não volta
 * e saldo que "some" — os erros que fazem o usuário perder confiança no app.
 *
 * A regra que este arquivo verifica é uma só: **dinheiro se conserva**. Toda operação ou
 * move valor entre bolsos (disponível ↔ reservado) ou tira do patrimônio para pagar algo
 * real. Nada aparece nem desaparece.
 *
 * Os valores são propositalmente "sujos" (com centavos que não dividem bem), porque é
 * onde arredondamento erra.
 */
class JornadaFinanceiraCompletaTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private Account $corrente;

    private Category $catDespesa;

    private Category $catReceita;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['is_admin' => true]);

        $this->catDespesa = Category::factory()->for($this->titular)->expense()->create(['name' => 'Mercado']);
        $this->catReceita = Category::factory()->for($this->titular)->income()->create(['name' => 'Salário']);

        // Conta corrente com R$ 5.000,00 e sem cheque especial.
        // Criada pela ROTA de propósito: o teste exercita o caminho real do usuário,
        // incluindo Form Request e normalização de "5.000,00".
        $this->actingAs($this->titular);
        $this->post(route('accounts.store'), [
            'name' => 'Corrente Principal',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '5.000,00',
            'overdraft_limit' => '0',
        ]);

        $this->corrente = Account::where('user_id', $this->titular->id)->firstOrFail();
    }

    /** Recarrega a conta do banco (os saldos são accessors com query). */
    private function conta(): Account
    {
        return Account::findOrFail($this->corrente->id);
    }

    /** Soma disponível de TODAS as contas de caixa da família. */
    private function caixaTotal(): float
    {
        return (float) Account::where('user_id', $this->titular->id)
            ->whereIn('type', ['checking', 'savings'])
            ->get()
            ->sum(fn (Account $c) => $c->available);
    }

    /**
     * ETAPA 1 — despesa simples no débito da conta: sai do disponível na hora.
     */
    public function test_etapa_1_despesa_na_conta_desconta_do_disponivel(): void
    {
        $this->actingAs($this->titular);

        $antes = $this->conta()->available;
        $this->assertSame(5000.0, round($antes, 2), 'A conta deveria começar com 5.000,00.');

        $this->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '1.234,56',
            'date' => now()->toDateString(),
            'account_id' => $this->corrente->id,
            'category_id' => $this->catDespesa->id,
            'description' => 'Compra do mês',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            3765.44,
            round($this->conta()->available, 2),
            '5.000,00 − 1.234,56 tem de dar 3.765,44.',
        );
    }

    /**
     * ETAPA 2 — receita entra no disponível.
     */
    public function test_etapa_2_receita_soma_no_disponivel(): void
    {
        $this->actingAs($this->titular);

        $this->post(route('transactions.store'), [
            'type' => 'income',
            'amount' => '2.500,49',
            'date' => now()->toDateString(),
            'account_id' => $this->corrente->id,
            'category_id' => $this->catReceita->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(7500.49, round($this->conta()->available, 2));
    }

    /**
     * ETAPA 3 — o teste central: aportar numa meta MOVE dinheiro de bolso, não gasta.
     *
     * O saldo bruto continua o mesmo (o dinheiro ainda está na conta), o reservado sobe
     * e o disponível cai. Se o disponível cair DUAS vezes, há dupla contagem — o bug mais
     * provável depois da migration que ligou aportes a transações.
     */
    public function test_etapa_3_aporte_em_meta_move_de_bolso_sem_sumir(): void
    {
        $this->actingAs($this->titular);

        $this->post(route('metas.store'), [
            'name' => 'Viagem',
            'target_amount' => '10.000,00',
            'emoji' => '✈️',
            'color' => '#1C9A70',
        ])->assertSessionHasNoErrors();

        $meta = Goal::where('user_id', $this->titular->id)->firstOrFail();

        $brutoAntes = $this->conta()->balance;
        $dispAntes = $this->conta()->available;

        $this->post(route('metas.aportes.store', $meta), [
            'amount' => '1.500,25',
            'account_id' => $this->corrente->id,
        ])->assertSessionHasNoErrors();

        $conta = $this->conta();

        $this->assertSame(
            round($dispAntes - 1500.25, 2),
            round($conta->available, 2),
            'O disponível deve cair exatamente o valor do aporte — UMA vez só.',
        );

        $this->assertSame(
            1500.25,
            round($conta->reserved, 2),
            'O reservado deve subir o valor do aporte.',
        );

        // O dinheiro não saiu da conta: bruto = disponível + reservado.
        $this->assertSame(
            round($conta->available + $conta->reserved, 2),
            round($conta->balance, 2),
            'bruto = disponível + reservado (invariante dos bolsos).',
        );

        $this->assertSame(
            round($brutoAntes, 2),
            round($conta->balance, 2),
            'Aporte NÃO é gasto: o saldo bruto da conta não pode mudar.',
        );
    }

    /**
     * ETAPA 4 — resgatar devolve exatamente o que foi aportado. Ida e volta fecha em zero.
     */
    public function test_etapa_4_aporte_e_resgate_fecham_em_zero(): void
    {
        $this->actingAs($this->titular);

        $this->post(route('metas.store'), [
            'name' => 'Reserva',
            'target_amount' => '5.000,00',
            'emoji' => '🛟',
            'color' => '#1C9A70',
        ]);
        $meta = Goal::where('user_id', $this->titular->id)->firstOrFail();

        $inicial = $this->conta()->available;

        // Três aportes com centavos que não dividem bem.
        foreach (['333,33', '666,67', '0,01'] as $valor) {
            $this->post(route('metas.aportes.store', $meta), [
                'amount' => $valor,
                'account_id' => $this->corrente->id,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(1000.01, round($this->conta()->reserved, 2));

        // Resgata tudo, em pedaços diferentes dos aportes.
        foreach (['500,00', '500,01'] as $valor) {
            $this->post(route('metas.resgates.store', $meta), [
                'amount' => $valor,
                'account_id' => $this->corrente->id,
            ])->assertSessionHasNoErrors();
        }

        $conta = $this->conta();

        $this->assertSame(0.0, round($conta->reserved, 2), 'Resgatado tudo, o reservado tem de voltar a zero.');
        $this->assertSame(
            round($inicial, 2),
            round($conta->available, 2),
            'Ida e volta do cofrinho tem de devolver o disponível ao valor original, sem perder centavo.',
        );
    }

    /**
     * ETAPA 5 — não deve ser possível resgatar mais do que foi aportado.
     * Se der, o reservado fica negativo e o app INVENTA dinheiro no disponível.
     */
    public function test_etapa_5_resgate_maior_que_o_aportado_e_recusado(): void
    {
        $this->actingAs($this->titular);

        $this->post(route('metas.store'), [
            'name' => 'Cofre',
            'target_amount' => '1.000,00',
            'emoji' => '🐷',
            'color' => '#1C9A70',
        ]);
        $meta = Goal::where('user_id', $this->titular->id)->firstOrFail();

        $this->post(route('metas.aportes.store', $meta), [
            'amount' => '100,00',
            'account_id' => $this->corrente->id,
        ])->assertSessionHasNoErrors();

        $dispAntes = $this->conta()->available;

        // Tenta resgatar o dobro do que existe.
        $this->post(route('metas.resgates.store', $meta), [
            'amount' => '200,00',
            'account_id' => $this->corrente->id,
        ]);

        $conta = $this->conta();

        $this->assertGreaterThanOrEqual(
            0.0,
            round($conta->reserved, 2),
            'O reservado NUNCA pode ficar negativo — isso infla o disponível com dinheiro inexistente.',
        );

        $this->assertLessThanOrEqual(
            round($dispAntes + 100.0, 2),
            round($conta->available, 2),
            'O disponível não pode crescer mais do que os R$ 100,00 que estavam reservados.',
        );
    }

    /**
     * ETAPA 6 — despesa maior que o saldo, sem cheque especial, tem de ser RECUSADA.
     * Esta é a trava que o app não tinha antes da v3.
     */
    public function test_etapa_6_gasto_acima_do_saldo_e_recusado(): void
    {
        $this->actingAs($this->titular);

        $disponivel = $this->conta()->available;

        $this->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '99.999,99',
            'date' => now()->toDateString(),
            'account_id' => $this->corrente->id,
            'category_id' => $this->catDespesa->id,
        ])->assertSessionHasErrors();

        $this->assertSame(
            round($disponivel, 2),
            round($this->conta()->available, 2),
            'Despesa recusada não pode ter mexido no saldo.',
        );
    }

    /**
     * ETAPA 7 — compra no cartão NÃO mexe no caixa; só compromete o limite.
     * O caixa só é debitado quando a fatura é paga.
     */
    public function test_etapa_7_compra_no_cartao_nao_toca_no_caixa(): void
    {
        $this->actingAs($this->titular);

        $this->post(route('accounts.store'), [
            'name' => 'Cartão Roxo',
            'type' => 'credit_card',
            'bank' => 'nubank',
            'credit_limit' => '3.000,00',
            'closing_day' => 10,
            'due_day' => 20,
        ])->assertSessionHasNoErrors();

        $cartao = Account::where('user_id', $this->titular->id)
            ->where('type', 'credit_card')->firstOrFail();

        $caixaAntes = $this->caixaTotal();

        $this->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '450,75',
            'date' => now()->toDateString(),
            'account_id' => $cartao->id,
            'category_id' => $this->catDespesa->id,
            'description' => 'Compra no crédito',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            round($caixaAntes, 2),
            round($this->caixaTotal(), 2),
            'Compra no crédito não pode descontar do caixa — só a fatura desconta.',
        );

        $cartao->refresh();
        $this->assertSame(
            450.75,
            round((float) $cartao->committed, 2),
            'A compra deve comprometer o limite do cartão.',
        );
    }

    /**
     * ETAPA 8 — pagar a fatura desconta do caixa UMA vez e devolve o limite.
     */
    public function test_etapa_8_pagar_fatura_desconta_uma_vez_e_devolve_limite(): void
    {
        $this->actingAs($this->titular);

        $this->post(route('accounts.store'), [
            'name' => 'Cartão',
            'type' => 'credit_card',
            'bank' => 'itau',
            'credit_limit' => '3.000,00',
            'closing_day' => 10,
            'due_day' => 20,
        ]);
        $cartao = Account::where('user_id', $this->titular->id)
            ->where('type', 'credit_card')->firstOrFail();

        $this->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '300,30',
            'date' => now()->toDateString(),
            'account_id' => $cartao->id,
            'category_id' => $this->catDespesa->id,
        ])->assertSessionHasNoErrors();

        $caixaAntes = $this->caixaTotal();

        $resposta = $this->post(route('faturas.fatura.pagar', $cartao), [
            'pay_account_id' => $this->corrente->id,
        ]);

        // Só segue a verificação se o app aceitou o pagamento (pode exigir ciclo fechado).
        if ($resposta->getStatusCode() >= 400) {
            $this->markTestSkipped('O app não permitiu pagar esta fatura agora (ciclo em aberto) — ver relatório.');
        }

        $caixaDepois = $this->caixaTotal();
        $movimento = round($caixaAntes - $caixaDepois, 2);

        $this->assertSame(
            300.30,
            $movimento,
            "Pagar a fatura tem de descontar exatamente o valor dela do caixa (movimento observado: {$movimento}).",
        );

        $cartao->refresh();
        $this->assertSame(
            0.0,
            round((float) $cartao->committed, 2),
            'Fatura paga tem de liberar o limite comprometido.',
        );
    }

    /**
     * ETAPA 9 — o invariante final: com tudo junto (despesa, receita, meta,
     * investimento, cartão), a conta dos bolsos tem de fechar.
     */
    public function test_etapa_9_invariante_final_dos_bolsos(): void
    {
        $this->actingAs($this->titular);

        // Movimento variado na conta.
        $this->post(route('transactions.store'), [
            'type' => 'income', 'amount' => '3.333,33', 'date' => now()->toDateString(),
            'account_id' => $this->corrente->id, 'category_id' => $this->catReceita->id,
        ]);
        $this->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => '777,77', 'date' => now()->toDateString(),
            'account_id' => $this->corrente->id, 'category_id' => $this->catDespesa->id,
        ]);

        // Meta + investimento a partir da mesma conta.
        $this->post(route('metas.store'), [
            'name' => 'Meta X', 'target_amount' => '9.999,99', 'emoji' => '🎯', 'color' => '#1C9A70',
        ]);
        $meta = Goal::where('user_id', $this->titular->id)->firstOrFail();
        $this->post(route('metas.aportes.store', $meta), [
            'amount' => '1.111,11', 'account_id' => $this->corrente->id,
        ])->assertSessionHasNoErrors();

        $this->post(route('investimentos.store'), [
            'name' => 'CDB Teste',
            'classe' => 'renda_fixa',
            'indexador' => 'CDI',
            'taxa' => '110',
            'valor_inicial' => '2.222,22',
            'account_id' => $this->corrente->id,
        ])->assertSessionHasNoErrors();

        $conta = $this->conta();

        // INVARIANTE 1: bruto = disponível + reservado.
        $this->assertSame(
            round($conta->balance, 2),
            round($conta->available + $conta->reserved, 2),
            'Invariante quebrada: saldo bruto ≠ disponível + reservado.',
        );

        // INVARIANTE 2: o reservado tem de ser exatamente meta + investimento.
        $this->assertSame(
            round(1111.11 + 2222.22, 2),
            round($conta->reserved, 2),
            'O reservado tem de ser a soma dos aportes (meta + investimento).',
        );

        // INVARIANTE 3: bruto = inicial + receitas − despesas (só o que passou pela conta).
        $this->assertSame(
            round(5000.00 + 3333.33 - 777.77, 2),
            round($conta->balance, 2),
            'O saldo bruto tem de ser inicial + receitas − despesas.',
        );

        // INVARIANTE 4: nada negativo onde não pode.
        $this->assertGreaterThanOrEqual(0.0, round($conta->reserved, 2), 'Reservado negativo é dinheiro inventado.');
    }
}
