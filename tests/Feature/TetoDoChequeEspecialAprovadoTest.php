<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O cheque especial não passa do que o usuário aprovou (24/09/2026).
 *
 * O resgate ganhou teto em 06/08 (TetoDoResgateAprovadoTest); o cheque especial ficou de
 * fora — a auditoria de 06/09 anotou "conferir com a intenção" e ninguém fechou. O modal diz
 * "Sua conta fica em R$ X", a pessoa confirma, e a escolha é reenviada. Se o disponível caiu
 * até a gravação (outra despesa da família, ou a escolha que dormiu na fila offline), a
 * despesa consumia MAIS cheque especial — que cobra juros — do que a pessoa viu, sem aviso.
 *
 * Agora o mesmo `funding_max_amount` (o faltante aprovado) vale para o cheque especial: se
 * a despesa passaria a usar mais cheque do que isso, volta 409 com as opções recalculadas.
 */
class TetoDoChequeEspecialAprovadoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Category $categoria;

    protected function setUp(): void
    {
        parent::setUp();
        // Dia 5: a conta fixa que vence dia 10 é do mês corrente (pagável).
        Carbon::setTestNow('2026-08-05');

        $this->user = User::factory()->create(['is_admin' => true]);

        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Corrente',
            'initial_balance' => 100,
            'overdraft_limit' => 1000,
        ]);

        $this->categoria = Category::factory()->for($this->user)->expense()->create();

        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function disponivel(): float
    {
        return round(Account::find($this->conta->id)->available, 2);
    }

    /** Despesa de outra pessoa da família, gravada por baixo, que derruba o disponível. */
    private function gastar(float $valor): void
    {
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->conta->id,
            'category_id' => $this->categoria->id,
            'type' => 'expense',
            'amount' => $valor,
            'date' => now()->toDateString(),
        ]);
    }

    private function lancar(string $valor, array $extra = []): TestResponse
    {
        return $this->postJson(route('transactions.store'), array_merge([
            'type' => 'expense',
            'amount' => $valor,
            'account_id' => $this->conta->id,
            'category_id' => $this->categoria->id,
            'date' => now()->toDateString(),
            'description' => 'Mercado',
        ], $extra));
    }

    private function cheque(float $teto): array
    {
        return [
            'funding_source' => FundingSource::CHEQUE_ESPECIAL,
            'funding_max_amount' => number_format($teto, 2, '.', ''),
        ];
    }

    public function test_o_409_mostra_o_faltante_que_vira_o_teto(): void
    {
        $this->lancar('300,00')
            ->assertStatus(409)
            ->assertJsonPath('fonte.faltante', 200)
            ->assertJsonPath('fonte.fontes.0.id', FundingSource::CHEQUE_ESPECIAL);
    }

    /** O CENÁRIO DO DEFEITO: aprovou ficar em −R$ 200 e a conta caiu antes da gravação. */
    public function test_cheque_especial_nao_passa_do_teto_aprovado(): void
    {
        // Disponível 100, despesa 300: o modal promete "Sua conta fica em −R$ 200,00".
        $this->assertSame(100.00, $this->disponivel());

        // Antes do reenvio, outra despesa da família leva os 100 disponíveis.
        $this->gastar(100.00);

        $this->lancar('300,00', $this->cheque(200))
            ->assertStatus(409)
            ->assertJsonPath('precisa_fonte', true)
            // As opções voltam RECALCULADAS: agora faltam 300.
            ->assertJsonPath('fonte.faltante', 300);

        $this->assertDatabaseMissing('transactions', ['description' => 'Mercado']);
        $this->assertSame(0.00, $this->disponivel(), 'A despesa usou cheque especial além do aprovado.');
    }

    public function test_dentro_do_teto_usa_o_cheque_normalmente(): void
    {
        $this->lancar('300,00', $this->cheque(200))->assertCreated();

        $despesa = Transaction::where('description', 'Mercado')->firstOrFail();
        $this->assertSame(FundingSource::CHEQUE_ESPECIAL, $despesa->funding_source);
        $this->assertSame(200.00, round((float) $despesa->funding_amount, 2));
        $this->assertSame(-200.00, $this->disponivel());
    }

    public function test_saldo_que_subiu_nao_atrapalha(): void
    {
        // Entrou dinheiro depois da aprovação: a despesa usa MENOS cheque que o aprovado.
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->conta->id,
            'type' => 'income',
            'amount' => 150,
            'date' => now()->toDateString(),
        ]);

        $this->lancar('300,00', $this->cheque(200))->assertCreated();
        $this->assertSame(-50.00, $this->disponivel());
    }

    /**
     * Conta que já estava no vermelho: a despesa não consome mais cheque que o próprio
     * valor, e o teto (o faltante, que inclui o vermelho) não pode disparar à toa.
     */
    public function test_conta_ja_no_vermelho_o_teto_nao_dispara_a_toa(): void
    {
        $this->gastar(200.00); // disponível −100
        // Despesa de 50: o modal mostra faltam 150 (50 + os 100 que já devia).
        $this->lancar('50,00')->assertStatus(409)->assertJsonPath('fonte.faltante', 150);

        $this->gastar(30.00); // caiu mais um pouco antes do reenvio: −130

        $this->lancar('50,00', $this->cheque(150))->assertCreated();

        $despesa = Transaction::where('description', 'Mercado')->firstOrFail();
        $this->assertSame(50.00, round((float) $despesa->funding_amount, 2));
        $this->assertSame(-180.00, $this->disponivel());
    }

    public function test_sem_teto_o_comportamento_antigo_e_preservado(): void
    {
        $this->gastar(100.00);

        $this->lancar('300,00', ['funding_source' => FundingSource::CHEQUE_ESPECIAL])->assertCreated();
        $this->assertSame(-300.00, $this->disponivel());
    }

    /** Obrigação (conta fixa) segue a mesma regra: o teto aprovado vale no pagamento. */
    public function test_pagar_conta_fixa_com_cheque_respeita_o_teto(): void
    {
        $condominio = FixedBill::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Condomínio',
            'amount' => 300,
            'due_day' => 10,
            'account_id' => $this->conta->id,
            'starts_on' => '2026-08-01',
            'active' => true,
        ]);

        $this->gastar(100.00);

        $this->from(route('faturas.index'))
            ->post(route('contas-fixas.pagar', [$condominio, '2026-08']), [
                'account_id' => $this->conta->id,
                'amount' => '300,00',
            ] + $this->cheque(200))
            ->assertRedirect(route('faturas.index'))
            ->assertSessionHas('fonteNecessaria');

        $this->assertDatabaseMissing('transactions', ['fixed_bill_id' => $condominio->id]);

        $this->post(route('contas-fixas.pagar', [$condominio, '2026-08']), [
            'account_id' => $this->conta->id,
            'amount' => '300,00',
        ] + $this->cheque(300))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'fixed_bill_id' => $condominio->id,
            'funding_source' => FundingSource::CHEQUE_ESPECIAL,
        ]);
        $this->assertSame(-300.00, $this->disponivel());
    }
}
