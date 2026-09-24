<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reenviar o pagamento de uma competência JÁ PAGA responde "já estava paga" — e não
 * pergunta de onde sai o dinheiro nem diz que falta saldo (achado do teste de
 * propriedades de 24/09/2026).
 *
 * A idempotência do pagamento de conta fixa é o índice único (fixed_bill_id,
 * competence), que só age no INSERT — depois da trava de gasto. O reenvio (duplo
 * clique, "voltar" do navegador) passava pelo guard com o saldo que o PRIMEIRO
 * pagamento já tinha consumido: com cheque especial, respondia 409 "de onde sai esse
 * dinheiro?" por uma conta paga; sem ele e antes do vencimento, 422 "Saldo
 * insuficiente". Nenhum dinheiro saía (o INSERT batia no índice e tudo era desfeito),
 * mas a pessoa era levada a escolher uma fonte — ou a lançar um recebimento — para
 * pagar de novo o que já estava pago. Os outros caminhos de gasto conferem a
 * duplicata ANTES do guard; este passou a conferir também.
 */
class ReenvioDoPagamentoDeContaFixaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Account, 2: FixedBill} */
    private function contaFixa(float $cheque, int $vence): array
    {
        Carbon::setTestNow('2026-08-15 10:00:00');
        $user = User::factory()->create();
        $corrente = Account::factory()->for($user)->create([
            'type' => 'checking', 'initial_balance' => 900, 'overdraft_limit' => $cheque,
        ]);
        $bill = FixedBill::create([
            'user_id' => $user->id, 'made_by_user_id' => $user->id, 'name' => 'Aluguel', 'amount' => 900,
            'due_day' => $vence, 'account_id' => $corrente->id, 'starts_on' => '2026-07-01', 'active' => true,
        ]);

        return [$user, $corrente, $bill];
    }

    private function pagar(User $user, FixedBill $bill, Account $conta)
    {
        return $this->actingAs($user)->postJson(route('contas-fixas.pagar', [$bill, '2026-08']), [
            'account_id' => $conta->id, 'amount' => '900,00', 'paid_on' => '2026-08-15',
        ]);
    }

    private function retrato(): array
    {
        return [
            DB::table('transactions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('investment_contributions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    public function test_reenvio_com_cheque_especial_nao_pergunta_a_fonte_de_novo(): void
    {
        [$user, $corrente, $bill] = $this->contaFixa(cheque: 1000, vence: 10);
        $this->pagar($user, $bill, $corrente)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0.0, $corrente->fresh()->available);
        $antes = $this->retrato();

        // ANTES: 409, pedindo cheque especial para pagar de novo o aluguel já pago.
        $this->pagar($user, $bill, $corrente)->assertRedirect()->assertSessionHas('status', 'Esta competência já estava paga.');

        $this->assertSame($antes, $this->retrato());
        $this->assertSame(1, Transaction::where('fixed_bill_id', $bill->id)->count());
    }

    public function test_reenvio_antes_do_vencimento_nao_diz_que_falta_saldo(): void
    {
        [$user, $corrente, $bill] = $this->contaFixa(cheque: 0, vence: 25);
        $this->pagar($user, $bill, $corrente)->assertRedirect()->assertSessionHasNoErrors();
        $antes = $this->retrato();

        // ANTES: 422 "Saldo insuficiente: a conta Corrente tem R$ 0,00 disponíveis...".
        $this->pagar($user, $bill, $corrente)->assertRedirect()->assertSessionHas('status', 'Esta competência já estava paga.');

        $this->assertSame($antes, $this->retrato());
    }
}
