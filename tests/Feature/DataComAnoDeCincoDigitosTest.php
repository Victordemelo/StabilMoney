<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FixedBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data com ano de 5 dígitos é recusada no campo — em vez de virar OUTRA data (24/09/2026).
 *
 * A regra `date` confere com o parser do PHP, que lê "20266-09-24" como HORA + data:
 * 24/09/2006 às 20:26. Passava no piso de 2000 e no teto de +10 anos, e cada caminho gravava
 * uma coisa diferente do que a pessoa digitou:
 *
 *  - Pagar despesas (`faturas.lancar`): a compra entrava datada em 24/09/2006;
 *  - conta fixa: início em 01/09/2006 — 13 competências "vencidas" de uma dívida que não
 *    existe, com o sino vermelho;
 *  - aporte/resgate: movimentação de 2006;
 *  - lançamento (`transactions.store`): o texto cru "20266-09-24" na coluna DATE. No MySQL
 *    é data inválida (HTTP 500 no caminho mais usado do app, e um item da fila offline
 *    preso para sempre — 5xx fica na fila); no sqlite desta suíte, lixo gravado.
 *
 * O `<input type="date">` deixa digitar ano de até 6 dígitos: basta um dígito a mais.
 * Agora toda data de formulário é `date_format:Y-m-d`, que é o que o input manda.
 */
class DataComAnoDeCincoDigitosTest extends TestCase
{
    use RefreshDatabase;

    private const ANO_COM_UM_DIGITO_A_MAIS = '20266-09-24';

    private User $titular;

    private Account $corrente;

    private Account $cartao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $this->corrente = Account::factory()->for($this->titular)->create(['type' => 'checking', 'initial_balance' => 1000]);
        $this->cartao = Account::factory()->creditCard()->for($this->titular)->create();
    }

    public function test_compra_em_pagar_despesas_nao_vira_compra_de_2006(): void
    {
        $this->actingAs($this->titular)
            ->post(route('faturas.lancar'), [
                'description' => 'Tênis',
                'amount' => '300,00',
                'date' => self::ANO_COM_UM_DIGITO_A_MAIS,
                'account_id' => $this->cartao->id,
                'mode' => 'avista',
            ])
            ->assertSessionHasErrors('date');

        $this->assertSame(0, Transaction::count(), 'A compra não podia ter sido gravada (antes: datada em 2006).');
    }

    public function test_conta_fixa_nao_nasce_com_competencias_vencidas_de_2006(): void
    {
        $this->actingAs($this->titular)
            ->post(route('contas-fixas.store'), [
                'name' => 'Aluguel',
                'amount' => '1.000,00',
                'due_day' => 5,
                'starts_on' => '20266-09-01',
            ])
            ->assertSessionHasErrors('starts_on');

        $this->assertSame(0, FixedBill::count());
        $this->assertCount(0, app(FixedBillService::class)->overdue($this->titular->id));

        // O fim tem a mesma regra (inclusive o ano com sinal, que o parser lê como 10000).
        foreach (['20270-12-31', '+10000-01-01'] as $fim) {
            $this->actingAs($this->titular)
                ->post(route('contas-fixas.store'), [
                    'name' => 'Aluguel',
                    'amount' => '1.000,00',
                    'due_day' => 5,
                    'starts_on' => now()->startOfMonth()->toDateString(),
                    'ends_on' => $fim,
                ])
                ->assertSessionHasErrors('ends_on');
        }

        $this->assertSame(0, FixedBill::count());
    }

    public function test_lancamento_nao_grava_a_data_crua(): void
    {
        $this->actingAs($this->titular)
            ->postJson(route('transactions.store'), [
                'type' => 'expense',
                'amount' => '10,00',
                'account_id' => $this->corrente->id,
                'date' => self::ANO_COM_UM_DIGITO_A_MAIS,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('date');

        $this->assertSame(0, Transaction::count(), 'Antes a coluna DATE recebia o texto "20266-09-24".');
    }

    public function test_aporte_nao_vira_movimentacao_de_2006(): void
    {
        $meta = Goal::factory()->for($this->titular)->create();

        $this->actingAs($this->titular)
            ->post(route('metas.aportes.store', $meta), [
                'amount' => '10,00',
                'account_id' => $this->corrente->id,
                'date' => self::ANO_COM_UM_DIGITO_A_MAIS,
            ])
            ->assertSessionHasErrors('date');

        $this->assertSame(0, GoalContribution::count());
    }

    public function test_data_de_nascimento_tambem_confere_o_formato(): void
    {
        $this->actingAs($this->titular)
            ->patch(route('profile.update'), [
                'name' => $this->titular->name,
                'email' => $this->titular->email,
                'birth_date' => '19900-05-10',
            ])
            ->assertSessionHasErrors('birth_date');

        $this->assertNull($this->titular->fresh()->birth_date);
    }

    /** Controle: a data que o `<input type="date">` manda continua passando em todos. */
    public function test_datas_de_sempre_continuam_passando(): void
    {
        $hoje = now()->toDateString();

        $this->actingAs($this->titular)
            ->postJson(route('transactions.store'), [
                'type' => 'expense', 'amount' => '10,00', 'account_id' => $this->corrente->id, 'date' => $hoje,
            ])
            ->assertCreated();

        $this->actingAs($this->titular)
            ->post(route('faturas.lancar'), [
                'description' => 'Tênis', 'amount' => '300,00', 'date' => $hoje,
                'account_id' => $this->cartao->id, 'mode' => 'avista',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->titular)
            ->post(route('contas-fixas.store'), [
                'name' => 'Aluguel', 'amount' => '1.000,00', 'due_day' => 5,
                'starts_on' => now()->startOfMonth()->toDateString(), 'ends_on' => '2061-06-30',
            ])
            ->assertSessionHasNoErrors();

        $meta = Goal::factory()->for($this->titular)->create();
        $this->actingAs($this->titular)
            ->post(route('metas.aportes.store', $meta), [
                'amount' => '10,00', 'account_id' => $this->corrente->id, 'date' => $hoje,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Transaction::count());
        $this->assertSame(1, FixedBill::count());
        $this->assertSame(1, GoalContribution::count());
    }
}
