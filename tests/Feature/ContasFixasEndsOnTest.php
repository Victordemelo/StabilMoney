<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FixedBillService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\DesligaEscopoDaFamiliaNaRota;
use Tests\TestCase;

/**
 * `ends_on` (a data em que a conta fixa se encerra) só era respeitado na
 * PROJEÇÃO — `FixedBillService::occurrences` era o ÚNICO lugar do app inteiro
 * que lia a coluna. Nem `FixedBillController::pay` nem `PayFixedBillRequest`
 * olhavam para ela.
 *
 * Efeito: um POST direto em `contas-fixas.pagar/{competencia}` pagava a
 * competência de uma conta JÁ ENCERRADA. Como as competências não são
 * materializadas, a URL é a única coisa que diz qual mês está sendo pago — o
 * dinheiro saía do caixa e a linha nascia com `fixed_bill_id` + `competence`
 * de um período que nenhuma tela lista (invisível para conferir, e sem botão
 * para estornar). Reproduzido: conta encerrada em 30/06 aceitou a competência
 * de agosto.
 *
 * A correção mora em `PayFixedBillRequest::validarJanelaDaCompetencia()` e
 * espelha a regra da projeção nas DUAS bordas: `ends_on` (inclusivo, por mês) e
 * o piso de `FixedBillService::MAX_MESES_ATRAS`. Os testes abaixo checam a
 * projeção e o pagamento LADO A LADO de propósito: se um dia divergirem, uma
 * tela mostrará "Pagar" num botão que o servidor recusa.
 */
class ContasFixasEndsOnTest extends TestCase
{
    use DesligaEscopoDaFamiliaNaRota, RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Conta Corrente',
            'initial_balance' => 10000,
            'overdraft_limit' => 0, // sem fonte alternativa: nada de 409 no meio do teste
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Aluguel de R$ 1.800 que vence todo dia 10, começando em jan/2026. */
    private function aluguel(array $overrides = []): FixedBill
    {
        return FixedBill::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'name' => 'Aluguel',
            'amount' => 1800,
            'due_day' => 10,
            'account_id' => $this->conta->id,
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'active' => true,
        ], $overrides));
    }

    private function pagar(FixedBill $bill, string $competencia, array $extra = [])
    {
        return $this->actingAs($this->user)->post(
            route('contas-fixas.pagar', [$bill, $competencia]),
            array_merge(['account_id' => $this->conta->id, 'amount' => '1.800,00'], $extra),
        );
    }

    /** As competências que a tela projeta no intervalo, como "AAAA-MM". */
    private function projetadas(string $de, string $ate): array
    {
        return app(FixedBillService::class)
            ->occurrences($this->user->id, CarbonImmutable::parse($de), CarbonImmutable::parse($ate))
            ->map(fn ($o) => $o['competence']->format('Y-m'))
            ->all();
    }

    // ------------------------------------------------------------------
    // 🔴 O bug: competência posterior ao encerramento
    // ------------------------------------------------------------------

    /**
     * O caso reproduzido: conta encerrada em 30/06 aceitava a competência de
     * agosto. O saldo é a prova — antes da correção ele caía R$ 1.800.
     */
    public function test_competencia_posterior_ao_encerramento_e_recusada_e_o_saldo_nao_muda(): void
    {
        $bill = $this->aluguel(['ends_on' => '2026-06-30']);

        $this->pagar($bill, '2026-08')->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(10000.0, $this->conta->fresh()->balance);
        $this->assertSame(10000.0, $this->conta->fresh()->available);

        // A mensagem cita a data de encerramento — o usuário precisa entender
        // por que um botão que ele viu (ou uma URL que guardou) parou de valer.
        $this->assertStringContainsString('30/06/2026', session('errors')->first('amount'));
    }

    /** E o mês IMEDIATAMENTE seguinte ao encerramento também não existe. */
    public function test_mes_seguinte_ao_encerramento_e_recusado(): void
    {
        $bill = $this->aluguel(['ends_on' => '2026-06-30']);

        $this->assertNotContains('2026-07', $this->projetadas('2026-01-01', '2026-12-31'));

        $this->pagar($bill, '2026-07')->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(10000.0, $this->conta->fresh()->balance);
    }

    // ------------------------------------------------------------------
    // 🟢 A fronteira é INCLUSIVA — não vale travar demais
    // ------------------------------------------------------------------

    /**
     * O mês do `ends_on` ainda é devido: o contrato acaba em 30/06, mas o
     * aluguel de junho venceu no dia 10 e tem de ser pago. A projeção lista
     * junho; o pagamento aceita junho.
     */
    public function test_competencia_do_mes_do_encerramento_continua_pagavel(): void
    {
        $bill = $this->aluguel(['ends_on' => '2026-06-30']);

        $projetadas = $this->projetadas('2026-01-01', '2026-12-31');
        $this->assertContains('2026-06', $projetadas);
        $this->assertNotContains('2026-07', $projetadas);

        $this->pagar($bill, '2026-06')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(8200.0, $this->conta->fresh()->balance);
        $this->assertSame('2026-06-01', Transaction::firstOrFail()->competence->toDateString());
    }

    /**
     * Borda fina: `ends_on` cai ANTES do vencimento, dentro do mesmo mês
     * (encerrou dia 05, vence dia 10). A projeção compara o MÊS, não a data, e
     * portanto ainda lista junho — o pagamento tem de aceitar exatamente o que
     * a tela oferece, senão o botão "Pagar" vira um erro na cara do usuário.
     *
     * (É de propósito diferente do `starts_on`, que o controller compara pela
     * data de VENCIMENTO — a assimetria vem da projeção, ver A-10.)
     */
    public function test_encerramento_no_meio_do_mes_segue_a_mesma_regra_da_projecao(): void
    {
        $bill = $this->aluguel(['ends_on' => '2026-06-05']);

        $this->assertContains('2026-06', $this->projetadas('2026-01-01', '2026-12-31'));

        $this->pagar($bill, '2026-06')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(8200.0, $this->conta->fresh()->balance);
    }

    /** `ends_on` null = sem fim: não dá para travar tudo por causa da correção. */
    public function test_conta_sem_encerramento_continua_pagando_normalmente(): void
    {
        $bill = $this->aluguel(); // ends_on = null

        $this->pagar($bill, '2026-08')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(8200.0, $this->conta->fresh()->balance);
        $this->assertSame('2026-08-01', Transaction::firstOrFail()->competence->toDateString());
    }

    // ------------------------------------------------------------------
    // 🕰️ A outra borda sem guarda: competência antiga demais
    // ------------------------------------------------------------------

    /**
     * A projeção também tem um teto PARA TRÁS (`MAX_MESES_ATRAS`), e o
     * pagamento não o respeitava: dava para pagar uma competência de anos atrás
     * que a tela nunca lista. Mesmo buraco do `ends_on`, outra borda.
     */
    public function test_competencia_anterior_a_janela_de_meses_atras_e_recusada(): void
    {
        $bill = $this->aluguel(['starts_on' => '2024-01-01']);

        // Hoje é 15/08/2026 → a tela começa em ago/2025 (12 meses atrás).
        $listadas = app(FixedBillService::class)->currentAndOverdue($this->user->id)
            ->map(fn ($o) => $o['competence']->format('Y-m'))
            ->all();
        $this->assertNotContains('2025-07', $listadas);
        $this->assertContains('2025-08', $listadas);

        $this->pagar($bill, '2025-07')->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(10000.0, $this->conta->fresh()->balance);

        // E a mais antiga que a tela LISTA continua pagável (fronteira inclusiva).
        $this->pagar($bill, '2025-08')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(8200.0, $this->conta->fresh()->balance);
    }

    // ------------------------------------------------------------------
    // 🛡️ Guardas de regressão
    // ------------------------------------------------------------------

    /** A-10 não pode ter regredido: antes do `starts_on` continua recusado. */
    public function test_competencia_anterior_ao_inicio_continua_recusada(): void
    {
        // Cadastrada para começar em 20/08, vencendo todo dia 5: agosto não existe.
        $bill = $this->aluguel(['starts_on' => '2026-08-20', 'due_day' => 5]);

        $this->pagar($bill, '2026-08')->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(10000.0, $this->conta->fresh()->balance);
    }

    /**
     * Idempotência intacta: o UNIQUE (fixed_bill_id, competence) continua sendo
     * a trava do duplo clique, e a nova validação não muda o caminho feliz.
     */
    public function test_pagar_a_mesma_competencia_duas_vezes_cria_uma_linha_so(): void
    {
        $bill = $this->aluguel(['ends_on' => '2026-06-30']);

        $this->pagar($bill, '2026-06')->assertSessionHasNoErrors();
        $this->pagar($bill, '2026-06')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(8200.0, $this->conta->fresh()->balance);
    }

    /**
     * Conta fixa de outra família nunca vira erro de validação (que citaria o nome dela).
     *
     * Desde 23/09/2026 ela recebe o 404 de um id que não existe, no binding, antes de a
     * validação existir. O 403 do `authorize()` do PayFixedBillRequest ficou como linha de
     * trás — e continua provado aqui, com o escopo do binding desligado.
     */
    public function test_conta_encerrada_de_outra_familia_nao_vaza_o_nome_na_mensagem(): void
    {
        $estranho = User::factory()->create();
        $bill = FixedBill::factory()->create([
            'user_id' => $estranho->id,
            'name' => 'Aluguel do vizinho',
            'amount' => 1800,
            'due_day' => 10,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-06-30',
            'active' => true,
        ]);

        $this->pagar($bill, '2026-08')->assertNotFound()->assertSessionHasNoErrors();

        $this->desligarEscopoDaFamiliaNaRota('conta', FixedBill::class);
        $this->pagar($bill, '2026-08')->assertForbidden()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transactions', 0);
    }
}
