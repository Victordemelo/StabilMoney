<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FaturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Recorrência de CARTÃO no ciclo certo (item 🔵 da auditoria de 02/09/2026).
 *
 * Antes, a ocorrência recorrente nascia datada no VENCIMENTO do cartão — fora
 * do ciclo aberto, invisível na lista até o ciclo virar — e cada clique em
 * "Pagar" na ocorrência mais nova empilhava uma cobrança a mais no futuro
 * (20/08, 20/09, 20/10…), todas consumindo limite de uma vez.
 *
 * Agora: a ocorrência é datada no DIA DA COMPRA; a próxima só nasce quando a
 * atual já pertence a um ciclo fechado (ou foi quitada com a fatura dela); e
 * ela cai no ciclo seguinte, uma por ciclo, como as parcelas.
 *
 * Cartão fecha dia 10 / vence dia 20.
 */
class RecorrenciaDeCartaoNoCicloTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-05');

        $this->user = User::factory()->create();
        $this->cartao = Account::factory()->for($this->user)->creditCard()->create([
            'name' => 'Nubank', 'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lancarRecorrente(string $data, string $valor = '49,90'): Transaction
    {
        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'client_uuid' => (string) Str::uuid(),
            'description' => 'Streaming',
            'amount' => $valor,
            'date' => $data,
            'account_id' => $this->cartao->id,
            'mode' => 'recorrente',
        ])->assertSessionHasNoErrors();

        return Transaction::where('description', 'Streaming')->orderBy('date')->firstOrFail();
    }

    private function avancar(Transaction $ocorrencia)
    {
        return $this->actingAs($this->user)->post(route('faturas.recorrente.pagar', $ocorrencia));
    }

    /** Datas (Y-m-d) de todas as ocorrências do grupo, em ordem. */
    private function datas(): array
    {
        return Transaction::where('description', 'Streaming')->orderBy('date')
            ->get()->map(fn ($t) => $t->date->toDateString())->all();
    }

    private function itensDoCicloAberto(): array
    {
        $card = app(FaturaService::class)->build($this->user->id)['cards']->first();

        return $card['items']->map(fn ($t) => $t->date->toDateString())->all();
    }

    private function committed(): float
    {
        return round((float) Account::find($this->cartao->id)->committed, 2);
    }

    public function test_ocorrencia_nasce_no_dia_da_compra_e_aparece_no_ciclo_aberto(): void
    {
        // Hoje 05/08: ciclo aberto = (10/07, 10/08].
        $ocorrencia = $this->lancarRecorrente('2026-08-01');

        $this->assertSame('2026-08-01', $ocorrencia->date->toDateString(), 'Nasce no dia da compra, não no vencimento.');
        $this->assertSame(['2026-08-01'], $this->itensDoCicloAberto(), 'Precisa aparecer na lista do cartão já no ciclo em que foi contratada.');
        $this->assertSame(49.90, $this->committed(), 'Só a ocorrência existente compromete limite.');
    }

    public function test_clique_no_ciclo_aberto_nao_gera_cadeia_futura(): void
    {
        $ocorrencia = $this->lancarRecorrente('2026-08-01');

        // Três cliques enquanto o ciclo (10/07, 10/08] ainda está aberto.
        for ($i = 0; $i < 3; $i++) {
            $this->avancar($ocorrencia)->assertRedirect(route('faturas.index'))
                ->assertSessionHas('status', fn ($msg) => str_contains($msg, 'ainda está na fatura aberta'));
        }

        $this->assertSame(['2026-08-01'], $this->datas(), 'Nenhuma ocorrência futura pode nascer por clique num ciclo que não fechou.');
        $this->assertNull($ocorrencia->fresh()->paid_at, 'Despesa de cartão só é quitada pela fatura.');
        $this->assertSame(49.90, $this->committed());
    }

    public function test_tres_meses_seguidos_uma_ocorrencia_por_ciclo(): void
    {
        $primeira = $this->lancarRecorrente('2026-08-01');

        // --- Setembro: o ciclo (10/07, 10/08] fechou. Avançar cai no ciclo aberto (10/08, 10/09].
        Carbon::setTestNow('2026-09-02');
        $this->avancar($primeira)->assertSessionHas('status', fn ($msg) => str_contains($msg, 'Próxima ocorrência lançada'));

        $this->assertSame(['2026-08-01', '2026-09-01'], $this->datas());
        $this->assertSame(['2026-09-01'], $this->itensDoCicloAberto(), 'A nova ocorrência tem de aparecer na lista do ciclo aberto.');
        $this->assertSame(99.80, $this->committed(), 'Duas ocorrências em aberto = duas vezes o valor comprometido.');

        // Clique repetido na antiga: a sucessora já existe, nada muda.
        $this->avancar($primeira)->assertSessionHas('status', fn ($msg) => str_contains($msg, 'já estava lançada'));
        // Clique na nova, cujo ciclo ainda está aberto: nada de futuro.
        $segunda = Transaction::where('description', 'Streaming')->orderByDesc('date')->firstOrFail();
        $this->avancar($segunda);
        $this->assertSame(['2026-08-01', '2026-09-01'], $this->datas(), 'Clique repetido não pode gerar cadeia futura.');

        // --- Outubro: (10/08, 10/09] fechou; a de 01/09 é quem gera a de 01/10.
        Carbon::setTestNow('2026-10-02');
        $this->avancar($segunda);
        $this->assertSame(['2026-08-01', '2026-09-01', '2026-10-01'], $this->datas());
        $this->assertSame(['2026-10-01'], $this->itensDoCicloAberto());
        $this->assertSame(149.70, $this->committed());

        // O bloco "recorrências para avançar" some assim que a sucessora existe.
        $card = app(FaturaService::class)->build($this->user->id)['cards']->first();
        $this->assertCount(0, $card['recorrenciasParaAvancar']);
    }

    public function test_recorrencia_fechada_sem_sucessora_tem_botao_na_tela(): void
    {
        $this->lancarRecorrente('2026-08-01');

        Carbon::setTestNow('2026-09-02');
        $card = app(FaturaService::class)->build($this->user->id)['cards']->first();

        $this->assertCount(1, $card['recorrenciasParaAvancar'], 'A ocorrência fechada precisa de um lugar na tela para ser avançada.');
        $this->assertSame([], $this->itensDoCicloAberto());

        $this->actingAs($this->user)->get(route('faturas.index'))
            ->assertOk()
            ->assertSee('Lançar neste ciclo')
            ->assertDontSee('Lançar próxima');
    }

    public function test_dia_da_compra_e_preservado_com_a_regra_de_uma_por_ciclo(): void
    {
        // Fecha dia 28: compra em 30/01 tem 28/02 dentro do MESMO ciclo (28/01, 28/02].
        $this->cartao->update(['closing_day' => 28, 'due_day' => 5]);
        Carbon::setTestNow('2026-02-05');
        $primeira = $this->lancarRecorrente('2026-01-30');

        Carbon::setTestNow('2026-03-02');
        $this->avancar($primeira);

        // 28/02 cairia no ciclo da 1ª: é empurrada para o 1º dia do ciclo seguinte.
        $this->assertSame(['2026-01-30', '2026-03-01'], $this->datas());

        // A 3ª volta ao dia original: 30/03 (compra + 2 meses) cai em (28/03, 28/04],
        // o ciclo seguinte ao da 2ª (28/02, 28/03] — sem colisão, sem empurrão.
        Carbon::setTestNow('2026-04-02');
        $segunda = Transaction::where('description', 'Streaming')->orderByDesc('date')->firstOrFail();
        $this->avancar($segunda);
        $this->assertSame(['2026-01-30', '2026-03-01', '2026-03-30'], $this->datas());
    }

    public function test_quitada_com_a_fatura_dela_pode_gerar_a_proxima(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
        $primeira = $this->lancarRecorrente('2026-08-01');

        // Paga a fatura do ciclo aberto antecipadamente (05/08).
        $this->actingAs($this->user)->post(route('faturas.fatura.pagar', $this->cartao), [
            'pay_account_id' => $conta->id, 'ciclo' => 'aberto', 'paid_on' => '2026-08-05',
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($primeira->fresh()->paid_at);

        $this->avancar($primeira);
        $this->assertSame(['2026-08-01', '2026-09-01'], $this->datas());
        $this->assertSame(49.90, $this->committed(), 'Só a ocorrência em aberto compromete limite.');
    }

    public function test_recorrencia_legada_em_conta_corrente_nao_muda(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
        $rec = Transaction::factory()->for($this->user)->for($conta)->expense()->create([
            'amount' => 100, 'date' => '2026-08-05', 'description' => 'Academia',
            'recurring' => true, 'group_id' => (string) Str::uuid(), 'paid_at' => null,
        ]);

        // Fora do cartão o clique marca paga e gera a próxima (+1 mês) — no mesmo dia, ciclo nenhum.
        $this->avancar($rec)->assertSessionHas('status', 'Recorrência paga — a próxima já foi lançada.');
        $this->assertNotNull($rec->fresh()->paid_at);

        $datas = Transaction::where('description', 'Academia')->orderBy('date')->get()
            ->map(fn ($t) => $t->date->toDateString())->all();
        $this->assertSame(['2026-08-05', '2026-09-05'], $datas);
    }
}
