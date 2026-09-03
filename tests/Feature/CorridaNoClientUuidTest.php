<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Corrida no `client_uuid` em `transactions.store` e na transferência.
 *
 * O dedupe tem duas camadas: um fast-path (SELECT por `(user_id, client_uuid)`
 * antes do guard) e o índice único do banco. Duas requisições idênticas que
 * chegam JUNTAS passam as duas pelo fast-path — nenhuma tinha gravado ainda —
 * e a segunda estoura o índice no INSERT. Antes isso era um 500 (com o
 * lançamento já gravado pela primeira); agora o controller captura a
 * `UniqueConstraintViolationException` e responde como duplicata, igual ao
 * `faturas.lancar`.
 *
 * COMO A CORRIDA É SIMULADA: um `DB::listen` que, logo depois do SELECT do
 * fast-path (que não achou nada), insere a linha "da outra requisição" com o
 * mesmo uuid. O listener roda ANTES de o `FundingService::spend` abrir a
 * `DB::transaction`, então a linha concorrente fica FORA do savepoint que o
 * rollback desfaz — exatamente como a linha commitada pela outra requisição em
 * produção. É mais fiel do que um mock do `spend` lançando a exceção (que não
 * exercitaria o INSERT real nem o rollback) e do que um hook `creating` (que
 * inseriria DENTRO da transação e sumiria no rollback, deixando zero linhas).
 */
class CorridaNoClientUuidTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private Account $corrente;

    private Account $poupanca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $this->corrente = Account::factory()->for($this->titular)->create([
            'name' => 'Corrente', 'type' => 'checking', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
        $this->poupanca = Account::factory()->for($this->titular)->create([
            'name' => 'Poupança', 'type' => 'savings', 'initial_balance' => 1000,
        ]);
    }

    /**
     * Arma a "outra requisição": depois do primeiro SELECT pelo `client_uuid`
     * (o fast-path, que sai vazio), grava a linha concorrente com o mesmo uuid.
     *
     * @param  array<string, mixed>  $linha
     */
    private function armarConcorrente(string $uuid, array $linha): void
    {
        $disparou = false;

        DB::listen(function (QueryExecuted $q) use (&$disparou, $uuid, $linha) {
            if ($disparou || ! str_starts_with($q->sql, 'select') || ! str_contains($q->sql, '"client_uuid"')) {
                return;
            }
            // Flag ANTES do insert: o insert também passa pelo listener.
            $disparou = true;

            Transaction::create($linha + [
                'user_id' => $this->titular->id,
                'made_by_user_id' => $this->titular->id,
                'client_uuid' => $uuid,
                'date' => CarbonImmutable::today()->toDateString(),
            ]);
        });
    }

    public function test_despesa_comum_em_corrida_responde_como_duplicata_e_deixa_uma_linha_so(): void
    {
        $uuid = (string) Str::uuid();
        $this->armarConcorrente($uuid, [
            'account_id' => $this->corrente->id,
            'type' => 'expense',
            'amount' => 150,
            'description' => 'Mercado (a que chegou primeiro)',
        ]);

        $this->actingAs($this->titular)->postJson(route('transactions.store'), [
            'client_uuid' => $uuid,
            'type' => 'expense',
            'amount' => '150,00',
            'account_id' => $this->corrente->id,
            'date' => CarbonImmutable::today()->toDateString(),
            'description' => 'Mercado (a perdedora)',
        ])
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('client_uuid', $uuid);

        $linhas = Transaction::where('user_id', $this->titular->id)->get();
        $this->assertCount(1, $linhas);
        $this->assertSame('Mercado (a que chegou primeiro)', $linhas->first()->description);
        $this->assertSame(850.0, $this->corrente->fresh()->available, 'a despesa conta UMA vez');
    }

    /** Mesma corrida pela web comum (form da página cheia): redirect com flash, nunca 500. */
    public function test_despesa_comum_em_corrida_pela_web_redireciona_com_flash(): void
    {
        $uuid = (string) Str::uuid();
        $this->armarConcorrente($uuid, [
            'account_id' => $this->corrente->id,
            'type' => 'expense',
            'amount' => 150,
        ]);

        $this->actingAs($this->titular)->post(route('transactions.store'), [
            'client_uuid' => $uuid,
            'type' => 'expense',
            'amount' => '150,00',
            'account_id' => $this->corrente->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(1, Transaction::count());
    }

    /** Receita também tem `client_uuid` e o mesmo índice único. */
    public function test_receita_em_corrida_responde_como_duplicata(): void
    {
        $uuid = (string) Str::uuid();
        $this->armarConcorrente($uuid, [
            'account_id' => $this->corrente->id,
            'type' => 'income',
            'amount' => 500,
        ]);

        $this->actingAs($this->titular)->postJson(route('transactions.store'), [
            'client_uuid' => $uuid,
            'type' => 'income',
            'amount' => '500,00',
            'account_id' => $this->corrente->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ])->assertOk()->assertJsonPath('created', false);

        $this->assertSame(1, Transaction::count());
        $this->assertSame(1500.0, $this->corrente->fresh()->available);
    }

    /**
     * A perdedora tinha escolhido resgatar do investimento: o rollback da
     * `DB::transaction` do `spend` desfaz o resgate junto — o investido não pode
     * encolher por uma despesa que não ficou gravada.
     */
    public function test_corrida_com_resgate_nao_deixa_resgate_orfao(): void
    {
        $inv = Investment::create([
            'user_id' => $this->titular->id, 'name' => 'CDB', 'classe' => 'renda_fixa', 'indexador' => 'cdi', 'taxa' => 100,
        ]);
        $inv->contributions()->create([
            'account_id' => $this->corrente->id, 'made_by_user_id' => $this->titular->id,
            'type' => 'aporte', 'amount' => 900, 'date' => CarbonImmutable::today()->toDateString(),
        ]);
        // 100 disponíveis; despesa de 400 precisa resgatar 300.

        $uuid = (string) Str::uuid();
        $this->armarConcorrente($uuid, [
            'account_id' => $this->corrente->id,
            'type' => 'expense',
            'amount' => 50,
        ]);

        $this->actingAs($this->titular)->postJson(route('transactions.store'), [
            'client_uuid' => $uuid,
            'type' => 'expense',
            'amount' => '400,00',
            'account_id' => $this->corrente->id,
            'date' => CarbonImmutable::today()->toDateString(),
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
            'funding_investment_id' => $inv->id,
        ])->assertOk()->assertJsonPath('created', false);

        $this->assertSame(1, Transaction::count());
        $this->assertSame(0, $inv->contributions()->where('type', 'resgate')->count(), 'o resgate foi desfeito com o rollback');
        $this->assertSame(900.0, $inv->fresh()->aplicado);
    }

    public function test_transferencia_em_corrida_responde_como_duplicata_e_deixa_um_par_so(): void
    {
        $uuid = (string) Str::uuid();
        // A "outra requisição" gravou o par inteiro; o uuid vive na SAÍDA.
        $grupo = (string) Str::uuid();
        $this->armarConcorrente($uuid, [
            'account_id' => $this->corrente->id,
            'type' => 'expense',
            'amount' => 300,
            'transfer_group_id' => $grupo,
            'description' => 'Transferência para Poupança',
        ]);

        $resposta = $this->actingAs($this->titular)->postJson(route('transactions.transfer'), [
            'client_uuid' => $uuid,
            'amount' => '300,00',
            'account_id' => $this->corrente->id,
            'to_account_id' => $this->poupanca->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $resposta->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('transfer_group_id', $grupo);

        // Só a linha da concorrente sobrou: nem a saída nem a entrada da perdedora.
        $this->assertSame(1, Transaction::count());
        $this->assertSame(1000.0, $this->poupanca->fresh()->available, 'a entrada da perdedora não ficou de pé');
    }

    /** Sem corrida, o caminho comum continua gravando normalmente (o catch não engole nada). */
    public function test_sem_corrida_o_lancamento_e_criado(): void
    {
        $uuid = (string) Str::uuid();

        $this->actingAs($this->titular)->postJson(route('transactions.store'), [
            'client_uuid' => $uuid,
            'type' => 'expense',
            'amount' => '150,00',
            'account_id' => $this->corrente->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ])->assertCreated()->assertJsonPath('created', true);

        $this->assertSame(1, Transaction::count());
    }
}
