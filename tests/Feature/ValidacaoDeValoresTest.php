<?php

namespace Tests\Feature;

use App\Http\Requests\StoreTransactionRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\Transaction;
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

    /**
     * Valores que BURLAM o `numeric` do PHP e por isso precisam do `decimal:0,2`.
     *
     * `is_numeric('1e12')` é true — para o PHP notação científica é número. Sem a
     * regra `decimal`, um "1e12" digitado (ou injetado por um cliente qualquer)
     * virava um lançamento de um trilhão de reais; e "10,999" gravava a terceira
     * casa decimal, que o MySQL arredonda em silêncio e o sqlite guarda inteira —
     * o mesmo app com dois números diferentes.
     *
     * O último caso é o teto ANTIGO da validação (9.999.999.999.999,99): ele
     * passava no Form Request e estourava o `decimal(15,2)` no MySQL, virando
     * erro 500 em vez de erro de validação. Ver `TETO_MONETARIO`.
     */
    public static function valoresQueBurlamONumeric(): array
    {
        return [
            'notação científica minúscula' => ['1e3'],
            'notação científica grande' => ['1e12'],
            'notação científica maiúscula' => ['1E5'],
            'notação científica com decimal' => ['1.5e3'],
            'três casas decimais' => ['10,999'],
            'quatro casas decimais' => ['800,1234'],
            'centavo de centavo' => ['0,001'],
            'teto antigo (estourava o decimal(15,2))' => ['9999999999999,99'],
            'um trilhão' => ['1.000.000.000.000,00'],
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
     * Notação científica: o validador `numeric` do PHP aceita "1e10", e por muito
     * tempo este teste aceitava as DUAS saídas ("recusar ou gravar exato") — foi
     * por isso que a suíte inteira nunca pegou o buraco. Agora que existe a regra
     * `decimal:0,2`, a única saída certa é RECUSAR: ninguém digita "1e3" querendo
     * mil reais, e quem manda isso está fuçando o formulário.
     */
    public function test_scientific_notation_is_rejected(): void
    {
        foreach (['1e3', '1e12', '1E5', '1.5e3'] as $valor) {
            $this->post(route('transactions.store'), [
                'type' => 'income',
                'amount' => $valor,
                'date' => now()->toDateString(),
                'account_id' => $this->conta->id,
                'category_id' => Category::factory()->for($this->user)->income()->create()->id,
            ])->assertSessionHasErrors('amount');
        }

        $this->assertSame(
            0,
            Transaction::count(),
            'Notação científica não pode virar lançamento nenhum — "1e12" é um trilhão de reais.',
        );
    }

    /**
     * Mais de duas casas decimais: o banco é decimal(15,2). Este teste também
     * aceitava "recusar OU arredondar"; agora exige a RECUSA — arredondar em
     * silêncio faz o app cobrar do usuário um centavo que ele não digitou, e o
     * resultado ainda dependia do driver (MySQL arredonda, sqlite guardava a
     * terceira casa inteira).
     */
    public function test_third_decimal_place_is_rejected(): void
    {
        $this->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '10,999',
            'date' => now()->toDateString(),
            'account_id' => $this->conta->id,
            'category_id' => $this->categoria->id,
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Transaction::count(), 'Valor com três casas decimais não pode ser gravado.');
    }

    /**
     * O ponto seguido de três dígitos é MILHAR em pt-BR, não decimal: "800.123" é
     * oitocentos mil cento e vinte e três, e continua valendo (é o que sai do
     * `sm/money.js`). A terceira casa decimal de verdade se escreve com vírgula
     * ("800,123") — e essa é recusada pelo teste acima.
     */
    public function test_ponto_de_milhar_continua_sendo_milhar(): void
    {
        $this->post(route('transactions.store'), [
            'type' => 'income',
            'amount' => '800.123',
            'date' => now()->toDateString(),
            'account_id' => $this->conta->id,
            'category_id' => Category::factory()->for($this->user)->income()->create()->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            800123.00,
            round((float) Transaction::firstOrFail()->amount, 2),
            '"800.123" tem de virar 800.123,00 — ler como 800,123 quebraria todo valor com milhar.',
        );
    }

    #[DataProvider('valoresQueBurlamONumeric')]
    public function test_transaction_rejects_values_that_slip_past_numeric(string $valor): void
    {
        $this->post(route('transactions.store'), [
            'type' => 'income',
            'amount' => $valor,
            'date' => now()->toDateString(),
            'account_id' => $this->conta->id,
            'category_id' => Category::factory()->for($this->user)->income()->create()->id,
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Transaction::count(), "\"{$valor}\" não podia ter virado lançamento.");
    }

    #[DataProvider('valoresQueBurlamONumeric')]
    public function test_fatura_launch_rejects_values_that_slip_past_numeric(string $valor): void
    {
        $this->post(route('faturas.lancar'), [
            'description' => 'Compra suspeita',
            'amount' => $valor,
            'date' => now()->toDateString(),
            'account_id' => $this->conta->id,
            'category_id' => $this->categoria->id,
            'mode' => 'avista',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, Transaction::count(), "\"{$valor}\" não podia ter virado despesa.");
    }

    #[DataProvider('valoresQueBurlamONumeric')]
    public function test_account_initial_balance_rejects_values_that_slip_past_numeric(string $valor): void
    {
        $this->post(route('accounts.store'), [
            'name' => 'Conta Suspeita',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => $valor,
            'overdraft_limit' => '0',
        ])->assertSessionHasErrors('initial_balance');

        $this->assertSame(
            1,
            Account::count(),
            "Saldo inicial \"{$valor}\" não podia ter criado conta — ele contamina TODO o cálculo de dinheiro dela.",
        );
    }

    #[DataProvider('valoresQueBurlamONumeric')]
    public function test_overdraft_limit_rejects_values_that_slip_past_numeric(string $valor): void
    {
        $this->post(route('accounts.store'), [
            'name' => 'Conta Suspeita',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '100,00',
            'overdraft_limit' => $valor,
        ])->assertSessionHasErrors('overdraft_limit');

        $this->assertSame(1, Account::count(), "Cheque especial \"{$valor}\" não podia ter criado conta.");
    }

    #[DataProvider('valoresQueBurlamONumeric')]
    public function test_credit_limit_rejects_values_that_slip_past_numeric(string $valor): void
    {
        $this->post(route('accounts.store'), [
            'name' => 'Cartão Suspeito',
            'type' => 'credit_card',
            'bank' => 'nubank',
            'credit_limit' => $valor,
            'closing_day' => 10,
            'due_day' => 20,
        ])->assertSessionHasErrors('credit_limit');

        $this->assertSame(1, Account::count(), "Limite \"{$valor}\" não podia ter criado cartão.");
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
            '25,90' => 25.90,
            '0,01' => 0.01,
            '1.000.000,00' => 1000000.00,
            '12,5' => 12.50,
            // O valor EXATAMENTE no teto da validação: ou é aceito e gravado
            // exato, ou o teto está errado. Nunca pode virar erro 500.
            '999.999.999.999,99' => 999999999999.99,
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
     * POR QUE o teto é 999.999.999.999,99 e não os 9.999.999.999.999,99 que o
     * `decimal(15,2)` caberia.
     *
     * O PDO manda o bind como STRING. Com o `precision=14` padrão do PHP,
     * `(string) (float) '9999999999999.99'` devolve "10000000000000" — e o MySQL
     * responde `SQLSTATE[22003] Out of range value`, ou seja, ERRO 500 num valor
     * que a validação tinha acabado de aprovar. Com 14 dígitos significativos
     * (12 inteiros + 2 centavos) o número volta a sobreviver à viagem.
     */
    public function test_teto_monetario_sobrevive_a_conversao_por_float(): void
    {
        $this->assertSame(
            '999999999999.99',
            (string) (float) StoreTransactionRequest::TETO_MONETARIO,
            'O teto tem de virar string exata: é essa string que o PDO manda no bind.',
        );

        if ((int) ini_get('precision') === 14) {
            $this->assertSame(
                '10000000000000',
                (string) (float) '9999999999999.99',
                'Se este assert falhar, o motivo do teto mudou — reveja TETO_MONETARIO.',
            );
        }
    }

    /**
     * Formato pt-BR legítimo nos campos de dinheiro da CONTA. É o caso que não
     * pode quebrar: endurecer a validação sem quebrar quem digita certo.
     */
    public function test_account_money_fields_accept_brazilian_format(): void
    {
        $this->post(route('accounts.store'), [
            'name' => 'Corrente pt-BR',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '1.234,56',
            'overdraft_limit' => '2.500,00',
        ])->assertSessionHasNoErrors();

        $conta = Account::where('name', 'Corrente pt-BR')->firstOrFail();
        $this->assertSame(1234.56, round((float) $conta->initial_balance, 2));
        $this->assertSame(2500.00, round((float) $conta->overdraft_limit, 2));

        $this->post(route('accounts.store'), [
            'name' => 'Cartão pt-BR',
            'type' => 'credit_card',
            'bank' => 'nubank',
            'credit_limit' => '5.000,90',
            'closing_day' => 10,
            'due_day' => 20,
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            5000.90,
            round((float) Account::where('name', 'Cartão pt-BR')->firstOrFail()->credit_limit, 2),
        );
    }

    /** Idem no lançamento de despesa da tela "Pagar despesas". */
    public function test_fatura_launch_accepts_brazilian_format(): void
    {
        $this->post(route('faturas.lancar'), [
            'description' => 'Mercado',
            'amount' => '1.234,56',
            'date' => now()->toDateString(),
            'account_id' => $this->conta->id,
            'category_id' => $this->categoria->id,
            'mode' => 'avista',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            1234.56,
            round((float) Transaction::firstOrFail()->amount, 2),
            'O formato que o usuário digita de verdade tem de continuar passando.',
        );
    }

    /**
     * Reduzir o cheque especial abaixo do que já está EM USO deixaria a conta num
     * estado que nenhum lançamento consegue produzir: saldo em −800 com piso 0.
     * O modelo de dinheiro v3 promete que o disponível nunca passa de
     * `−overdraft_limit`; sem esta trava, a promessa era furada pela tela de
     * edição da conta.
     */
    public function test_reducing_overdraft_below_usage_is_rejected(): void
    {
        $conta = $this->contaNoChequeEspecial();

        $this->patch(route('accounts.update', $conta), [
            'name' => $conta->name,
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '200,00',
            'overdraft_limit' => '0',
        ])->assertSessionHasErrors('overdraft_limit');

        $this->assertSame(
            1000.00,
            round((float) $conta->fresh()->overdraft_limit, 2),
            'O limite não podia ter sido reduzido.',
        );

        // Reduzir para MENOS do que os R$ 800 usados também não vale.
        $this->patch(route('accounts.update', $conta), [
            'name' => $conta->name,
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '200,00',
            'overdraft_limit' => '799,99',
        ])->assertSessionHasErrors('overdraft_limit');
    }

    /** Reduzir até EXATAMENTE o que está em uso é legítimo (o piso continua honrado). */
    public function test_reducing_overdraft_to_exactly_the_usage_is_allowed(): void
    {
        $conta = $this->contaNoChequeEspecial();

        $this->patch(route('accounts.update', $conta), [
            'name' => $conta->name,
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '200,00',
            'overdraft_limit' => '800,00',
        ])->assertSessionHasNoErrors();

        $this->assertSame(800.00, round((float) $conta->fresh()->overdraft_limit, 2));
    }

    /** Conta positiva: zerar o cheque especial é livre — não há nada em uso. */
    public function test_reducing_overdraft_on_positive_account_is_allowed(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'name' => 'Corrente Positiva',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => 500,
            'overdraft_limit' => 1000,
        ]);

        $this->patch(route('accounts.update', $conta), [
            'name' => $conta->name,
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '500,00',
            'overdraft_limit' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.00, round((float) $conta->fresh()->overdraft_limit, 2));
    }

    /**
     * Poupança não tem cheque especial: virar poupança com o limite em uso é a
     * mesma redução por outro caminho (o prepareForValidation zera o campo).
     */
    public function test_switching_to_savings_while_using_overdraft_is_rejected(): void
    {
        $conta = $this->contaNoChequeEspecial();

        $this->patch(route('accounts.update', $conta), [
            'name' => $conta->name,
            'type' => 'savings',
            'bank' => 'nubank',
            'initial_balance' => '200,00',
        ])->assertSessionHasErrors('overdraft_limit');

        $this->assertSame('checking', $conta->fresh()->type);
    }

    /** Conta corrente com R$ 200 e uma despesa de R$ 1.000: disponível −800, limite 1.000. */
    private function contaNoChequeEspecial(): Account
    {
        $conta = Account::factory()->for($this->user)->create([
            'name' => 'Corrente no Vermelho',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => 200,
            'overdraft_limit' => 1000,
        ]);

        Transaction::factory()->for($this->user)->for($conta)->expense()->create([
            'amount' => 1000,
            'date' => now()->toDateString(),
        ]);

        $this->assertSame(
            -800.00,
            round($conta->fresh()->available, 2),
            'Pré-condição do teste: a conta precisa estar usando R$ 800 do cheque especial.',
        );

        return $conta;
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
