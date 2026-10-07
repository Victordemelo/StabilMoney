<?php

namespace Tests\Feature;

use App\Http\Requests\PayFixedBillRequest;
use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SimulaRequisicaoConcorrente;
use Tests\TestCase;

/**
 * Excluir a conta fixa enquanto alguém da família a paga (out/2026 — auditoria de
 * concorrência, pendência 4).
 *
 * A exclusão perguntava "tem pagamento?" sem trava, e o pagamento não travava a conta fixa.
 * Juntos, a exclusão via zero pagamentos, o pagamento entrava e a conta fixa era apagada:
 * a despesa ficava apontando para um `fixed_bill_id` que não existe (a coluna não tem
 * chave estrangeira, então o banco não barra). Contraria a própria regra da exclusão — com
 * pagamento, a conta fixa é DESATIVADA, para o histórico continuar explicável.
 *
 * Agora pagamento e exclusão pegam a mesma trava (a linha da conta fixa).
 */
class ContaFixaExcluidaDuranteOPagamentoTest extends TestCase
{
    use RefreshDatabase;
    use SimulaRequisicaoConcorrente;

    private User $user;

    private Account $corrente;

    private FixedBill $bill;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-15 10:00:00');
        $this->user = User::factory()->create();
        $this->corrente = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'initial_balance' => 2000, 'overdraft_limit' => 0,
        ]);
        $this->bill = FixedBill::create([
            'user_id' => $this->user->id, 'made_by_user_id' => $this->user->id, 'name' => 'Aluguel', 'amount' => 900,
            'due_day' => 10, 'account_id' => $this->corrente->id, 'starts_on' => '2026-07-01', 'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pagar(bool $json = false)
    {
        $dados = ['account_id' => $this->corrente->id, 'amount' => '900,00', 'paid_on' => '2026-08-15'];
        $cliente = $this->actingAs($this->user)->from(route('faturas.index'));

        return $json
            ? $cliente->postJson(route('contas-fixas.pagar', [$this->bill, '2026-08']), $dados)
            : $cliente->post(route('contas-fixas.pagar', [$this->bill, '2026-08']), $dados);
    }

    public function test_pagamento_de_conta_fixa_excluida_no_meio_nao_grava_despesa_orfa(): void
    {
        $this->depoisDaValidacaoDe(PayFixedBillRequest::class, fn () => FixedBill::whereKey($this->bill->id)->delete());

        $this->pagar()
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHasErrors(['amount' => 'A conta fixa “Aluguel” foi excluída enquanto você pagava. Nada foi pago.']);

        $this->assertSame(0, Transaction::count(), 'O pagamento ficou apontando para uma conta fixa que não existe.');
        $this->assertEqualsWithDelta(2000, $this->corrente->fresh()->available, 0.001);
    }

    public function test_pagamento_de_conta_fixa_desativada_no_meio_e_recusado_tambem_por_json(): void
    {
        $this->depoisDaValidacaoDe(PayFixedBillRequest::class, fn () => FixedBill::whereKey($this->bill->id)->update(['active' => false]));

        $this->pagar(json: true)->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_exclusao_confere_os_pagamentos_e_apaga_na_mesma_transacao(): void
    {
        $base = DB::transactionLevel();
        $gramatica = DB::getQueryGrammar();

        $consultas = $this->consultasDe(fn () => $this->actingAs($this->user)
            ->delete(route('contas-fixas.destroy', $this->bill), ['password' => 'password'])
            ->assertSessionHasNoErrors());

        $pagamentos = collect($consultas)->first(fn ($c) => str_contains($c['sql'], 'exists')
            && str_contains($c['sql'], $gramatica->wrapTable('transactions')));
        $apagou = collect($consultas)->first(fn ($c) => str_starts_with($c['sql'], 'delete from '.$gramatica->wrapTable('fixed_bills')));

        $this->assertNotNull($pagamentos);
        $this->assertNotNull($apagou);
        $this->assertGreaterThan($base, $pagamentos['nivel'], 'O "tem pagamento?" foi lido fora da transação da exclusão.');
        $this->assertGreaterThan($base, $apagou['nivel']);
        $this->assertModelMissing($this->bill);

        if (DB::getDriverName() === 'mysql') {
            $this->assertTrue(collect($consultas)->contains(fn ($c) => str_contains($c['sql'], 'for update')
                && str_contains($c['sql'], $gramatica->wrapTable('fixed_bills'))));
        }
    }

    public function test_com_pagamento_a_exclusao_so_desativa_como_antes(): void
    {
        $this->pagar()->assertSessionHasNoErrors();

        $this->actingAs($this->user)->delete(route('contas-fixas.destroy', $this->bill), ['password' => 'password'])
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Conta fixa desativada'));

        $this->assertFalse((bool) $this->bill->fresh()->active);
        $this->assertSame($this->bill->id, Transaction::sole()->fixed_bill_id);
    }
}
