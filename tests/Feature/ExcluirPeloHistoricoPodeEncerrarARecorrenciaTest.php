<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\EndedRecurrence;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use App\Support\FundingSource;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Excluir pelo Histórico uma cobrança de série recorrente pergunta se a recorrência
 * acaba junto.
 *
 * O defeito: `TransactionController::destroy` apagava só aquela linha e a série seguia
 * viva. A ocorrência anterior voltava a ser oferecida em "Recorrente · Lançar neste
 * ciclo" (/faturas) e um clique recriava justamente a cobrança que a pessoa tinha
 * apagado. Em /faturas, excluir a série já a ENCERRA (`EndedRecurrence`, R2-2); o
 * Histórico não tinha como.
 *
 * Decisão do Victor: depende — a exclusão pergunta. O formulário do Histórico ganha a
 * caixa "Encerrar também a recorrência", DESMARCADA:
 * - marcada: a cobrança sai (com o estorno da fonte, como sempre) e a série é encerrada
 *   na mesma transação de banco — sem ocorrência que tenha sobrado, não há marcador;
 * - desmarcada: só a cobrança sai, e a série continua (o comportamento de antes).
 *
 * Cartão fecha dia 10 / vence dia 20 (o cenário de RecorrenciaExcluidaNaoVoltaTest).
 */
class ExcluirPeloHistoricoPodeEncerrarARecorrenciaTest extends TestCase
{
    use RefreshDatabase;

    private const REMOVIDA_E_ENCERRADA = 'Transação removida e recorrência encerrada: nenhuma cobrança nova será lançada. As outras cobranças dela continuam no histórico.';

    private const SERIE_ENCERRADA = 'Esta recorrência foi excluída: nenhuma cobrança nova é lançada. As já pagas continuam no histórico.';

    private User $user;

    private Account $cartao;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-05');

        $this->user = User::factory()->create(['is_admin' => true]);
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create([
            'name' => 'Nubank', 'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 5000, 'overdraft_limit' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Ações ────────────────────────────────────────────────────────────────

    private function lancarRecorrente(string $data, string $descricao = 'Streaming'): Transaction
    {
        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'client_uuid' => (string) Str::uuid(),
            'description' => $descricao,
            'amount' => '49,90',
            'date' => $data,
            'account_id' => $this->cartao->id,
            'mode' => 'recorrente',
        ])->assertSessionHasNoErrors();

        return Transaction::where('description', $descricao)->orderByDesc('date')->firstOrFail();
    }

    private function avancar(Transaction $ocorrencia): TestResponse
    {
        return $this->actingAs($this->user)->post(route('faturas.recorrente.pagar', $ocorrencia));
    }

    private function pagarFatura(string $ciclo): void
    {
        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id, 'ciclo' => $ciclo,
        ])->assertSessionHasNoErrors();
    }

    /** Exclui pelo Histórico, como o formulário da tela de edição envia. */
    private function excluir(Transaction $linha, bool $encerrar, ?User $quem = null, array $corpo = []): TestResponse
    {
        return $this->actingAs($quem ?? $this->user)
            ->from(route('transactions.edit', $linha))
            ->delete(
                route('transactions.destroy', $linha),
                $corpo + ($encerrar ? ['encerrar_recorrencia' => '1'] : []),
            );
    }

    // ── Leituras ─────────────────────────────────────────────────────────────

    /** @return list<string> datas (Y-m-d) das ocorrências da série, em ordem */
    private function datas(string $descricao = 'Streaming'): array
    {
        return Transaction::where('description', $descricao)->orderBy('date')
            ->get()->map(fn ($t) => $t->date->toDateString())->all();
    }

    /** O bloco "Recorrente · Lançar neste ciclo" do cartão. */
    private function paraAvancar(): array
    {
        return app(FaturaService::class)->build($this->user->id)['cards']->first()['recorrenciasParaAvancar']
            ->map(fn ($t) => $t->description.' '.$t->date->toDateString())->all();
    }

    /** @return list<string> "tipo nome" de cada item do sino */
    private function sino(): array
    {
        return app(FaturaService::class)->upcomingDue($this->user->id)
            ->map(fn ($i) => $i['tipo'].' '.$i['nome'])->values()->all();
    }

    /**
     * Assinatura de 01/08; em setembro a fatura de agosto é paga e a de 01/09 é
     * lançada. Devolve [paga de agosto, em aberto de setembro].
     *
     * @return array{0: Transaction, 1: Transaction}
     */
    private function serieDoCartao(): array
    {
        $agosto = $this->lancarRecorrente('2026-08-01');

        Carbon::setTestNow('2026-09-02');
        $this->pagarFatura('fechado');
        $this->avancar($agosto)->assertSessionHas('status', fn ($msg) => str_contains($msg, 'Próxima ocorrência lançada'));

        $setembro = Transaction::where('description', 'Streaming')->where('date', '2026-09-01')->firstOrFail();
        $this->assertNotNull($agosto->fresh()->paid_at, 'Pré-condição: a de agosto foi paga com a fatura.');

        return [$agosto->fresh(), $setembro];
    }

    /**
     * Recorrência LEGADA em conta corrente (o app não cria mais, mas ela existe nos
     * dados antigos): 05/08 paga e 05/09 em aberto. Devolve [agosto, setembro].
     *
     * @return array{0: Transaction, 1: Transaction}
     */
    private function serieLegadaEmConta(?Account $conta = null, float $valor = 100): array
    {
        $agosto = Transaction::factory()->for($this->user)->for($conta ?? $this->conta)->expense()->create([
            'amount' => $valor, 'date' => '2026-08-05', 'description' => 'Academia',
            'recurring' => true, 'group_id' => (string) Str::uuid(), 'paid_at' => null,
        ]);
        $this->avancar($agosto)->assertSessionHas('status', 'Recorrência paga — a próxima já foi lançada.');

        $setembro = Transaction::where('description', 'Academia')->where('date', '2026-09-05')->firstOrFail();

        return [$agosto->fresh(), $setembro];
    }

    // ── O formulário (HTML servido) ──────────────────────────────────────────

    /** O formulário que EXCLUI esta linha na tela de edição (só o do `_method=DELETE`). */
    private function formularioDeExclusao(Transaction $linha, ?User $quem = null): array
    {
        $html = $this->actingAs($quem ?? $this->user)
            ->get(route('transactions.edit', $linha))
            ->assertOk()
            ->getContent();

        $doc = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        $xp = new DOMXPath($doc);
        $forms = $xp->query('//form[@action="'.route('transactions.destroy', $linha).'"][.//input[@name="_method" and @value="DELETE"]]');
        $this->assertSame(1, $forms->length, 'A tela de edição deveria ter um formulário de exclusão.');

        return [$xp, $forms->item(0)];
    }

    /** A caixa "Encerrar também a recorrência", ou null. Sempre DENTRO do formulário: funciona sem JS. */
    private function caixaDeEncerrar(Transaction $linha, ?User $quem = null): ?DOMElement
    {
        [$xp, $form] = $this->formularioDeExclusao($linha, $quem);

        $naPagina = $xp->query('//input[@name="encerrar_recorrencia"]')->length;
        $noFormulario = $xp->query('.//input[@name="encerrar_recorrencia"]', $form);
        $this->assertSame($naPagina, $noFormulario->length, 'A caixa precisa estar dentro do formulário de exclusão.');

        return $noFormulario->item(0);
    }

    // ── O defeito: a série continuava viva ───────────────────────────────────

    public function test_marcada_encerra_a_serie_e_ela_nao_volta_em_lancar_neste_ciclo(): void
    {
        $this->lancarRecorrente('2026-08-02', 'Música');
        [$agosto, $setembro] = $this->serieDoCartao();

        $this->excluir($setembro, encerrar: true)
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::REMOVIDA_E_ENCERRADA);

        $this->assertSame(['2026-08-01'], $this->datas(), 'A paga de agosto fica no histórico.');
        $this->assertDatabaseHas('ended_recurrences', [
            'user_id' => $this->user->id,
            'group_id' => $agosto->group_id,
            'ended_by_user_id' => $this->user->id,
        ]);

        // A série encerrada não é mais oferecida; a outra assinatura do cartão, sim.
        $this->assertSame(['Música 2026-08-02'], $this->paraAvancar());
        $this->actingAs($this->user)->get(route('faturas.index'))
            ->assertOk()
            ->assertDontSee('última cobrança em 01/08/2026', false);

        // E no mês seguinte também não: o encerramento não "vence".
        Carbon::setTestNow('2026-10-02');
        $this->assertSame(['Música 2026-08-02'], $this->paraAvancar());
    }

    public function test_marcada_o_clique_direto_em_lancar_neste_ciclo_e_recusado(): void
    {
        [$agosto, $setembro] = $this->serieDoCartao();
        $this->excluir($setembro, encerrar: true)->assertSessionHasNoErrors();

        $this->avancar($agosto)
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHas('status', self::SERIE_ENCERRADA);
        $this->assertSame(['2026-08-01'], $this->datas(), 'Um POST direto recriou a cobrança de uma série encerrada.');

        Carbon::setTestNow('2026-10-02');
        $this->avancar($agosto)->assertSessionHas('status', self::SERIE_ENCERRADA);
        $this->assertSame(['2026-08-01'], $this->datas());
    }

    /** O "depende": sem a caixa, a exclusão é a de sempre — a série continua andando. */
    public function test_desmarcada_so_a_cobranca_sai_e_a_serie_continua(): void
    {
        [$agosto, $setembro] = $this->serieDoCartao();

        $this->excluir($setembro, encerrar: false)
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Transação removida.');

        $this->assertSame(0, EndedRecurrence::count());
        $this->assertSame(['Streaming 2026-08-01'], $this->paraAvancar());

        $this->avancar($agosto)->assertSessionHas('status', fn ($msg) => str_contains($msg, 'Próxima ocorrência lançada'));
        $this->assertSame(['2026-08-01', '2026-09-01'], $this->datas());
    }

    // ── Recorrência legada em conta ──────────────────────────────────────────

    public function test_recorrencia_legada_em_conta_marcada_encerra_e_o_pagar_e_recusado(): void
    {
        [$agosto, $setembro] = $this->serieLegadaEmConta();
        $this->assertSame(4800.0, $this->conta->fresh()->available, 'Pré-condição: as duas cobranças já saíram do saldo.');

        $this->excluir($setembro, encerrar: true)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::REMOVIDA_E_ENCERRADA);

        $this->assertSame(4900.0, $this->conta->fresh()->available, 'A cobrança apagada volta para o saldo.');

        $this->avancar($agosto)->assertSessionHas('status', self::SERIE_ENCERRADA);
        $this->assertSame(['2026-08-05'], $this->datas('Academia'));
    }

    /**
     * Apagar a cobrança JÁ PAGA com a caixa marcada deixa de pé a que está em aberto — e
     * nela o "pagar" é recusado (a série não gera mais nada). No sino ela viraria um
     * aviso que nada resolve, "vencida" para sempre: série encerrada sai do sino. A
     * cobrança continua no saldo e no Histórico, como qualquer despesa.
     */
    public function test_legada_encerrada_sai_do_sino_mesmo_com_cobranca_em_aberto(): void
    {
        [$agosto, $setembro] = $this->serieLegadaEmConta();

        Carbon::setTestNow('2026-09-02'); // 05/09 entra na janela de 7 dias do sino
        $this->assertContains('recorrente Academia', $this->sino(), 'Pré-condição: a cobrança em aberto está no sino.');

        $this->excluir($agosto, encerrar: true)->assertSessionHas('status', self::REMOVIDA_E_ENCERRADA);

        $this->assertNotContains('recorrente Academia', $this->sino());
        Carbon::setTestNow('2026-09-20'); // já vencida: continua fora
        $this->assertNotContains('recorrente Academia', $this->sino());

        $this->avancar($setembro)->assertSessionHas('status', self::SERIE_ENCERRADA);
        $this->assertNull($setembro->fresh()->paid_at);
        $this->assertSame(['2026-09-05'], $this->datas('Academia'));
        $this->assertSame(4900.0, $this->conta->fresh()->available, 'O marcador não mexe em dinheiro.');
    }

    // ── A caixa no formulário ────────────────────────────────────────────────

    public function test_a_caixa_aparece_na_ocorrencia_recorrente_desmarcada_e_com_rotulo(): void
    {
        $agosto = $this->lancarRecorrente('2026-08-01');
        [, $form] = $this->formularioDeExclusao($agosto);
        $caixa = $this->caixaDeEncerrar($agosto);

        $this->assertNotNull($caixa, 'Ocorrência recorrente: a exclusão deveria perguntar pela recorrência.');
        $this->assertSame('checkbox', $caixa->getAttribute('type'));
        $this->assertSame('1', $caixa->getAttribute('value'));
        $this->assertFalse($caixa->hasAttribute('checked'), 'A caixa nasce DESMARCADA: o padrão é a exclusão de sempre.');

        // Rótulo de verdade: a caixa mora dentro do <label> com o texto.
        $rotulo = (new DOMXPath($caixa->ownerDocument))->query('ancestor::label', $caixa)->item(0);
        $this->assertNotNull($rotulo);
        $this->assertStringContainsString('Encerrar também a recorrência', $rotulo->textContent);
        $this->assertStringContainsString('nenhuma cobrança nova será lançada', $rotulo->textContent);

        // O "tem certeza?" diz o que acontece em cada caso (sm/confirmar.js).
        $this->assertSame(
            'Excluir esta cobrança? A recorrência continua ativa — só esta cobrança sai. Essa ação não pode ser desfeita.',
            $form->getAttribute('data-confirmar'),
        );
        $this->assertSame(
            'Excluir esta cobrança e encerrar a recorrência? Nenhuma cobrança nova será lançada. Essa ação não pode ser desfeita.',
            $caixa->getAttribute('data-confirmar-marcado'),
        );

        // Recorrência legada em conta, mesmo já paga: a exclusão é aceita, então a caixa aparece.
        [$academiaPaga] = $this->serieLegadaEmConta();
        $this->assertNotNull($this->caixaDeEncerrar($academiaPaga));
    }

    public function test_a_caixa_nao_aparece_em_parcela_avulsa_nem_transferencia(): void
    {
        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'client_uuid' => (string) Str::uuid(),
            'description' => 'Geladeira', 'amount' => '900,00', 'date' => '2026-08-01',
            'account_id' => $this->cartao->id, 'mode' => 'parcelado', 'installments' => 3,
        ])->assertSessionHasNoErrors();
        $parcela = Transaction::where('installment_no', 2)->firstOrFail();

        $avulsa = Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 80, 'date' => '2026-08-03', 'description' => 'Farmácia']);

        $poupanca = Account::factory()->for($this->user)->create(['type' => 'savings', 'initial_balance' => 0]);
        $this->actingAs($this->user)->post(route('transactions.transfer'), [
            'amount' => '300,00', 'account_id' => $this->conta->id, 'to_account_id' => $poupanca->id,
            'date' => '2026-08-05',
        ])->assertSessionHasNoErrors();
        $saida = Transaction::whereNotNull('transfer_group_id')->where('type', 'expense')->firstOrFail();

        $this->assertNull($this->caixaDeEncerrar($parcela), 'Parcela não é recorrência.');
        $this->assertNull($this->caixaDeEncerrar($saida), 'Transferência não é recorrência.');
        $this->assertNull($this->caixaDeEncerrar($avulsa), 'Despesa avulsa não é recorrência.');

        [, $form] = $this->formularioDeExclusao($avulsa);
        $this->assertSame('Excluir esta transação? Essa ação não pode ser desfeita.', $form->getAttribute('data-confirmar'));
    }

    /**
     * A caixa só aparece quando tem efeito. Compra de cartão já quitada não sai pelo
     * Histórico (guarda de sempre) — a caixa prometeria encerrar numa exclusão que não
     * acontece. E série já encerrada não tem o que encerrar.
     */
    public function test_a_caixa_nao_aparece_se_a_exclusao_e_recusada_ou_a_serie_ja_acabou(): void
    {
        [$agostoPago] = $this->serieDoCartao();
        $this->assertNull($this->caixaDeEncerrar($agostoPago));

        // E o POST com a caixa marcada é recusado pela guarda, sem encerrar nada.
        $this->excluir($agostoPago, encerrar: true)->assertSessionHasErrors('transaction');
        $this->assertDatabaseHas('transactions', ['id' => $agostoPago->id]);
        $this->assertSame(0, EndedRecurrence::count());

        // Série legada encerrada em Pagar despesas: a paga que sobrou não oferece a caixa.
        [$academiaPaga, $academiaAberta] = $this->serieLegadaEmConta();
        $this->actingAs($this->user)->delete(route('faturas.compra.destroy', $academiaAberta))->assertSessionHasNoErrors();
        $this->assertSame(1, EndedRecurrence::count(), 'Pré-condição: Pagar despesas encerrou a série.');

        $this->assertNull($this->caixaDeEncerrar($academiaPaga));
    }

    // ── Quem pode ────────────────────────────────────────────────────────────

    public function test_dependente_da_familia_tambem_encerra(): void
    {
        $dependente = User::factory()->create(['is_admin' => false, 'account_owner_id' => $this->user->id]);
        [$agosto, $setembro] = $this->serieDoCartao();

        $this->assertNotNull($this->caixaDeEncerrar($setembro, $dependente));

        $this->excluir($setembro, encerrar: true, quem: $dependente)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::REMOVIDA_E_ENCERRADA);

        // O marcador é da FAMÍLIA (titular); quem encerrou fica registrado.
        $this->assertDatabaseHas('ended_recurrences', [
            'user_id' => $this->user->id,
            'group_id' => $agosto->group_id,
            'ended_by_user_id' => $dependente->id,
        ]);
        $this->assertSame([], $this->paraAvancar());
    }

    public function test_outra_familia_nao_apaga_nem_encerra(): void
    {
        [, $setembro] = $this->serieDoCartao();
        $intruso = User::factory()->create(['is_admin' => true]);

        $resposta = $this->excluir($setembro, encerrar: true, quem: $intruso);

        // 404, como um id que não existe (IdAlheioNaRotaIgualAIdInexistenteTest) — e nada muda.
        $resposta->assertNotFound();
        $this->assertDatabaseHas('transactions', ['id' => $setembro->id]);
        $this->assertSame(0, EndedRecurrence::count());
    }

    // ── Idempotência e a última cobrança ─────────────────────────────────────

    public function test_duas_exclusoes_com_a_caixa_encerram_uma_vez_so(): void
    {
        [$agosto, $setembro] = $this->serieLegadaEmConta();
        $this->avancar($setembro)->assertSessionHas('status', 'Recorrência paga — a próxima já foi lançada.');
        $this->assertSame(['2026-08-05', '2026-09-05', '2026-10-05'], $this->datas('Academia'));

        $this->excluir($agosto, encerrar: true)->assertSessionHas('status', self::REMOVIDA_E_ENCERRADA);
        $this->excluir($setembro->fresh(), encerrar: true)->assertSessionHas('status', self::REMOVIDA_E_ENCERRADA);

        $this->assertSame(1, EndedRecurrence::count());
        $this->assertSame(['2026-10-05'], $this->datas('Academia'));
    }

    /** Sem ocorrência que tenha sobrado, não há de onde a série renascer — nem marcador. */
    public function test_a_ultima_cobranca_da_serie_sai_sem_marcador(): void
    {
        $agosto = $this->lancarRecorrente('2026-08-01');

        $this->excluir($agosto, encerrar: true)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Transação removida. Era a única cobrança que restava da recorrência — nenhuma nova será lançada.');

        $this->assertSame([], $this->datas());
        $this->assertSame(0, EndedRecurrence::count());
    }

    // ── O estorno da fonte continua ──────────────────────────────────────────

    /**
     * A cobrança de setembro foi corrigida para R$ 300 e só coube resgatando R$ 200 do
     * CDB. Excluí-la com a caixa marcada desfaz o resgate na mesma transação — senão o
     * aplicado encolheria para sempre, sem contrapartida.
     */
    public function test_o_estorno_da_fonte_continua_acontecendo(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente do CDB', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
        $cdb = Investment::create([
            'user_id' => $this->user->id, 'name' => 'CDB', 'classe' => 'renda_fixa', 'indexador' => 'cdi', 'taxa' => 100,
        ]);
        $cdb->contributions()->create([
            'account_id' => $conta->id, 'made_by_user_id' => $this->user->id,
            'type' => 'aporte', 'amount' => 800, 'date' => '2026-08-01',
        ]);

        [, $setembro] = $this->serieLegadaEmConta($conta);
        $this->assertSame(0.0, $conta->fresh()->available, 'Pré-condição: 1000 − 100 − 100 − 800 guardados.');

        $this->actingAs($this->user)->from(route('transactions.edit', $setembro))
            ->put(route('transactions.update', $setembro), [
                'type' => 'expense', 'amount' => '300,00', 'account_id' => $conta->id,
                'date' => '2026-09-05', 'description' => 'Academia',
                'funding_source' => FundingSource::RESGATE_INVESTIMENTO, 'funding_investment_id' => $cdb->id,
            ])->assertSessionHasNoErrors();
        $this->assertSame(600.0, $cdb->fresh()->aplicado, 'Pré-condição: R$ 200 resgatados para a cobrança.');

        $this->excluir($setembro->fresh(), encerrar: true)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::REMOVIDA_E_ENCERRADA);

        $this->assertSame(800.0, $cdb->fresh()->aplicado, 'O resgate tinha de ser desfeito junto com a cobrança.');
        $this->assertSame(0, $cdb->contributions()->where('type', 'resgate')->count());
        $this->assertSame(100.0, $conta->fresh()->available, 'Sobra só a cobrança de agosto: 1000 − 100 − 800.');
        $this->assertSame(1, EndedRecurrence::count());
    }

    // ── A caixa é validada só onde vale ─────────────────────────────────────

    public function test_valor_invalido_na_caixa_de_ocorrencia_recorrente_e_recusado_sem_apagar(): void
    {
        [, $setembro] = $this->serieDoCartao();

        $this->excluir($setembro, encerrar: false, corpo: ['encerrar_recorrencia' => 'talvez'])
            ->assertSessionHasErrors('encerrar_recorrencia');

        $this->assertDatabaseHas('transactions', ['id' => $setembro->id]);
        $this->assertSame(0, EndedRecurrence::count());
    }

    /**
     * Fora de ocorrência recorrente o campo é IGNORADO — nem validado: um 422 por uma
     * opção sem efeito barraria a exclusão à toa. E as guardas de sempre vêm antes de
     * tudo: a parcela isolada continua recusada, com ou sem a caixa.
     */
    public function test_fora_de_recorrencia_o_campo_e_ignorado_e_as_guardas_vem_antes(): void
    {
        $avulsa = Transaction::factory()->for($this->user)->for($this->conta)->expense()
            ->create(['amount' => 80, 'date' => '2026-08-03', 'description' => 'Farmácia']);

        $this->excluir($avulsa, encerrar: false, corpo: ['encerrar_recorrencia' => 'talvez'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Transação removida.');
        $this->assertDatabaseMissing('transactions', ['id' => $avulsa->id]);

        $poupanca = Account::factory()->for($this->user)->create(['type' => 'savings', 'initial_balance' => 0]);
        $this->actingAs($this->user)->post(route('transactions.transfer'), [
            'amount' => '300,00', 'account_id' => $this->conta->id, 'to_account_id' => $poupanca->id,
            'date' => '2026-08-05',
        ])->assertSessionHasNoErrors();
        $entrada = Transaction::whereNotNull('transfer_group_id')->where('type', 'income')->firstOrFail();

        $this->excluir($entrada, encerrar: true)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Transferência removida das duas contas.');
        $this->assertSame(0, Transaction::whereNotNull('transfer_group_id')->count());

        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'client_uuid' => (string) Str::uuid(),
            'description' => 'Geladeira', 'amount' => '900,00', 'date' => '2026-08-01',
            'account_id' => $this->cartao->id, 'mode' => 'parcelado', 'installments' => 3,
        ])->assertSessionHasNoErrors();
        $parcela = Transaction::where('installment_no', 2)->firstOrFail();

        $this->excluir($parcela, encerrar: true)->assertSessionHasErrors('transaction');
        $this->assertSame(3, Transaction::where('group_id', $parcela->group_id)->count());

        $this->assertSame(0, EndedRecurrence::count());
    }
}
