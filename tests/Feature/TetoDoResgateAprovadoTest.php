<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O resgate nunca passa do que o usuário aprovou (revisão de 06/08/2026).
 *
 * O modal mostra "Vamos resgatar R$ 100,00" e a pessoa confirma — mas o VALOR não
 * viajava no payload: só a escolha (`funding_source` + `funding_investment_id`). O
 * servidor recalculava o faltante na hora de gravar, com o disponível daquele
 * momento. Um lançamento que dormiu na fila offline enquanto outras despesas
 * derrubaram a conta resgatava muito mais do que o número aprovado — sem novo aviso.
 *
 * A correção: `funding_max_amount` é o teto. Estourou, o servidor devolve 409 com as
 * opções RECALCULADAS em vez de sacar mais.
 */
class TetoDoResgateAprovadoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    private Category $categoria;

    private Investment $investimento;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_admin' => true]);

        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'initial_balance' => 1000,
            'overdraft_limit' => 0,
        ]);

        $this->categoria = Category::factory()->for($this->user)->expense()->create();

        $this->investimento = Investment::create([
            'user_id' => $this->user->id,
            'name' => 'CDB',
            'classe' => array_key_first(Investment::CLASSES),
        ]);

        // R$ 900 aplicados A PARTIR desta conta (só isso pode voltar para ela).
        $this->investimento->contributions()->create([
            'account_id' => $this->conta->id,
            'type' => 'aporte',
            'amount' => 900.00,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->user);
    }

    private function disponivel(): float
    {
        return round(Account::find($this->conta->id)->available, 2);
    }

    /** Despesa que derruba o disponível sem passar pelo guard de resgate. */
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

    private function lancar(array $extra = []): TestResponse
    {
        return $this->postJson(route('transactions.store'), array_merge([
            'type' => 'expense',
            'amount' => '200,00',
            'account_id' => $this->conta->id,
            'category_id' => $this->categoria->id,
            'date' => now()->toDateString(),
            'description' => 'Mercado',
        ], $extra));
    }

    /**
     * O CENÁRIO DO DEFEITO: aprovou um resgate de R$ 100 e, antes da gravação, a
     * conta despencou. Sem o teto, saíam R$ 700 do investimento.
     */
    public function test_resgate_nao_passa_do_teto_aprovado(): void
    {
        // Saldo 1000 − 900 reservados no CDB = 100 disponíveis. Uma despesa de 200
        // faria o modal prometer "vamos resgatar R$ 100,00".
        $this->assertSame(100.00, $this->disponivel());

        // ... e então a conta despenca (outra despesa da família, fila offline etc.),
        // e o faltante real vira 600.
        $this->gastar(500.00);
        $this->assertSame(-400.00, $this->disponivel());

        // O replay chega com a escolha antiga E o teto que o usuário viu (R$ 100).
        $resposta = $this->lancar([
            'funding_source' => 'resgate_investimento',
            'funding_investment_id' => $this->investimento->id,
            'funding_max_amount' => '100.00',
        ]);

        $resposta->assertStatus(409)->assertJsonPath('precisa_fonte', true);

        $this->assertSame(
            900.00,
            round((float) $this->investimento->fresh()->aplicado, 2),
            'O investimento encolheu além do que o usuário aprovou.',
        );
        $this->assertDatabaseMissing('transactions', ['description' => 'Mercado']);
    }

    /** Dentro do teto, o resgate acontece normalmente — a trava não atrapalha o caminho feliz. */
    public function test_resgate_dentro_do_teto_passa_normalmente(): void
    {
        // Disponível 100, despesa 200 → faltante 100.
        $this->lancar([
            'funding_source' => 'resgate_investimento',
            'funding_investment_id' => $this->investimento->id,
            'funding_max_amount' => '100.00',
        ])->assertCreated();

        $this->assertSame(800.00, round((float) $this->investimento->fresh()->aplicado, 2));
        $this->assertSame(0.0, $this->disponivel(), 'O resgate deve zerar o disponível, como o modal promete.');
    }

    /** Teto MAIOR que o faltante não força nada: resgata só o que falta. */
    public function test_teto_folgado_resgata_apenas_o_faltante(): void
    {
        // Faltante real = 100.
        $this->lancar([
            'funding_source' => 'resgate_investimento',
            'funding_investment_id' => $this->investimento->id,
            'funding_max_amount' => '500.00',
        ])->assertCreated();

        $this->assertSame(
            800.00,
            round((float) $this->investimento->fresh()->aplicado, 2),
            'O teto é um limite, não um valor a resgatar.',
        );
    }

    /**
     * Sem teto (caminho antigo/sem JS), o comportamento não muda — a trava é
     * opcional de propósito, para não quebrar quem não manda o campo.
     */
    public function test_sem_teto_o_comportamento_antigo_e_preservado(): void
    {

        $this->lancar([
            'funding_source' => 'resgate_investimento',
            'funding_investment_id' => $this->investimento->id,
        ])->assertCreated();

        $this->assertSame(800.00, round((float) $this->investimento->fresh()->aplicado, 2));
    }

    /** O teto é campo de dinheiro: não pode aceitar notação científica nem 3 casas. */
    public function test_teto_valida_como_dinheiro(): void
    {

        $this->lancar([
            'funding_source' => 'resgate_investimento',
            'funding_investment_id' => $this->investimento->id,
            'funding_max_amount' => '1e12',
        ])->assertStatus(422)->assertJsonValidationErrors('funding_max_amount');
    }

    /** `funding_max_amount` é instrução para o guard, nunca coluna da transação. */
    public function test_teto_nao_vaza_para_a_tabela(): void
    {

        $this->lancar([
            'funding_source' => 'resgate_investimento',
            'funding_investment_id' => $this->investimento->id,
            'funding_max_amount' => '100.00',
        ])->assertCreated();

        $transacao = Transaction::where('description', 'Mercado')->firstOrFail();

        $this->assertArrayNotHasKey('funding_max_amount', $transacao->getAttributes());
        $this->assertSame('resgate_investimento', $transacao->funding_source);
        $this->assertSame(100.00, round((float) $transacao->funding_amount, 2));
    }
}
