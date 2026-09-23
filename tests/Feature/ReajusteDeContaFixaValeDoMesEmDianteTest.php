<?php

namespace Tests\Feature;

use App\Console\Commands\LembretesDeVencimentos;
use App\Models\Account;
use App\Models\FixedBill;
use App\Models\User;
use App\Services\FaturaService;
use App\Services\FixedBillService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reajustar uma conta fixa vale da competência do mês em diante — as anteriores
 * ainda em aberto mantêm o valor que tinham.
 *
 * O defeito: `FixedBillService::occurrences` usava `$bill->amount` para TODA
 * competência não paga. Reajustar o aluguel em julho reescrevia o previsto de maio
 * e junho em aberto — e é esse número que o sino, os lembretes por e-mail e o teto
 * de 3× do pagamento usam. Um reajuste para BAIXO travava o pagamento de junho com
 * o valor verdadeiro de junho, que passava a estourar o teto calculado com o novo.
 *
 * A regra (`FixedBill::definirValorPrevisto`): o valor de cada competência é o
 * último salvo até o mês dela. Por isso uma edição no MESMO mês em que o valor foi
 * salvo (na criação ou num reajuste) é correção, não reajuste — e a correção de
 * digitação logo depois de criar vale para todas as competências.
 *
 * Aluguel que vence dia 10, cadastrado em abril com início em 01/04.
 */
class ReajusteDeContaFixaValeDoMesEmDianteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)
            ->create(['type' => 'checking', 'initial_balance' => 50000, 'overdraft_limit' => 0]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    private function em(string $data): void
    {
        $this->travelTo(CarbonImmutable::parse($data.' 12:00:00'));
    }

    /** Cadastra pela tela, como o usuário faz (o `created_at` sai do relógio do teste). */
    private function cadastrar(string $valor, string $inicio = '2026-04-01'): FixedBill
    {
        $this->actingAs($this->user)->post(route('contas-fixas.store'), [
            'name' => 'Aluguel',
            'amount' => $valor,
            'due_day' => 10,
            'account_id' => $this->conta->id,
            'starts_on' => $inicio,
        ])->assertSessionHasNoErrors();

        return FixedBill::latest('id')->firstOrFail();
    }

    /** O PATCH do modal de edição: ele manda TODOS os campos, inclusive o valor. */
    private function editar(FixedBill $bill, string $valor, array $outros = [])
    {
        $bill->refresh();

        return $this->actingAs($this->user)->patch(route('contas-fixas.update', $bill), array_merge([
            'name' => $bill->name,
            'amount' => $valor,
            'due_day' => $bill->due_day,
            'account_id' => $bill->account_id,
            'starts_on' => $bill->starts_on->toDateString(),
        ], $outros));
    }

    private function pagar(FixedBill $bill, string $competencia, string $valor)
    {
        return $this->actingAs($this->user)->post(route('contas-fixas.pagar', [$bill, $competencia]), [
            'account_id' => $this->conta->id,
            'amount' => $valor,
        ]);
    }

    /** "Y-m" => valor, como o bloco de contas fixas de /faturas mostra. */
    private function naTela(): array
    {
        return app(FixedBillService::class)->currentAndOverdue($this->user->id)
            ->mapWithKeys(fn ($o) => [$o['competence']->format('Y-m') => $o['valor']])
            ->all();
    }

    /** "Y-m" => valor, como o sino avisa. */
    private function noSino(): array
    {
        return app(FaturaService::class)->upcomingDue($this->user->id)
            ->where('tipo', 'conta_fixa')
            ->mapWithKeys(fn ($i) => [$i['due']->format('Y-m') => $i['valor']])
            ->all();
    }

    // ── O defeito ────────────────────────────────────────────────────────────

    public function test_reajuste_em_julho_nao_muda_abril_maio_e_junho_em_aberto(): void
    {
        $this->em('2026-04-02');
        $aluguel = $this->cadastrar('1.500,00');

        $this->em('2026-07-15');
        $this->editar($aluguel, '1.600,00')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'a partir de julho/2026'));

        $esperado = ['2026-04' => 1500.0, '2026-05' => 1500.0, '2026-06' => 1500.0, '2026-07' => 1600.0];

        $this->assertSame($esperado, $this->naTela(), 'Os meses anteriores em aberto mudaram junto com o reajuste.');
        $this->assertSame($esperado, $this->noSino(), 'O sino avisou o valor novo em meses que tinham o antigo.');
        $this->assertSame(6100.0, app(FaturaService::class)->build($this->user->id)['stats']['totalContas']);

        // O que o formulário de edição mostra continua sendo o valor ATUAL.
        $this->assertSame('1600.00', (string) $aluguel->fresh()->amount);
    }

    /** O lembrete por e-mail monta o quadro com a MESMA lista do sino. */
    public function test_lembrete_por_email_cobra_junho_pelo_valor_de_junho(): void
    {
        $this->em('2026-04-02');
        $aluguel = $this->cadastrar('1.500,00');

        $this->em('2026-07-03');
        $this->editar($aluguel, '1.600,00')->assertSessionHasNoErrors();

        // 17/07 — julho venceu há 7 dias: é dia de lembrete, e junho vai no mesmo quadro.
        $this->em('2026-07-17');
        $quadro = LembretesDeVencimentos::selecionar(
            app(FaturaService::class)->upcomingDue($this->user->id, LembretesDeVencimentos::HORIZONTE_DIAS),
        );

        $vencidas = collect($quadro['vencidas'])->mapWithKeys(fn ($i) => [$i['due']->format('Y-m') => $i['valor']])->all();
        $this->assertSame(1500.0, $vencidas['2026-06']);
        $this->assertSame(1600.0, $vencidas['2026-07']);
    }

    /**
     * O teto de 3× do pagamento é o da COMPETÊNCIA. Com o valor atual, baixar o
     * aluguel para 400 em julho travava o pagamento de junho com os 1.500 de verdade
     * (teto 1.200) — sem saída pela tela, porque editar de novo não mexe em junho.
     */
    public function test_teto_de_tres_vezes_usa_o_previsto_da_competencia(): void
    {
        $this->em('2026-04-02');
        $aluguel = $this->cadastrar('1.500,00');

        $this->em('2026-07-15');
        $this->editar($aluguel, '400,00')->assertSessionHasNoErrors();

        $this->pagar($aluguel, '2026-06', '1.500,00')->assertSessionHasNoErrors();
        $this->assertTrue($aluguel->payments()->where('competence', '2026-06-01')->exists());

        // Julho já é do valor novo: 1.500 passa de 3 × 400.
        $this->pagar($aluguel, '2026-07', '1.500,00')
            ->assertSessionHasErrors(['amount' => 'O valor pago (R$ 1.500,00) é muito maior que o previsto para Aluguel em julho/2026 (R$ 400,00). Confira os centavos — se a conta mudou de valor de vez, edite a conta fixa antes de pagar.']);
        $this->assertFalse($aluguel->payments()->where('competence', '2026-07-01')->exists());
    }

    /** Numa competência passada, a mensagem não manda "editar a conta fixa": não adiantaria. */
    public function test_teto_de_competencia_passada_nao_manda_editar_a_conta(): void
    {
        $this->em('2026-04-02');
        $aluguel = $this->cadastrar('1.500,00');

        $this->em('2026-07-15');
        $this->editar($aluguel, '9.000,00')->assertSessionHasNoErrors();

        // Maio vale 1.500 (teto 4.500), mesmo com o valor atual em 9.000.
        $this->pagar($aluguel, '2026-05', '5.000,00')
            ->assertSessionHasErrors(['amount' => 'O valor pago (R$ 5.000,00) é muito maior que o previsto para Aluguel em maio/2026 (R$ 1.500,00). Confira os centavos — o previsto de maio/2026 é o valor que a conta tinha naquele mês (um reajuste feito agora vale só daqui em diante).']);
    }

    // ── A regra: correção × reajuste ─────────────────────────────────────────

    /**
     * Cadastrou em julho uma conta que começou em maio, com 18.000 no lugar de 1.800,
     * e corrigiu em seguida. O valor errado nunca foi o de mês fechado nenhum: a
     * correção vale para maio e junho também. Se virasse "histórico", o teto de 3×
     * travaria para sempre o pagamento de maio e junho com o valor certo.
     */
    public function test_correcao_de_digitacao_logo_depois_de_criar_vale_para_todas_as_competencias(): void
    {
        $this->em('2026-07-02');
        $aluguel = $this->cadastrar('18.000,00', inicio: '2026-05-01');

        $this->em('2026-07-03');
        $this->editar($aluguel, '1.800,00')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Conta fixa atualizada.');

        $this->assertSame(['2026-05' => 1800.0, '2026-06' => 1800.0, '2026-07' => 1800.0], $this->naTela());
        $this->assertNull($aluguel->fresh()->amount_history);

        $this->pagar($aluguel, '2026-05', '1.800,00')->assertSessionHasNoErrors();
    }

    /**
     * Errou o valor do REAJUSTE e corrigiu no mesmo mês: o passado continua intacto.
     *
     * (O histórico é comparado com `assertEquals`, não `assertSame`: o tipo `json` do
     * MySQL reordena as chaves de cada objeto ao gravar, e a ordem das chaves não é o
     * que está em teste — a ordem dos PERÍODOS, sim, e essa o JSON preserva.)
     */
    public function test_segunda_edicao_no_mesmo_mes_corrige_o_reajuste_sem_mexer_no_passado(): void
    {
        $this->em('2026-04-02');
        $aluguel = $this->cadastrar('1.500,00');

        $this->em('2026-07-15');
        $this->editar($aluguel, '1.060,00')->assertSessionHasNoErrors();
        $this->em('2026-07-16');
        $this->editar($aluguel, '1.600,00')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Conta fixa atualizada.');

        $this->assertSame(
            ['2026-04' => 1500.0, '2026-05' => 1500.0, '2026-06' => 1500.0, '2026-07' => 1600.0],
            $this->naTela(),
        );
        $this->assertEquals([['until' => '2026-06', 'amount' => '1500.00']], $aluguel->fresh()->amount_history);
    }

    public function test_reajustes_em_meses_diferentes_preservam_cada_periodo(): void
    {
        $this->em('2026-04-02');
        $aluguel = $this->cadastrar('1.500,00');

        $this->em('2026-07-15');
        $this->editar($aluguel, '1.600,00')->assertSessionHasNoErrors();

        $this->em('2026-09-15');
        $this->editar($aluguel, '1.700,00')->assertSessionHasNoErrors();

        $this->assertSame([
            '2026-04' => 1500.0, '2026-05' => 1500.0, '2026-06' => 1500.0,
            '2026-07' => 1600.0, '2026-08' => 1600.0,
            '2026-09' => 1700.0,
        ], $this->naTela());

        $this->assertEquals([
            ['until' => '2026-06', 'amount' => '1500.00'],
            ['until' => '2026-08', 'amount' => '1600.00'],
        ], $aluguel->fresh()->amount_history);
    }

    /** O modal manda o valor de volta mesmo quando só o nome mudou: isso não é reajuste. */
    public function test_editar_so_o_nome_nao_cria_historico(): void
    {
        $this->em('2026-04-02');
        $aluguel = $this->cadastrar('1.500,00');

        $this->em('2026-07-15');
        $this->editar($aluguel, '1.500,00', ['name' => 'Aluguel do apartamento'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Conta fixa atualizada.');

        $this->assertNull($aluguel->fresh()->amount_history);
        $this->assertSame('Aluguel do apartamento', $aluguel->fresh()->name);
    }

    /** Competência PAGA mostra o valor que foi pago, antes e depois do reajuste. */
    public function test_competencia_paga_continua_com_o_valor_pago(): void
    {
        $this->em('2026-04-02');
        $aluguel = $this->cadastrar('1.500,00');

        $this->em('2026-05-08');
        $this->pagar($aluguel, '2026-05', '1.480,00')->assertSessionHasNoErrors();

        $this->em('2026-07-15');
        $this->editar($aluguel, '1.600,00')->assertSessionHasNoErrors();

        $maio = app(FixedBillService::class)->occurrences(
            $this->user->id,
            CarbonImmutable::parse('2026-04-01'),
            CarbonImmutable::parse('2026-07-01'),
        )->mapWithKeys(fn ($o) => [$o['competence']->format('Y-m') => [$o['valor'], $o['paga']]])->all();

        $this->assertSame([
            '2026-04' => [1500.0, false],
            '2026-05' => [1480.0, true],
            '2026-06' => [1500.0, false],
            '2026-07' => [1600.0, false],
        ], $maio);
    }

    /**
     * Consequência ACEITA da regra, documentada aqui: a correção que cruza a virada
     * do mês já é reajuste. Criou em 30/06 com 150 no lugar de 1.500 e corrigiu em
     * 01/07: maio e junho ficam com os 150 digitados. A saída é pagar com o valor
     * real (editável, dentro do teto) ou, sem pagamento ainda, excluir e recadastrar.
     */
    public function test_correcao_que_cruza_a_virada_do_mes_fica_no_passado(): void
    {
        $this->em('2026-06-30');
        $aluguel = $this->cadastrar('150,00', inicio: '2026-05-01');

        $this->em('2026-07-01');
        $this->editar($aluguel, '1.500,00')->assertSessionHasNoErrors();

        $this->assertSame(['2026-05' => 150.0, '2026-06' => 150.0, '2026-07' => 1500.0], $this->naTela());
        $this->assertEquals([['until' => '2026-06', 'amount' => '150.00']], $aluguel->fresh()->amount_history);
    }

    /** O histórico não entra por atribuição em massa: só a regra escreve nele. */
    public function test_historico_nao_pode_ser_reescrito_pelo_formulario(): void
    {
        $this->em('2026-04-02');
        $aluguel = $this->cadastrar('1.500,00');

        $this->em('2026-07-15');
        $this->editar($aluguel, '1.600,00', [
            'amount_history' => [['until' => '2026-06', 'amount' => '1.00']],
        ])->assertSessionHasNoErrors();

        $this->assertEquals([['until' => '2026-06', 'amount' => '1500.00']], $aluguel->fresh()->amount_history);
    }

    // ── A migration ──────────────────────────────────────────────────────────

    public function test_migration_do_historico_e_reversivel(): void
    {
        $migration = require base_path('database/migrations/2026_09_22_100000_add_amount_history_to_fixed_bills.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('fixed_bills', 'amount_history'));
        $migration->down(); // reentrante

        $migration->up();
        $migration->up(); // reentrante
        $this->assertTrue(Schema::hasColumn('fixed_bills', 'amount_history'));
    }
}
