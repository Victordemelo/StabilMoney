<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Atividade;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Registro de atividade — o DINHEIRO (out/2026).
 *
 * Cada gesto que mexe em dinheiro vira UMA linha legível, com quem fez, o valor e onde:
 * lançar, editar (com o antes → depois), excluir, transferir, parcelar, pagar e estornar a
 * fatura, guardar numa meta. E os caminhos que escrevem por baixo do Eloquent (delete em
 * massa das parcelas, desfazer a transferência) também registram — eles não disparam evento.
 *
 * O registro vive na MESMA transação da ação: uma falha no meio não deixa linha contando
 * uma história que não aconteceu.
 */
class AtividadeDoDinheiroTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private User $maria;

    private Account $corrente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['name' => 'Victor', 'is_admin' => true]);
        $this->maria = User::factory()->create(['name' => 'Maria', 'account_owner_id' => $this->titular->id, 'is_admin' => false]);

        $this->corrente = Account::factory()->for($this->titular)->create([
            'name' => 'Conta Corrente', 'type' => 'checking', 'bank' => 'nubank',
            'initial_balance' => 5000, 'overdraft_limit' => 0,
        ]);

        // O que os factories acima registraram (a criação da conta) não é o assunto aqui.
        Atividade::query()->delete();
    }

    private function hoje(): string
    {
        return CarbonImmutable::today()->toDateString();
    }

    public function test_lancar_uma_despesa_registra_quem_o_que_quanto_e_onde(): void
    {
        $this->actingAs($this->maria)->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => '120,00', 'description' => 'Mercado',
            'account_id' => $this->corrente->id, 'date' => $this->hoje(),
        ])->assertSessionHasNoErrors();

        $linha = Atividade::sole();

        $this->assertSame('transacao.criada', $linha->acao);
        $this->assertSame('dinheiro', $linha->grupo);
        $this->assertSame($this->titular->id, $linha->owner_id, 'A linha é da FAMÍLIA (titular), não de quem lançou.');
        $this->assertSame($this->maria->id, $linha->user_id);
        $this->assertSame('Maria', $linha->autor_nome);
        $this->assertSame('Maria lançou a despesa “Mercado” de R$ 120,00 em Conta Corrente (Nubank)', $linha->descricao);
        $this->assertSame('127.0.0.1', $linha->ip);
        $this->assertSame('Transaction', $linha->alvo_tipo);
    }

    public function test_editar_registra_o_antes_e_o_depois_so_do_que_mudou(): void
    {
        $despesa = Transaction::factory()->for($this->titular)->create([
            'account_id' => $this->corrente->id, 'type' => 'expense', 'amount' => 100,
            'description' => 'Mercado', 'date' => $this->hoje(), 'made_by_user_id' => $this->titular->id,
        ]);
        Atividade::query()->delete();

        $this->actingAs($this->titular)->put(route('transactions.update', $despesa), [
            'type' => 'expense', 'amount' => '150,00', 'description' => 'Mercado',
            'account_id' => $this->corrente->id, 'date' => $this->hoje(),
            'made_by_user_id' => $this->titular->id,
        ])->assertSessionHasNoErrors();

        $linha = Atividade::where('acao', 'transacao.editada')->sole();

        $this->assertSame('Victor editou a despesa “Mercado”', $linha->descricao);
        $this->assertSame(
            [['campo' => 'amount', 'rotulo' => 'Valor', 'antes' => 'R$ 100,00', 'depois' => 'R$ 150,00']],
            $linha->mudancas,
        );
    }

    public function test_excluir_registra_o_que_saiu(): void
    {
        $despesa = Transaction::factory()->for($this->titular)->create([
            'account_id' => $this->corrente->id, 'type' => 'expense', 'amount' => 80,
            'description' => 'Farmácia', 'date' => $this->hoje(),
        ]);
        Atividade::query()->delete();

        $this->actingAs($this->titular)->delete(route('transactions.destroy', $despesa))->assertSessionHasNoErrors();

        $this->assertSame(
            'Victor excluiu a despesa “Farmácia” de R$ 80,00 de Conta Corrente (Nubank)',
            Atividade::where('acao', 'transacao.excluida')->sole()->descricao,
        );
    }

    public function test_transferencia_e_uma_linha_so_e_desfazer_tambem_registra(): void
    {
        $poupanca = Account::factory()->for($this->titular)->create([
            'name' => 'Poupança', 'type' => 'savings', 'bank' => 'caixa', 'initial_balance' => 0,
        ]);
        Atividade::query()->delete();

        $this->actingAs($this->titular)->postJson(route('transactions.transfer'), [
            'amount' => '300,00', 'account_id' => $this->corrente->id,
            'to_account_id' => $poupanca->id, 'date' => $this->hoje(),
        ])->assertCreated();

        $feita = Atividade::sole();
        $this->assertSame('transferencia.feita', $feita->acao, 'As duas pontas são UM gesto: uma linha só.');
        $this->assertSame('Victor transferiu R$ 300,00 de Conta Corrente (Nubank) para Poupança (Caixa)', $feita->descricao);

        // Desfazer apaga as duas pontas com delete EM MASSA — sem evento do Eloquent.
        $ponta = Transaction::where('type', 'expense')->firstOrFail();
        $this->actingAs($this->titular)->delete(route('transactions.destroy', $ponta))->assertSessionHasNoErrors();

        $this->assertSame(
            'Victor desfez a transferência de R$ 300,00 de Conta Corrente (Nubank) para Poupança (Caixa)',
            Atividade::where('acao', 'transferencia.desfeita')->sole()->descricao,
        );
    }

    public function test_compra_parcelada_e_uma_linha_e_a_exclusao_em_massa_das_parcelas_registra(): void
    {
        $cartao = Account::factory()->for($this->titular)->creditCard()->create([
            'name' => 'Roxinho', 'bank' => 'nubank', 'credit_limit' => 10000, 'closing_day' => 10, 'due_day' => 17,
        ]);
        Atividade::query()->delete();

        $this->actingAs($this->maria)->post(route('faturas.lancar'), [
            'description' => 'Geladeira', 'amount' => '3.000,00', 'account_id' => $cartao->id,
            'date' => $this->hoje(), 'mode' => 'parcelado', 'installments' => 3,
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, Transaction::where('account_id', $cartao->id)->count());
        $linha = Atividade::sole();
        $this->assertSame('compra.parcelada', $linha->acao, 'Três parcelas, uma compra: uma linha.');
        $this->assertSame('Maria lançou a compra “Geladeira” em 3x de R$ 1.000,00 em Roxinho (Crédito · Nubank)', $linha->descricao);

        // `FaturaController::destroy` apaga as parcelas em aberto com `->delete()` no builder.
        $primeira = Transaction::where('account_id', $cartao->id)->orderBy('installment_no')->firstOrFail();
        $this->actingAs($this->titular)->delete(route('faturas.compra.destroy', $primeira), ['password' => 'password'])->assertSessionHasNoErrors();

        $this->assertSame(0, Transaction::where('account_id', $cartao->id)->count());
        $this->assertSame(
            'Victor excluiu a compra parcelada “Geladeira” de Roxinho (Crédito · Nubank) (3 parcelas em aberto)',
            Atividade::where('acao', 'compra.excluida')->sole()->descricao,
        );
    }

    public function test_pagar_e_estornar_a_fatura_registram_as_duas_pontas(): void
    {
        $cartao = Account::factory()->for($this->titular)->creditCard()->create([
            'name' => 'Roxinho', 'bank' => 'nubank', 'credit_limit' => 10000, 'closing_day' => 10, 'due_day' => 17,
        ]);
        Transaction::factory()->for($this->titular)->create([
            'account_id' => $cartao->id, 'type' => 'expense', 'amount' => 400,
            'description' => 'Tênis', 'date' => $this->hoje(),
        ]);
        Atividade::query()->delete();

        $this->actingAs($this->titular)->post(route('faturas.fatura.pagar', $cartao), [
            'pay_account_id' => $this->corrente->id, 'ciclo' => 'aberto', 'paid_on' => $this->hoje(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Victor pagou R$ 400,00 da fatura do cartão “Roxinho (Crédito · Nubank)”, saindo de Conta Corrente (Nubank)',
            Atividade::where('acao', 'fatura.paga')->sole()->descricao,
        );

        $quitacao = Transaction::whereNotNull('settles_account_id')->firstOrFail();
        $this->actingAs($this->titular)->delete(route('faturas.fatura.estornar', $quitacao))->assertSessionHasNoErrors();

        $this->assertSame(
            'Victor estornou o pagamento de R$ 400,00 da fatura do cartão “Roxinho (Crédito · Nubank)”: o dinheiro voltou para Conta Corrente (Nubank)',
            Atividade::where('acao', 'fatura.estornada')->sole()->descricao,
        );
    }

    public function test_guardar_numa_meta_registra_o_aporte(): void
    {
        $meta = Goal::factory()->for($this->titular)->create(['name' => 'Viagem', 'emoji' => '✈️', 'target_amount' => 10000]);
        Atividade::query()->delete();

        $this->actingAs($this->maria)->post(route('metas.aportes.store', $meta), [
            'account_id' => $this->corrente->id, 'amount' => '250,00', 'date' => $this->hoje(),
        ])->assertSessionHasNoErrors();

        $linha = Atividade::where('acao', 'meta.aporte')->sole();
        $this->assertSame('Maria guardou R$ 250,00 na meta “Viagem”, saindo de Conta Corrente (Nubank)', $linha->descricao);
        $this->assertSame($this->titular->id, $linha->owner_id);
    }

    public function test_despesa_paga_com_resgate_registra_o_resgate_e_a_exclusao_o_desfaz(): void
    {
        $tesouro = Investment::factory()->for($this->titular)->create(['name' => 'Tesouro Selic']);
        InvestmentContribution::factory()->for($tesouro)->create([
            'account_id' => $this->corrente->id, 'type' => 'aporte', 'amount' => 4800, 'date' => $this->hoje(),
        ]);
        Atividade::query()->delete();

        // Disponível = 5000 − 4800 = 200: a despesa de 500 só cabe resgatando 300.
        $this->actingAs($this->titular)->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => '500,00', 'description' => 'Conserto do carro',
            'account_id' => $this->corrente->id, 'date' => $this->hoje(),
            'funding_source' => FundingSource::RESGATE_INVESTIMENTO, 'funding_investment_id' => $tesouro->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Victor resgatou R$ 300,00 de “Tesouro Selic” para cobrir uma despesa em Conta Corrente (Nubank)',
            Atividade::where('acao', 'investimento.resgate')->sole()->descricao,
        );

        // O resgate sai por um delete EM MASSA (`FundingService::estornarFonte`).
        $despesa = Transaction::where('description', 'Conserto do carro')->firstOrFail();
        $this->actingAs($this->titular)->delete(route('transactions.destroy', $despesa))->assertSessionHasNoErrors();

        $this->assertSame(
            'Victor desfez o resgate de R$ 300,00 que cobria a despesa “Conserto do carro” em Conta Corrente (Nubank): o dinheiro voltou para o investimento',
            Atividade::where('acao', 'resgate.desfeito')->sole()->descricao,
        );
    }

    public function test_falha_no_meio_nao_deixa_registro_de_algo_que_nao_aconteceu(): void
    {
        $cartao = Account::factory()->for($this->titular)->creditCard()->create([
            'name' => 'Roxinho', 'bank' => 'nubank', 'credit_limit' => 10000, 'closing_day' => 10, 'due_day' => 17,
        ]);
        Atividade::query()->delete();

        // A 1ª parcela entra (e com ela a linha de atividade da compra); a 3ª explode. A
        // transação do FundingService desfaz tudo — a linha de atividade tem de ir junto.
        Transaction::created(function (Transaction $t) {
            if ((int) $t->installment_no === 3) {
                throw new RuntimeException('falha simulada no meio da compra');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->titular)->post(route('faturas.lancar'), [
                'description' => 'Geladeira', 'amount' => '3.000,00', 'account_id' => $cartao->id,
                'date' => $this->hoje(), 'mode' => 'parcelado', 'installments' => 3,
            ]);
            $this->fail('A falha simulada deveria ter subido.');
        } catch (RuntimeException $e) {
            $this->assertSame('falha simulada no meio da compra', $e->getMessage());
        }

        $this->assertSame(0, Transaction::count(), 'Nenhuma parcela pode ter ficado.');
        $this->assertSame(0, Atividade::count(), 'O registro da compra desfeita não pode ter ficado.');
    }
}
