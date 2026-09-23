<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\EndedRecurrence;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Recorrência excluída não volta (R2-2 da auditoria financeira, rodada 2).
 *
 * O defeito: `faturas.compra.destroy` apaga as ocorrências EM ABERTO de uma série
 * recorrente e mantém as já pagas. Só que a paga mais recente continuava sendo "a
 * última da série": voltava em "Recorrente · Lançar neste ciclo"
 * (`FaturaService::recorrenciasParaAvancar`) e um clique recriava a sucessora. Não
 * existia marcador de série encerrada — a assinatura cancelada ressuscitava.
 *
 * A correção grava um marcador numa tabela à parte (`ended_recurrences`) que não
 * toca em nenhuma linha da série: os testes conferem que dinheiro e histórico pago
 * ficam idênticos.
 *
 * Cartão fecha dia 10 / vence dia 20 (o mesmo cenário de RecorrenciaDeCartaoNoCicloTest).
 */
class RecorrenciaExcluidaNaoVoltaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $cartao;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-05');

        $this->user = User::factory()->create();
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create([
            'name' => 'Nubank', 'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'initial_balance' => 5000, 'overdraft_limit' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    private function avancar(Transaction $ocorrencia)
    {
        return $this->actingAs($this->user)->post(route('faturas.recorrente.pagar', $ocorrencia));
    }

    private function excluir(Transaction $ocorrencia)
    {
        return $this->actingAs($this->user)->delete(route('faturas.compra.destroy', $ocorrencia));
    }

    private function pagarFatura(string $ciclo): void
    {
        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $this->conta->id, 'ciclo' => $ciclo,
        ])->assertSessionHasNoErrors();
    }

    /** @return list<string> datas (Y-m-d) das ocorrências da série, em ordem */
    private function datas(string $descricao = 'Streaming'): array
    {
        return Transaction::where('description', $descricao)->orderBy('date')
            ->get()->map(fn ($t) => $t->date->toDateString())->all();
    }

    private function paraAvancar(): array
    {
        return app(FaturaService::class)->build($this->user->id)['cards']->first()['recorrenciasParaAvancar']
            ->map(fn ($t) => $t->description.' '.$t->date->toDateString())->all();
    }

    /** Tudo que pode mudar dinheiro ou histórico, para comparar antes × depois. */
    private function fotografia(): array
    {
        return [
            'linhas' => Transaction::orderBy('id')->get()
                ->map(fn ($t) => $t->only(['id', 'type', 'amount', 'date', 'paid_at', 'settled_by_id', 'settles_account_id', 'recurring', 'group_id', 'account_id']))
                ->map(fn ($l) => array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, $l))
                ->all(),
            'disponivel' => Account::find($this->conta->id)->available,
            'comprometido' => round((float) Account::find($this->cartao->id)->committed, 2),
        ];
    }

    /**
     * Assinatura de 01/08; em setembro a fatura de agosto é paga e a de 01/09 é
     * lançada. Devolve [paga de agosto, em aberto de setembro].
     *
     * @return array{0: Transaction, 1: Transaction}
     */
    private function serieComUmaPagaEUmaEmAberto(): array
    {
        $agosto = $this->lancarRecorrente('2026-08-01');

        Carbon::setTestNow('2026-09-02');
        $this->pagarFatura('fechado');
        $this->avancar($agosto)->assertSessionHas('status', fn ($msg) => str_contains($msg, 'Próxima ocorrência lançada'));

        $setembro = Transaction::where('description', 'Streaming')->where('date', '2026-09-01')->firstOrFail();
        $this->assertNotNull($agosto->fresh()->paid_at, 'Pré-condição: a de agosto foi paga com a fatura.');

        return [$agosto->fresh(), $setembro];
    }

    // ── O defeito ────────────────────────────────────────────────────────────

    public function test_serie_excluida_nao_volta_em_lancar_neste_ciclo(): void
    {
        [$agosto, $setembro] = $this->serieComUmaPagaEUmaEmAberto();

        $this->excluir($setembro)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Recorrência excluída: 1 cobrança em aberto removida. As já pagas continuam no histórico, e nenhuma nova será lançada.');

        $this->assertSame(['2026-08-01'], $this->datas(), 'A paga de agosto fica no histórico.');
        $this->assertSame([], $this->paraAvancar(), 'A série excluída voltou a oferecer "Lançar neste ciclo".');

        $this->actingAs($this->user)->get(route('faturas.index'))
            ->assertOk()
            ->assertDontSee('Lançar neste ciclo');

        // E no mês seguinte também não: o encerramento não "vence".
        Carbon::setTestNow('2026-10-02');
        $this->assertSame([], $this->paraAvancar());
    }

    public function test_clique_na_ocorrencia_que_sobrou_nao_recria_a_serie(): void
    {
        [$agosto, $setembro] = $this->serieComUmaPagaEUmaEmAberto();
        $this->excluir($setembro)->assertSessionHasNoErrors();

        $antes = $this->fotografia();

        $this->avancar($agosto)
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHas('erro', 'Esta recorrência foi encerrada: nenhuma cobrança nova é lançada. As já pagas continuam no histórico.');

        $this->assertSame(['2026-08-01'], $this->datas(), 'Um clique recriou a ocorrência de uma série excluída.');
        $this->assertSame($antes, $this->fotografia());

        Carbon::setTestNow('2026-10-02');
        $this->avancar($agosto);
        $this->assertSame(['2026-08-01'], $this->datas());
    }

    /**
     * O marcador não muda dinheiro nem histórico: excluir a série só apaga o que está
     * em aberto (como sempre) — a paga de agosto, a quitação que a pagou, o saldo da
     * conta e o limite do cartão ficam exatamente como estavam, fora a cobrança apagada.
     */
    public function test_encerrar_nao_toca_na_ocorrencia_paga_nem_no_dinheiro(): void
    {
        [$agosto, $setembro] = $this->serieComUmaPagaEUmaEmAberto();

        $antes = $this->fotografia();
        $this->excluir($setembro)->assertSessionHasNoErrors();
        $depois = $this->fotografia();

        // Só a linha de setembro saiu; as demais são idênticas, campo a campo.
        $semSetembro = array_values(array_filter($antes['linhas'], fn ($l) => $l['id'] !== $setembro->id));
        $this->assertSame($semSetembro, $depois['linhas']);

        // Saldo da conta: igual (a de setembro era do cartão). Limite: voltou só os 49,90 dela.
        $this->assertSame($antes['disponivel'], $depois['disponivel']);
        $this->assertSame(round($antes['comprometido'] - 49.90, 2), $depois['comprometido']);

        $agostoDepois = $agosto->fresh();
        $this->assertTrue($agostoDepois->recurring, 'O selo "Recorrente" da paga não pode mudar.');
        $this->assertNotNull($agostoDepois->settled_by_id);
        $this->assertSame(1, EndedRecurrence::where('group_id', $agosto->group_id)->count());
    }

    /**
     * Série toda paga, sem sucessora — o estado normal de uma assinatura entre um
     * ciclo e outro. Excluir era RECUSADO ("estorne o pagamento primeiro"), ou seja,
     * desfazer uma cobrança que aconteceu de verdade só para parar as próximas. Agora
     * encerra sem apagar nada.
     */
    public function test_serie_toda_paga_e_encerrada_sem_apagar_nada(): void
    {
        $agosto = $this->lancarRecorrente('2026-08-01');
        Carbon::setTestNow('2026-09-02');
        $this->pagarFatura('fechado');

        $this->assertSame(['Streaming 2026-08-01'], $this->paraAvancar(), 'Pré-condição: a série aparece para avançar.');
        $antes = $this->fotografia();

        $this->excluir($agosto->fresh())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Recorrência encerrada: nenhuma cobrança nova será lançada. As já pagas continuam no histórico.');

        $this->assertSame($antes, $this->fotografia(), 'Encerrar mexeu em dinheiro ou no histórico pago.');
        $this->assertSame([], $this->paraAvancar());
    }

    /** A ocorrência paga ANTES do fechamento (fatura antecipada) mostra "Lançar próxima". */
    public function test_lancar_proxima_de_serie_encerrada_e_recusado(): void
    {
        $agosto = $this->lancarRecorrente('2026-08-01');
        $this->pagarFatura('aberto'); // paga em 05/08, com o ciclo ainda aberto

        $this->avancar($agosto->fresh()); // "Lançar próxima": nasce a de 01/09
        $this->assertSame(['2026-08-01', '2026-09-01'], $this->datas());

        $setembro = Transaction::where('date', '2026-09-01')->firstOrFail();
        $this->excluir($setembro)->assertSessionHasNoErrors();

        // A tela já não oferece o botão (ver o teste seguinte), mas quem decide é o
        // servidor: um POST de uma aba aberta antes da exclusão chega do mesmo jeito.
        $this->avancar($agosto->fresh())
            ->assertSessionHas('erro', fn ($msg) => str_contains($msg, 'recorrência foi encerrada'));
        $this->assertSame(['2026-08-01'], $this->datas());
    }

    /**
     * A tela não oferece "Lançar próxima" numa série encerrada: o botão aparecia na
     * ocorrência paga que sobrou e só existia para o servidor recusar o clique. A outra
     * recorrência do cartão continua com o dela.
     *
     * A decisão sai de UMA consulta às séries encerradas da família (`FaturaService`), não
     * de uma por ocorrência: o número de consultas a `ended_recurrences` não cresce com a
     * lista.
     */
    public function test_a_tela_nao_oferece_lancar_proxima_em_serie_encerrada(): void
    {
        $streaming = $this->lancarRecorrente('2026-08-01', 'Streaming');
        $musica = $this->lancarRecorrente('2026-08-02', 'Música');
        $this->pagarFatura('aberto'); // em 05/08, ciclo ainda aberto: as duas oferecem "Lançar próxima"

        $this->assertSame(['Streaming', 'Música'], $this->comLancarProxima());

        $this->excluir($streaming->fresh())->assertSessionHasNoErrors();
        $this->assertTrue(EndedRecurrence::daSerie($streaming), 'Pré-condição: a série ficou encerrada.');

        $consultas = $this->consultasAsSeriesEncerradas(fn () => $this->assertSame(['Música'], $this->comLancarProxima()));

        // Mais duas séries pagas na lista — uma delas encerrada — e as consultas não mudam.
        $nuvem = $this->lancarRecorrente('2026-08-03', 'Nuvem');
        $this->lancarRecorrente('2026-08-04', 'Jornal');
        $this->pagarFatura('aberto');
        $this->excluir($nuvem->fresh())->assertSessionHasNoErrors();

        $this->assertSame(
            $consultas,
            $this->consultasAsSeriesEncerradas(fn () => $this->assertSame(['Música', 'Jornal'], $this->comLancarProxima())),
            'A tela passou a consultar as séries encerradas uma vez por ocorrência.',
        );
        $this->assertNotNull($musica->fresh()->paid_at);
    }

    /** Descrições das linhas do cartão que a tela renderizou com "Lançar próxima", na ordem da data. */
    private function comLancarProxima(): array
    {
        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        return Transaction::where('account_id', $this->cartao->id)->orderBy('date')->get()
            ->filter(fn (Transaction $t) => str_contains($html, 'action="'.route('faturas.recorrente.pagar', $t).'"'))
            ->map(fn (Transaction $t) => $t->description)
            ->values()
            ->all();
    }

    /** Quantas consultas a `ended_recurrences` a `$acao` fez. */
    private function consultasAsSeriesEncerradas(callable $acao): int
    {
        $total = 0;
        DB::listen(function ($consulta) use (&$total) {
            if (str_contains($consulta->sql, 'ended_recurrences')) {
                $total++;
            }
        });
        $acao();

        return $total;
    }

    /**
     * A corrida: a série é encerrada DEPOIS da guarda do topo do `pay()` e ANTES de o
     * FundingService travar a conta (outra aba excluiu a série nesse meio-tempo). É a
     * checagem de dentro do `$write`, sob a trava, que impede a sucessora.
     *
     * A exclusão concorrente é simulada no primeiro carregamento da conta do cartão
     * durante o clique — que acontece depois da guarda e antes do `spend()`.
     */
    public function test_encerrada_enquanto_o_clique_esperava_a_trava_nao_gera_a_proxima(): void
    {
        $agosto = $this->lancarRecorrente('2026-08-01');
        Carbon::setTestNow('2026-09-02');
        $this->pagarFatura('fechado');
        $agosto = $agosto->fresh();

        $armado = true;
        Account::retrieved(function (Account $conta) use (&$armado, $agosto) {
            if ($armado && $conta->id === $this->cartao->id) {
                $armado = false;
                EndedRecurrence::create(['user_id' => $agosto->user_id, 'group_id' => $agosto->group_id]);
            }
        });

        $this->avancar($agosto)
            ->assertSessionHas('erro', 'Esta recorrência foi encerrada: nenhuma cobrança nova é lançada. As já pagas continuam no histórico.');

        $this->assertFalse($armado, 'A exclusão simulada não chegou a acontecer — o teste não provaria nada.');
        $this->assertSame(['2026-08-01'], $this->datas(), 'A sucessora nasceu numa série encerrada durante o clique.');
    }

    // ── O que NÃO muda ───────────────────────────────────────────────────────

    public function test_outra_recorrencia_do_mesmo_cartao_continua_andando(): void
    {
        $this->lancarRecorrente('2026-08-01', 'Streaming');
        $musica = $this->lancarRecorrente('2026-08-02', 'Música');

        Carbon::setTestNow('2026-09-02');
        $this->pagarFatura('fechado');

        $streaming = Transaction::where('description', 'Streaming')->firstOrFail();
        $this->excluir($streaming)->assertSessionHasNoErrors();

        $this->assertSame(['Música 2026-08-02'], $this->paraAvancar());

        $this->avancar($musica->fresh())
            ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'Próxima ocorrência lançada'));
        $this->assertSame(['2026-08-02', '2026-09-02'], $this->datas('Música'));
    }

    /** Nada pago: a série some inteira e não há marcador a guardar. */
    public function test_serie_sem_nada_pago_some_inteira_sem_marcador(): void
    {
        $agosto = $this->lancarRecorrente('2026-08-01');

        $this->excluir($agosto)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Recorrência removida (todas as cobranças).');

        $this->assertSame([], $this->datas());
        $this->assertSame(0, EndedRecurrence::count());
    }

    /** Parcelado não é série recorrente: nada de marcador, mensagem de sempre. */
    public function test_parcelado_nao_ganha_marcador(): void
    {
        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'client_uuid' => (string) Str::uuid(),
            'description' => 'Geladeira',
            'amount' => '900,00',
            'date' => '2026-08-01',
            'account_id' => $this->cartao->id,
            'mode' => 'parcelado',
            'installments' => 3,
        ])->assertSessionHasNoErrors();

        $this->excluir(Transaction::where('installment_no', 2)->firstOrFail())
            ->assertSessionHas('status', 'Compra removida (todas as parcelas).');

        $this->assertSame(0, EndedRecurrence::count());
    }

    /** Recorrência legada em conta corrente: excluída, o clique não diz mais "a próxima já foi lançada". */
    public function test_recorrencia_legada_em_conta_excluida_nao_gera_mais(): void
    {
        $rec = Transaction::factory()->for($this->user)->for($this->conta)->expense()->create([
            'amount' => 100, 'date' => '2026-08-05', 'description' => 'Academia',
            'recurring' => true, 'group_id' => (string) Str::uuid(), 'paid_at' => null,
        ]);
        $this->avancar($rec)->assertSessionHas('status', 'Recorrência paga — a próxima já foi lançada.');
        $this->assertSame(['2026-08-05', '2026-09-05'], $this->datas('Academia'));

        $this->excluir(Transaction::where('date', '2026-09-05')->firstOrFail())->assertSessionHasNoErrors();

        $this->avancar($rec->fresh())
            ->assertSessionHas('erro', 'Esta recorrência foi encerrada: nenhuma cobrança nova é lançada. As já pagas continuam no histórico.');
        $this->assertSame(['2026-08-05'], $this->datas('Academia'));
    }

    /** Excluir duas vezes (duplo clique) encerra uma vez só. */
    public function test_encerrar_duas_vezes_nao_duplica_o_marcador(): void
    {
        $agosto = $this->lancarRecorrente('2026-08-01');
        Carbon::setTestNow('2026-09-02');
        $this->pagarFatura('fechado');

        $this->excluir($agosto->fresh())->assertSessionHasNoErrors();
        $this->excluir($agosto->fresh())->assertSessionHasNoErrors();

        $this->assertSame(1, EndedRecurrence::count());
    }

    /** O marcador de uma família não encerra série de outra, nem com o mesmo group_id. */
    public function test_marcador_de_outra_familia_nao_encerra_a_serie(): void
    {
        $agosto = $this->lancarRecorrente('2026-08-01');
        Carbon::setTestNow('2026-09-02');
        $this->pagarFatura('fechado');

        $outra = User::factory()->create();
        EndedRecurrence::create(['user_id' => $outra->id, 'group_id' => $agosto->group_id]);

        $this->assertSame(['Streaming 2026-08-01'], $this->paraAvancar());
        $this->avancar($agosto->fresh())->assertSessionHas('status', fn ($msg) => str_contains($msg, 'Próxima ocorrência lançada'));
    }

    // ── A migration ──────────────────────────────────────────────────────────

    public function test_migration_dos_marcadores_e_reversivel(): void
    {
        $migration = require base_path('database/migrations/2026_09_22_100100_create_ended_recurrences_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('ended_recurrences'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('ended_recurrences'));
        $this->assertTrue(Schema::hasIndex('ended_recurrences', ['user_id', 'group_id'], 'unique'));
    }
}
