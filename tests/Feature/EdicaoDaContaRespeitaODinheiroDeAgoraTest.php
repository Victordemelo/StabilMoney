<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SimulaRequisicaoConcorrente;
use Tests\TestCase;

/**
 * Editar a conta ao mesmo tempo que entra uma despesa (out/2026 — auditoria de concorrência,
 * pendência 2). As regras do piso (`available >= −overdraft_limit`) moram no
 * `UpdateAccountRequest` e rodam ANTES do controller, sem trava. Uma despesa que entrasse
 * entre a validação e o `update` usava o cheque especial antigo, e a edição gravava por cima
 * um limite (ou um saldo inicial) que deixava a conta abaixo do piso — um estado que nenhum
 * lançamento consegue produzir. Agora o controller trava a conta e refaz as duas contas.
 */
class EdicaoDaContaRespeitaODinheiroDeAgoraTest extends TestCase
{
    use RefreshDatabase;
    use SimulaRequisicaoConcorrente;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'bank' => 'itau',
            'initial_balance' => 1000, 'overdraft_limit' => 500,
        ]);
    }

    private function despesaConcorrente(float $valor): void
    {
        $this->depoisDaValidacaoDe(UpdateAccountRequest::class, function () use ($valor) {
            Transaction::factory()->for($this->user)->for($this->conta)->expense()->create(['amount' => $valor]);
        });
    }

    private function editar(string $inicial, string $limite, bool $json = false)
    {
        $dados = ['name' => 'Corrente', 'type' => 'checking', 'bank' => 'itau', 'initial_balance' => $inicial, 'overdraft_limit' => $limite];
        $cliente = $this->actingAs($this->user)->from(route('accounts.index'));

        return $json
            ? $cliente->patchJson(route('accounts.update', $this->conta), $dados)
            : $cliente->patch(route('accounts.update', $this->conta), $dados);
    }

    public function test_baixar_o_limite_enquanto_uma_despesa_usa_o_cheque_especial_e_recusado(): void
    {
        // Validação: conta em +1.000, zerar o limite passa. Entre ela e o update, uma despesa
        // de 1.200 usa 200 do cheque especial de 500.
        $this->despesaConcorrente(1200);

        $this->editar('1.000,00', '0,00')
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHasErrors(['overdraft_limit' => 'Esta conta está usando R$ 200,00 do cheque especial agora, então o limite não pode cair para R$ 0,00. Deixe pelo menos R$ 200,00 ou lance um recebimento para cobrir o saldo negativo antes de reduzir o limite.']);

        $conta = $this->conta->fresh();
        $this->assertSame('500.00', (string) $conta->overdraft_limit);
        $this->assertGreaterThanOrEqual(-(float) $conta->overdraft_limit, $conta->available);
    }

    public function test_baixar_o_saldo_inicial_enquanto_entra_uma_despesa_e_recusado_tambem_por_json(): void
    {
        $this->conta->update(['overdraft_limit' => 0]);
        // Validação: saldo inicial 100 deixa a conta em +100. A despesa de 300 que entra no meio
        // a levaria a −200 sem cheque especial.
        $this->despesaConcorrente(300);

        $this->editar('100,00', '0,00', json: true)
            ->assertStatus(422)
            ->assertJsonValidationErrors('initial_balance');

        $this->assertSame('1000.00', (string) $this->conta->fresh()->initial_balance);
    }

    public function test_sem_corrida_a_edicao_continua_gravando(): void
    {
        $this->editar('900,00', '200,00')->assertSessionHasNoErrors();

        $conta = $this->conta->fresh();
        $this->assertSame('900.00', (string) $conta->initial_balance);
        $this->assertSame('200.00', (string) $conta->overdraft_limit);
    }

    public function test_a_conferencia_e_a_gravacao_acontecem_na_mesma_transacao_com_a_conta_travada(): void
    {
        $base = DB::transactionLevel();
        $consultas = $this->consultasDe(fn () => $this->editar('900,00', '200,00')->assertSessionHasNoErrors());

        $update = collect($consultas)->first(fn ($c) => str_starts_with($c['sql'], 'update '.DB::getQueryGrammar()->wrapTable('accounts')));
        $this->assertNotNull($update);
        $this->assertGreaterThan($base, $update['nivel'], 'A conta foi gravada fora da transação da trava.');

        if (DB::getDriverName() === 'mysql') {
            $this->assertTrue(collect($consultas)->contains(fn ($c) => str_contains($c['sql'], 'for update')
                && str_contains($c['sql'], DB::getQueryGrammar()->wrapTable('accounts'))));
        }
    }
}
