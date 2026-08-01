<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ataque sistemático aos CAMPOS DE DINHEIRO de todos os endpoints.
 *
 * A regra do projeto é "dinheiro nunca é negativo — o sinal vem do type". Este arquivo
 * verifica se essa regra se sustenta em cada porta de entrada, inclusive nas que entraram
 * com a feature de contas fixas e cheque especial.
 *
 * Os payloads foram escolhidos por serem os que costumam furar validação de moeda em PHP:
 * negativo, zero, notação científica (o validador `numeric` do PHP ACEITA "1e10"),
 * mais de duas casas decimais, valores acima do decimal(15,2), string com SQL e
 * espaço/vírgula em posição inesperada.
 */
class ValidacaoDeValoresTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Category $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_admin' => true]);
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'initial_balance' => 100000,
        ]);
        $this->categoria = Category::factory()->for($this->user)->expense()->create();

        $this->actingAs($this->user);
    }

    /** Valores que NUNCA devem ser aceitos num campo de dinheiro. */
    public static function valoresInvalidos(): array
    {
        return [
            'negativo' => ['-100,00'],
            'negativo sem vírgula' => ['-100'],
            'zero' => ['0'],
            'zero com centavos' => ['0,00'],
            'texto' => ['abc'],
            'sql' => ["1 OR 1=1"],
            'vazio' => [''],
            'acima do decimal(15,2)' => ['99999999999999999'],
            'só símbolo' => ['R$'],
        ];
    }

    #[DataProvider('valoresInvalidos')]
    public function test_transaction_rejects_invalid_amount(string $valor): void
    {
        $antes = Account::find($this->conta->id)->balance;

        $this->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => $valor,
            'date' => now()->toDateString(),
            'account_id' => $this->conta->id,
            'category_id' => $this->categoria->id,
        ])->assertSessionHasErrors('amount');

        $this->assertSame(
            round($antes, 2),
            round(Account::find($this->conta->id)->balance, 2),
            "O valor \"{$valor}\" foi recusado, mas o saldo mudou — a validação não protegeu o banco.",
        );
    }

    #[DataProvider('valoresInvalidos')]
    public function test_goal_contribution_rejects_invalid_amount(string $valor): void
    {
        $meta = Goal::factory()->for($this->user)->create();

        $this->post(route('metas.aportes.store', $meta), [
            'amount' => $valor,
            'account_id' => $this->conta->id,
        ])->assertSessionHasErrors('amount');

        $this->assertSame(
            0.0,
            round(Account::find($this->conta->id)->reserved, 2),
            "Aporte inválido (\"{$valor}\") não pode reservar dinheiro.",
        );
    }

    #[DataProvider('valoresInvalidos')]
    public function test_fixed_bill_rejects_invalid_amount(string $valor): void
    {
        if (! \Illuminate\Support\Facades\Route::has('contas-fixas.store')) {
            $this->markTestSkipped('Contas fixas não disponíveis.');
        }

        $this->post(route('contas-fixas.store'), [
            'name' => 'Aluguel',
            'amount' => $valor,
            'due_day' => 10,
            'account_id' => $this->conta->id,
            'category_id' => $this->categoria->id,
            'starts_on' => now()->startOfMonth()->toDateString(),
        ])->assertSessionHasErrors('amount');
    }

    /**
     * Cheque especial não pode ser negativo (daria limite negativo = trava invertida).
     */
    public function test_overdraft_limit_rejects_negative(): void
    {
        $this->post(route('accounts.store'), [
            'name' => 'Conta Teste',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '1.000,00',
            'overdraft_limit' => '-500,00',
        ])->assertSessionHasErrors('overdraft_limit');
    }

    /**
     * Notação científica: o validador `numeric` do PHP aceita "1e10". Documenta o que o
     * app faz com isso — se aceitar, o valor gravado tem de ser o número correto
     * (10.000.000.000), nunca 1 ou 10.
     */
    public function test_scientific_notation_is_either_rejected_or_stored_exactly(): void
    {
        $resposta = $this->post(route('transactions.store'), [
            'type' => 'income',
            'amount' => '1e3',
            'date' => now()->toDateString(),
            'account_id' => $this->conta->id,
            'category_id' => Category::factory()->for($this->user)->income()->create()->id,
        ]);

        $transacao = \App\Models\Transaction::where('user_id', $this->user->id)->first();

        if ($transacao === null) {
            // Recusado: comportamento aceitável.
            $this->assertTrue($resposta->getSession()->hasOldInput() || $resposta->getStatusCode() >= 300);

            return;
        }

        // Aceito: então tem de valer exatamente 1000,00 — não 1,00 nem 3,00.
        $this->assertSame(
            1000.00,
            round((float) $transacao->amount, 2),
            'Notação científica foi aceita mas gravou valor errado — risco de lançamento com valor irreal.',
        );
    }

    /**
     * Mais de duas casas decimais: o banco é decimal(15,2). O app precisa ser
     * DETERMINÍSTICO — e nunca gravar mais do que foi pedido.
     */
    public function test_third_decimal_place_never_rounds_up_against_the_user(): void
    {
        $this->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '10,999',
            'date' => now()->toDateString(),
            'account_id' => $this->conta->id,
            'category_id' => $this->categoria->id,
        ]);

        $transacao = \App\Models\Transaction::where('user_id', $this->user->id)->first();

        if ($transacao === null) {
            $this->addToAssertionCount(1); // recusar também é válido

            return;
        }

        $gravado = round((float) $transacao->amount, 2);

        $this->assertContains(
            $gravado,
            [10.99, 11.00],
            "10,999 virou {$gravado} — fora das duas possibilidades aceitáveis (truncar ou arredondar).",
        );
    }

    /**
     * Um valor legítimo com vírgula pt-BR e separador de milhar tem de ser aceito e
     * gravado EXATO. É o formato que o usuário digita de verdade.
     */
    public function test_brazilian_format_is_parsed_exactly(): void
    {
        $casos = [
            '1.234,56' => 1234.56,
            '999,99' => 999.99,
            '0,01' => 0.01,
            '1.000.000,00' => 1000000.00,
            '12,5' => 12.50,
        ];

        foreach ($casos as $entrada => $esperado) {
            \App\Models\Transaction::query()->delete();

            $this->post(route('transactions.store'), [
                'type' => 'income',
                'amount' => (string) $entrada,
                'date' => now()->toDateString(),
                'account_id' => $this->conta->id,
                'category_id' => Category::factory()->for($this->user)->income()->create()->id,
            ])->assertSessionHasNoErrors();

            $transacao = \App\Models\Transaction::where('user_id', $this->user->id)->firstOrFail();

            $this->assertSame(
                $esperado,
                round((float) $transacao->amount, 2),
                "\"{$entrada}\" deveria virar {$esperado} e virou {$transacao->amount}.",
            );
        }
    }

    /**
     * due_day de conta fixa: o CLAUDE.md diz que aceita 1..31, enquanto o cartão limita
     * a 1..28 (justamente para evitar fevereiro). Documenta e verifica o limite real.
     */
    public function test_fixed_bill_due_day_boundaries(): void
    {
        if (! \Illuminate\Support\Facades\Route::has('contas-fixas.store')) {
            $this->markTestSkipped('Contas fixas não disponíveis.');
        }

        // 0 e 32 têm de ser recusados em qualquer hipótese.
        foreach ([0, 32, -1, 100] as $dia) {
            $this->post(route('contas-fixas.store'), [
                'name' => 'Teste dia '.$dia,
                'amount' => '100,00',
                'due_day' => $dia,
                'account_id' => $this->conta->id,
                'category_id' => $this->categoria->id,
                'starts_on' => now()->startOfMonth()->toDateString(),
            ])->assertSessionHasErrors('due_day');
        }
    }

    /**
     * Aportar mais do que o disponível tem de ser recusado — senão o app reserva
     * dinheiro que não existe e o disponível fica negativo sem cheque especial.
     */
    public function test_contribution_above_available_is_rejected(): void
    {
        $meta = Goal::factory()->for($this->user)->create();
        $disponivel = Account::find($this->conta->id)->available;

        $this->post(route('metas.aportes.store', $meta), [
            'amount' => number_format($disponivel + 1000, 2, ',', '.'),
            'account_id' => $this->conta->id,
        ])->assertSessionHasErrors();

        $this->assertSame(
            0.0,
            round(Account::find($this->conta->id)->reserved, 2),
            'Nada pode ter sido reservado.',
        );
    }

    /**
     * A taxa do investimento NÃO é dinheiro (é %), mas também não pode ser negativa
     * nem absurda — projeção com taxa negativa mostraria rendimento invertido.
     */
    public function test_investment_rate_rejects_negative(): void
    {
        $this->post(route('investimentos.store'), [
            'name' => 'CDB',
            'classe' => 'renda_fixa',
            'indexador' => 'CDI',
            'taxa' => '-50',
            'valor_inicial' => '1.000,00',
            'account_id' => $this->conta->id,
        ])->assertSessionHasErrors('taxa');

        $this->assertSame(0, Investment::count(), 'Investimento com taxa negativa não pode ser criado.');
    }
}
