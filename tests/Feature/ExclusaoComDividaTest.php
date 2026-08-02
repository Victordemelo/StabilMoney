<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Excluir com dívida na mesa — duas guardas de naturezas DIFERENTES,
 * deliberadamente resolvidas de formas diferentes:
 *
 * 1) META / INVESTIMENTO com conta no vermelho → BLOQUEIA.
 *    Não porque criaria dinheiro: excluir o cofrinho e resgatá-lo por inteiro
 *    derrubam o `reserved` da conta no MESMO valor, o disponível termina
 *    idêntico nos dois caminhos. O que se perde na exclusão é o REGISTRO de
 *    que foi a poupança que cobriu o negativo — e "excluir" é um caminho
 *    acidental (limpar uma lista), não uma decisão de quitar dívida. Então
 *    pedimos o caminho explícito, que deixa rastro.
 *
 * 2) CONTA DE USUÁRIO com pendência → NÃO bloqueia, pede consentimento.
 *    Travar a exclusão por causa de um número interno de bookkeeping brigaria
 *    com o direito de eliminação (LGPD art. 18) que a Política de Privacidade
 *    do app promete, e o app não movimenta dinheiro de verdade. R$ 0,01 de
 *    cheque especial usado trancaria a pessoa na conta para sempre. Em vez
 *    disso, o modal lista o que fica pendente e exige um segundo aceite.
 */
class ExclusaoComDividaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-02');

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Corrente',
            'initial_balance' => 1000,
            'overdraft_limit' => 2000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ============================ Apoios ============================

    /**
     * Joga a conta corrente para o vermelho, usando o cheque especial.
     *
     * Com R$ 1.000 de saldo inicial, uma despesa de R$ 1.500 deixa o saldo em
     * −R$ 500. Somado ao que estiver reservado num cofrinho, o `available`
     * fica bem abaixo de zero.
     */
    private function derrubaSaldoDaConta(float $valor = 1500): void
    {
        Transaction::factory()->for($this->user)->for($this->conta)->expense()->create([
            'amount' => $valor,
            'date' => '2026-08-02',
            'description' => 'Conserto do carro',
        ]);
    }

    /** Investimento com `$aplicado` reservado a partir da conta corrente. */
    private function investimentoCom(float $aplicado): Investment
    {
        $investimento = Investment::factory()->for($this->user)->create(['name' => 'CDB Liquidez']);

        InvestmentContribution::factory()->for($investimento)->for($this->conta)->aporte()
            ->create(['amount' => $aplicado, 'date' => '2026-07-01']);

        return $investimento;
    }

    /** Meta com `$guardado` reservado a partir da conta corrente. */
    private function metaCom(float $guardado): Goal
    {
        $meta = Goal::factory()->for($this->user)->create([
            'name' => 'Viagem',
            'target_amount' => 5000,
        ]);

        GoalContribution::factory()->for($meta)->for($this->conta)->aporte()
            ->create(['amount' => $guardado, 'date' => '2026-07-01']);

        return $meta;
    }

    // ==================== Investimento ====================

    public function test_investimento_com_aporte_nao_pode_ser_excluido_com_a_conta_no_vermelho(): void
    {
        $investimento = $this->investimentoCom(800);
        $this->derrubaSaldoDaConta();

        // −500 de saldo − 800 reservados = −1.300 disponíveis.
        $this->assertSame(-1300.0, $this->conta->fresh()->available);

        $this->actingAs($this->user)
            ->from(route('investimentos.index'))
            ->delete(route('investimentos.destroy', $investimento))
            ->assertSessionHasErrors('investimento')
            ->assertRedirect(route('investimentos.index'));

        // O investimento continua de pé, com o dinheiro dele intacto.
        $this->assertDatabaseHas('investments', ['id' => $investimento->id]);
        $this->assertSame(800.0, $investimento->fresh()->aplicado);
        $this->assertSame(-1300.0, $this->conta->fresh()->available);
    }

    public function test_a_mensagem_diz_qual_conta_e_quanto(): void
    {
        $investimento = $this->investimentoCom(800);
        $this->derrubaSaldoDaConta();

        $erro = $this->actingAs($this->user)
            ->delete(route('investimentos.destroy', $investimento))
            ->assertSessionHasErrors('investimento')
            ->getSession()->get('errors')->first('investimento');

        $this->assertStringContainsString('Corrente', $erro);
        $this->assertStringContainsString('−R$ 1.300,00', $erro);
        $this->assertStringContainsString('R$ 800,00', $erro);
        // Enquadramento honesto: a guarda é de registro, não de "criar dinheiro".
        $this->assertStringContainsString('resgate', mb_strtolower($erro));
    }

    public function test_investimento_com_aporte_pode_ser_excluido_com_a_conta_no_azul(): void
    {
        $investimento = $this->investimentoCom(800);

        // 1.000 − 800 reservados = 200 disponíveis: nada a proteger.
        $this->assertSame(200.0, $this->conta->fresh()->available);

        $this->actingAs($this->user)
            ->delete(route('investimentos.destroy', $investimento))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('investimentos.index'));

        $this->assertDatabaseMissing('investments', ['id' => $investimento->id]);
        $this->assertSame(1000.0, $this->conta->fresh()->available);
    }

    public function test_investimento_vazio_pode_ser_excluido_mesmo_com_a_conta_no_vermelho(): void
    {
        $investimento = Investment::factory()->for($this->user)->create();
        $this->derrubaSaldoDaConta();

        // Sem nada aplicado, excluir não mexe no disponível de conta nenhuma.
        $this->actingAs($this->user)
            ->delete(route('investimentos.destroy', $investimento))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('investments', ['id' => $investimento->id]);
    }

    public function test_investimento_ja_totalmente_resgatado_pode_ser_excluido_com_a_conta_no_vermelho(): void
    {
        $investimento = $this->investimentoCom(800);
        InvestmentContribution::factory()->for($investimento)->for($this->conta)->resgate()
            ->create(['amount' => 800, 'date' => '2026-07-20']);
        $this->derrubaSaldoDaConta();

        // Aplicado zerado: a exclusão já não tem efeito nenhum sobre o saldo.
        $this->assertSame(0.0, $investimento->fresh()->aplicado);

        $this->actingAs($this->user)
            ->delete(route('investimentos.destroy', $investimento))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('investments', ['id' => $investimento->id]);
    }

    public function test_conta_no_vermelho_que_nao_aportou_no_investimento_nao_trava_a_exclusao(): void
    {
        // A guarda olha as contas que ainda têm dinheiro NESTE cofrinho — uma
        // outra conta no vermelho não tem relação com ele.
        $outra = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Segunda conta',
            'initial_balance' => 0,
            'overdraft_limit' => 1000,
        ]);
        Transaction::factory()->for($this->user)->for($outra)->expense()
            ->create(['amount' => 400, 'date' => '2026-08-02']);

        $investimento = $this->investimentoCom(800);

        $this->assertSame(-400.0, $outra->fresh()->available);

        $this->actingAs($this->user)
            ->delete(route('investimentos.destroy', $investimento))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('investments', ['id' => $investimento->id]);
    }

    // ==================== Meta ====================

    public function test_meta_com_aporte_nao_pode_ser_excluida_com_a_conta_no_vermelho(): void
    {
        $meta = $this->metaCom(800);
        $this->derrubaSaldoDaConta();

        $this->actingAs($this->user)
            ->from(route('metas.index'))
            ->delete(route('metas.destroy', $meta))
            ->assertSessionHasErrors('meta')
            ->assertRedirect(route('metas.index'));

        $this->assertDatabaseHas('goals', ['id' => $meta->id]);
        $this->assertSame(800.0, $meta->fresh()->saved);
        $this->assertSame(-1300.0, $this->conta->fresh()->available);
    }

    public function test_meta_com_aporte_pode_ser_excluida_com_a_conta_no_azul(): void
    {
        $meta = $this->metaCom(800);

        $this->actingAs($this->user)
            ->delete(route('metas.destroy', $meta))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('metas.index'));

        $this->assertDatabaseMissing('goals', ['id' => $meta->id]);
        $this->assertSame(1000.0, $this->conta->fresh()->available);
    }

    public function test_meta_vazia_pode_ser_excluida_mesmo_com_a_conta_no_vermelho(): void
    {
        $meta = Goal::factory()->for($this->user)->create();
        $this->derrubaSaldoDaConta();

        $this->actingAs($this->user)
            ->delete(route('metas.destroy', $meta))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('goals', ['id' => $meta->id]);
    }

    // ==================== Excluir a conta de usuário ====================

    /** Cria um cartão de crédito com uma compra em aberto (fatura não paga). */
    private function cartaoComFaturaEmAberto(): Account
    {
        $cartao = Account::factory()->for($this->user)->creditCard()->create(['name' => 'Nubank']);

        Transaction::factory()->for($this->user)->for($cartao)->expense()
            ->create(['amount' => 450, 'date' => '2026-08-02', 'description' => 'Mercado']);

        return $cartao;
    }

    /** Conta fixa mensal já vencida (vence dia 1; hoje é 02/08). */
    private function contaFixaVencida(): FixedBill
    {
        return FixedBill::factory()->for($this->user)->create([
            'name' => 'Aluguel',
            'amount' => 1800,
            'due_day' => 1,
            'starts_on' => '2026-07-01',
        ]);
    }

    public function test_sem_pendencia_a_exclusao_da_conta_segue_so_com_a_senha(): void
    {
        // Só a conta corrente do setUp, no azul: nada a avisar.
        $this->actingAs($this->user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    public function test_com_saldo_negativo_a_exclusao_exige_o_aceite_explicito(): void
    {
        $this->derrubaSaldoDaConta();

        $this->actingAs($this->user)
            ->from(route('settings', 'conta'))
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrorsIn('userDeletion', 'confirmo_pendencias')
            ->assertRedirect(route('settings', 'conta'));

        // Senha certa, mas sem o aceite: a conta continua de pé.
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_com_o_aceite_marcado_a_conta_e_apagada_mesmo_com_saldo_negativo(): void
    {
        $this->derrubaSaldoDaConta();

        $this->actingAs($this->user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
                'confirmo_pendencias' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        // O direito de eliminação (LGPD art. 18) vence o bookkeeping.
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    public function test_fatura_de_cartao_em_aberto_tambem_exige_o_aceite(): void
    {
        $this->cartaoComFaturaEmAberto();

        $this->actingAs($this->user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrorsIn('userDeletion', 'confirmo_pendencias');

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_conta_fixa_vencida_tambem_exige_o_aceite(): void
    {
        $this->contaFixaVencida();

        $this->actingAs($this->user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrorsIn('userDeletion', 'confirmo_pendencias');

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_o_aceite_nao_substitui_a_senha(): void
    {
        $this->derrubaSaldoDaConta();

        $this->actingAs($this->user)
            ->delete(route('profile.destroy'), [
                'password' => 'senha-errada',
                'confirmo_pendencias' => '1',
            ])
            ->assertSessionHasErrorsIn('userDeletion', 'password');

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_dependente_nao_ve_pendencia_da_familia_ao_apagar_o_proprio_login(): void
    {
        // As contas/faturas são do titular e continuam com ele — o dependente
        // que apaga o próprio acesso não apaga esse dinheiro.
        $this->derrubaSaldoDaConta();

        $dependente = User::factory()->create([
            'account_owner_id' => $this->user->id,
            'is_admin' => false,
        ]);

        $this->actingAs($dependente)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $dependente->id]);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_a_tela_de_configuracoes_lista_as_pendencias_e_o_checkbox(): void
    {
        $this->derrubaSaldoDaConta();
        $this->cartaoComFaturaEmAberto();
        $this->contaFixaVencida();

        $this->actingAs($this->user)
            ->get(route('settings', 'conta'))
            ->assertOk()
            ->assertSee('Estas pendências continuam existindo depois da exclusão:')
            // Saldo em conta: 1.000 − 1.500 (sem nada reservado aqui).
            ->assertSee('Corrente')
            ->assertSee('−R$ 500,00', false)
            // Fatura do cartão em aberto e conta fixa vencida.
            ->assertSee('Nubank')
            ->assertSee('R$ 450,00')
            ->assertSee('Aluguel')
            ->assertSee('R$ 1.800,00')
            ->assertSee('confirmo_pendencias', false);
    }

    public function test_a_tela_de_configuracoes_nao_pede_aceite_quando_esta_tudo_em_dia(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings', 'conta'))
            ->assertOk()
            ->assertDontSee('Estas pendências continuam existindo depois da exclusão:')
            ->assertDontSee('confirmo_pendencias', false);
    }
}
