<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comportamento do CHEQUE ESPECIAL e da CONTA-FAMÍLIA, ponta a ponta.
 *
 * O cheque especial é a parte mais delicada do modelo de dinheiro: é a única situação em
 * que o app deixa o saldo ficar negativo. A spec do projeto define duas regras que este
 * arquivo verifica de verdade, com dinheiro:
 *
 *  1. O app NUNCA usa o cheque especial por conta própria. Havendo limite disponível, o
 *     servidor pergunta (HTTP 409) e espera a escolha do usuário.
 *  2. Gasto novo sem fonte é RECUSADO; obrigação já vencida (fatura, conta fixa) PASSA e
 *     deixa a conta negativa — porque a dívida já existe no mundo real.
 *
 * E na família: titular e dependente compartilham o mesmo dinheiro, mas famílias
 * diferentes não podem se tocar de forma alguma.
 */
class JornadaChequeEspecialEFamiliaTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private User $dependente;

    private Account $conta;

    private Category $catDespesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['is_admin' => true]);
        $this->dependente = User::factory()->create([
            'account_owner_id' => $this->titular->id,
            'is_admin' => false,
        ]);

        // Conta com R$ 100,00 de saldo e R$ 500,00 de cheque especial.
        $this->conta = Account::factory()->for($this->titular)->create([
            'type' => 'checking',
            'initial_balance' => 100,
            'overdraft_limit' => 500,
        ]);

        $this->catDespesa = Category::factory()->for($this->titular)->expense()->create();
    }

    private function conta(): Account
    {
        return Account::findOrFail($this->conta->id);
    }

    /** Lança uma despesa pela rota, como o usuário faria. */
    private function lancar(array $extra = [], ?User $como = null)
    {
        return $this->actingAs($como ?? $this->titular)->post(route('transactions.store'), array_merge([
            'type' => 'expense',
            'amount' => '300,00',
            'date' => now()->toDateString(),
            'account_id' => $this->conta->id,
            'category_id' => $this->catDespesa->id,
        ], $extra));
    }

    /**
     * Gasto acima do saldo, MAS dentro do cheque especial: o app não pode decidir
     * sozinho. Ou pergunta (409), ou recusa — o que não pode é entrar em silêncio.
     */
    public function test_gasto_dentro_do_cheque_especial_nao_entra_sem_escolha(): void
    {
        $resposta = $this->lancar(['amount' => '300,00']);

        $entrou = Transaction::where('account_id', $this->conta->id)->exists();

        $this->assertFalse(
            $entrou,
            'A despesa entrou sem o usuário escolher a fonte — o app usou o cheque especial por conta própria.',
        );

        // 409 (pergunta) e 422/302-com-erro (recusa) são ambos aceitáveis.
        $this->assertContains(
            $resposta->getStatusCode(),
            [409, 422, 302],
            'Resposta inesperada para gasto que precisa de fonte.',
        );

        $this->assertSame(100.0, round($this->conta()->available, 2), 'O saldo não pode ter mudado.');
    }

    /**
     * Com a fonte escolhida explicitamente, o gasto passa e o saldo fica negativo
     * — exatamente o valor que passou do disponível.
     */
    public function test_com_fonte_escolhida_o_saldo_fica_negativo_no_valor_certo(): void
    {
        $this->lancar([
            'amount' => '300,00',
            'funding_source' => 'cheque_especial',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            -200.0,
            round($this->conta()->available, 2),
            'R$ 100 de saldo − R$ 300 de gasto tem de deixar −R$ 200,00.',
        );
    }

    /**
     * O PISO do cheque especial tem de ser respeitado: com R$ 100 + R$ 500 de limite,
     * um gasto de R$ 700 não pode entrar de jeito nenhum.
     */
    public function test_gasto_acima_do_piso_do_cheque_especial_e_recusado(): void
    {
        $this->lancar([
            'amount' => '700,00',
            'funding_source' => 'cheque_especial',
        ]);

        $conta = $this->conta();

        $this->assertGreaterThanOrEqual(
            -500.0,
            round($conta->available, 2),
            'O disponível furou o piso de −R$ 500,00 (limite do cheque especial).',
        );
    }

    /** Exatamente no limite: R$ 600 (100 de saldo + 500 de limite) é o máximo. */
    public function test_gasto_exatamente_no_limite_do_cheque_especial(): void
    {
        $this->lancar([
            'amount' => '600,00',
            'funding_source' => 'cheque_especial',
        ]);

        $disponivel = round($this->conta()->available, 2);

        $this->assertGreaterThanOrEqual(-500.0, $disponivel, 'Não pode passar do piso.');

        // Se aceitou, tem de ser exatamente −500,00.
        if ($disponivel < 0.0) {
            $this->assertSame(-500.0, $disponivel, 'No limite exato, o disponível tem de ser −500,00.');
        }
    }

    /** Um centavo acima do limite tem de ser recusado. */
    public function test_um_centavo_acima_do_limite_e_recusado(): void
    {
        $this->lancar([
            'amount' => '600,01',
            'funding_source' => 'cheque_especial',
        ]);

        $this->assertGreaterThanOrEqual(
            -500.0,
            round($this->conta()->available, 2),
            'R$ 600,01 excede em 1 centavo o total disponível e não podia entrar.',
        );
    }

    /**
     * Conta SEM cheque especial: gasto acima do saldo é recusado, e o saldo
     * não pode ficar negativo em nenhuma hipótese.
     */
    public function test_conta_sem_cheque_especial_nao_fica_negativa(): void
    {
        $semLimite = Account::factory()->for($this->titular)->create([
            'type' => 'checking',
            'initial_balance' => 50,
            'overdraft_limit' => 0,
        ]);

        $this->actingAs($this->titular)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '80,00',
            'date' => now()->toDateString(),
            'account_id' => $semLimite->id,
            'category_id' => $this->catDespesa->id,
            'funding_source' => 'cheque_especial', // tenta forçar
        ]);

        $this->assertGreaterThanOrEqual(
            0.0,
            round(Account::find($semLimite->id)->available, 2),
            'Conta sem cheque especial ficou negativa — a trava foi furada pelo campo funding_source.',
        );
    }

    /**
     * FAMÍLIA: o dependente lança e o dinheiro sai da MESMA conta do titular.
     * É o comportamento desejado (a família compartilha tudo), e precisa somar certo.
     */
    public function test_dependente_lanca_na_mesma_conta_e_o_saldo_e_compartilhado(): void
    {
        $antes = $this->conta()->available;

        $this->lancar(['amount' => '40,00'], como: $this->dependente)
            ->assertSessionHasNoErrors();

        $this->assertSame(
            round($antes - 40.0, 2),
            round($this->conta()->available, 2),
            'O gasto do dependente tem de sair do saldo da família.',
        );

        // E o autor tem de ficar registrado (é o que alimenta "quanto cada um gastou").
        $transacao = Transaction::where('account_id', $this->conta->id)->latest('id')->firstOrFail();
        $this->assertSame(
            $this->dependente->id,
            $transacao->made_by_user_id,
            'O lançamento tem de registrar QUEM fez a compra.',
        );

        // O dono do dado continua sendo o titular (escopo da família).
        $this->assertSame($this->titular->id, $transacao->user_id);
    }

    /**
     * ISOLAMENTO: outra família não pode lançar na minha conta nem ver meu saldo.
     * Este é o teste que protege o dinheiro de terceiros.
     */
    public function test_outra_familia_nao_toca_no_meu_dinheiro(): void
    {
        $estranho = User::factory()->create(['is_admin' => true]);
        $categoriaDele = Category::factory()->for($estranho)->expense()->create();

        $saldoAntes = $this->conta()->available;

        // Tenta lançar despesa NA MINHA conta.
        $this->actingAs($estranho)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '100,00',
            'date' => now()->toDateString(),
            'account_id' => $this->conta->id,     // conta de outra família
            'category_id' => $categoriaDele->id,
        ])->assertSessionHasErrors();

        $this->assertSame(
            round($saldoAntes, 2),
            round($this->conta()->available, 2),
            'Usuário de outra família mexeu no meu saldo.',
        );

        // E não consegue nem ver a conta na tela dele.
        $this->actingAs($estranho)->get(route('accounts.index'))
            ->assertOk()
            ->assertDontSee($this->conta->name);
    }

    /**
     * O dependente NÃO pode se promover a titular pelo formulário de perfil
     * (campos de privilégio não são preenchíveis).
     */
    public function test_dependente_nao_se_promove_a_titular(): void
    {
        $this->actingAs($this->dependente)->patch(route('profile.update'), [
            'name' => 'Tentativa',
            'email' => $this->dependente->email,
            'is_admin' => 1,
            'account_owner_id' => null,
        ]);

        $this->dependente->refresh();

        $this->assertFalse((bool) $this->dependente->is_admin, 'O dependente virou titular.');
        $this->assertSame(
            $this->titular->id,
            $this->dependente->account_owner_id,
            'O dependente escapou da família.',
        );
    }
}
