<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\SidebarService;
use App\Support\FundingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pix como método de pagamento.
 *
 * Do ponto de vista de QUEM PAGA — que é o que este app modela — Pix e cartão de
 * débito são a mesma coisa: o dinheiro sai da conta na hora, não existe fatura, e
 * se o saldo acabar o cheque especial daquela conta entra automaticamente. As
 * diferenças que a internet cita (liquidação instantânea × D+1, rede de cartão ×
 * transferência do Banco Central, tarifa) são do LOJISTA que recebe.
 *
 * Uma diferença importa aqui: **uma chave Pix é registrada em UMA conta**, enquanto
 * o cartão de débito saca da corrente E da poupança. Por isso o Pix vincula uma só.
 *
 * A tela `/faturas` já prometia Pix em dois lugares ("débito, Pix e conta já
 * descontam na hora") desde antes de ele existir. Agora a promessa é verdade.
 */
class PixComoMetodoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $corrente;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-05');

        $this->user = User::factory()->create();
        $this->corrente = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Corrente Nubank',
            'initial_balance' => 1000,
            'overdraft_limit' => 500,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pix(?Account $conta = null): Account
    {
        return Account::factory()->for($this->user)
            ->pix(($conta ?? $this->corrente)->id, ($conta ?? $this->corrente)->type)
            ->create(['name' => 'Pix Nubank']);
    }

    // ================= Cadastro =================

    public function test_cria_pix_apontando_para_a_conta_corrente(): void
    {
        $this->actingAs($this->user)->post(route('accounts.store'), [
            'name' => 'Pix Nubank',
            'type' => 'pix',
            'bank' => 'nubank',
            'pix_account_id' => $this->corrente->id,
        ])->assertSessionHasNoErrors();

        $pix = Account::where('type', 'pix')->firstOrFail();

        // O select manda UM id; o servidor devolve para a coluna do tipo certo.
        $this->assertSame($this->corrente->id, $pix->checking_account_id);
        $this->assertNull($pix->savings_account_id);
        $this->assertSame('Pix', $pix->typeLabel());
    }

    public function test_pix_pode_apontar_para_a_poupanca(): void
    {
        $poupanca = Account::factory()->for($this->user)->create([
            'type' => 'savings', 'name' => 'Poupança', 'initial_balance' => 800,
        ]);

        $this->actingAs($this->user)->post(route('accounts.store'), [
            'name' => 'Pix da poupança',
            'type' => 'pix',
            'bank' => 'itau',
            'pix_account_id' => $poupanca->id,
        ])->assertSessionHasNoErrors();

        $pix = Account::where('type', 'pix')->firstOrFail();
        $this->assertSame($poupanca->id, $pix->savings_account_id);
        $this->assertNull($pix->checking_account_id);
    }

    public function test_pix_sem_conta_vinculada_e_recusado(): void
    {
        $this->actingAs($this->user)->post(route('accounts.store'), [
            'name' => 'Pix solto',
            'type' => 'pix',
            'bank' => 'nubank',
        ])->assertSessionHasErrors();

        $this->assertDatabaseCount('accounts', 1); // só a corrente do setUp
    }

    public function test_pix_nao_pode_apontar_para_duas_contas(): void
    {
        $poupanca = Account::factory()->for($this->user)->create([
            'type' => 'savings', 'initial_balance' => 500,
        ]);

        // Uma chave Pix vive numa conta só. Somar duas anunciaria um saldo que a
        // chave não consegue movimentar.
        $this->actingAs($this->user)->post(route('accounts.store'), [
            'name' => 'Pix ganancioso',
            'type' => 'pix',
            'bank' => 'nubank',
            'checking_account_id' => $this->corrente->id,
            'savings_account_id' => $poupanca->id,
        ])->assertSessionHasErrors('checking_account_id');
    }

    public function test_pix_de_conta_de_outra_familia_e_recusado(): void
    {
        $estranho = User::factory()->create();
        $contaAlheia = Account::factory()->for($estranho)->create([
            'type' => 'checking', 'initial_balance' => 9999,
        ]);

        $this->actingAs($this->user)->post(route('accounts.store'), [
            'name' => 'Pix invasor',
            'type' => 'pix',
            'bank' => 'nubank',
            'pix_account_id' => $contaAlheia->id,
        ])->assertSessionHasErrors();

        $this->assertDatabaseMissing('accounts', ['name' => 'Pix invasor']);
    }

    // ================= Dinheiro: espelha, não duplica =================

    public function test_pix_nao_tem_saldo_proprio_espelha_a_conta(): void
    {
        $pix = $this->pix();

        $this->assertSame(1000.0, $pix->balance, 'o saldo é o da conta da chave');
        $this->assertSame(1000.0, $pix->available);

        // Gastar pela conta muda o que o Pix mostra — é o mesmo dinheiro.
        Transaction::factory()->for($this->user)->for($this->corrente)->expense()
            ->create(['amount' => 300, 'date' => '2026-08-05']);

        $this->assertSame(700.0, $pix->fresh()->available);
    }

    public function test_pix_nao_entra_no_patrimonio_e_nao_conta_o_dinheiro_duas_vezes(): void
    {
        $this->pix();

        $dashboard = app(DashboardService::class)->build($this->user->id);
        $sidebar = app(SidebarService::class)->build($this->user->id);

        // Se o Pix entrasse no patrimônio, a mesma corrente contaria 2x = 2.000.
        $this->assertSame(1000.0, $dashboard['payload']['periods']['mes']['stats']['saldo']);
        $this->assertSame(1000.0, $sidebar['saldoTotal']);
    }

    public function test_pix_espelha_o_disponivel_nao_o_bruto(): void
    {
        $pix = $this->pix();

        // Guardar dinheiro numa meta reserva o valor: ele continua na conta, mas
        // não é para gastar — e o Pix não pode oferecê-lo.
        $this->actingAs($this->user)->post(route('metas.store'), [
            'name' => 'Viagem', 'emoji' => '✈️', 'color' => '#0F6B47',
            'target_amount' => '5.000,00', 'target_date' => '2027-01-01',
        ])->assertSessionHasNoErrors();

        $meta = Goal::firstOrFail();
        $this->actingAs($this->user)->post(route('metas.aportes.store', $meta), [
            'account_id' => $this->corrente->id, 'amount' => '400,00',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1000.0, $pix->fresh()->balance, 'o bruto não muda');
        $this->assertSame(600.0, $pix->fresh()->available, 'o disponível cai com a reserva');
    }

    // ================= Lançar: sai da conta, na hora =================

    public function test_o_select_de_pagamento_manda_a_conta_e_nao_o_pix(): void
    {
        $pix = $this->pix();

        $opcoes = Account::paymentOptions($this->user->id);
        $opcaoPix = $opcoes->firstWhere('type', 'pix');

        $this->assertNotNull($opcaoPix, 'o Pix precisa aparecer como método');
        $this->assertSame($this->corrente->id, $opcaoPix->id, 'mas submete o id da CONTA');
        $this->assertFalse($opcaoPix->isCard);
        $this->assertStringContainsString('Pix Nubank', $opcaoPix->name);
    }

    public function test_lancar_direto_no_pix_e_recusado(): void
    {
        $pix = $this->pix();

        // Pix não é conta de lançamento: não tem saldo próprio, então gravar nele
        // deixaria a despesa sem descontar de lugar nenhum.
        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '100,00',
            'account_id' => $pix->id,
            'date' => '2026-08-05',
            'description' => 'Tentativa',
        ])->assertSessionHasErrors('account_id');

        $this->assertSame(0, Transaction::count());
    }

    public function test_despesa_por_pix_sai_do_saldo_na_hora_sem_fatura(): void
    {
        $pix = $this->pix();

        // O usuário escolhe "Pix" na tela; o select submete a conta vinculada.
        $contaSubmetida = Account::paymentOptions($this->user->id)->firstWhere('type', 'pix')->id;

        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '250,00',
            'account_id' => $contaSubmetida,
            'date' => '2026-08-05',
            'description' => 'Almoço no Pix',
        ])->assertSessionHasNoErrors();

        // Saiu na hora, como débito — e nada foi para fatura nenhuma.
        $this->assertSame(750.0, $this->corrente->fresh()->available);
        $this->assertSame(750.0, $pix->fresh()->available);

        $lancamento = Transaction::firstOrFail();
        $this->assertSame($this->corrente->id, $lancamento->account_id);
        $this->assertNull($lancamento->installments);
    }

    public function test_pix_sem_saldo_pergunta_a_fonte_e_usa_o_cheque_especial(): void
    {
        $this->pix();
        $contaSubmetida = Account::paymentOptions($this->user->id)->firstWhere('type', 'pix')->id;

        // Disponível 1.000, despesa 1.200: falta 200. Como em qualquer conta
        // corrente, o app pergunta de onde sai (409), nunca usa o cheque sozinho.
        $this->actingAs($this->user)->postJson(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '1.200,00',
            'account_id' => $contaSubmetida,
            'date' => '2026-08-05',
            'description' => 'Compra grande no Pix',
        ])->assertStatus(409);

        $this->assertSame(0, Transaction::count());

        // Com a fonte escolhida, passa e a conta fica no vermelho.
        $this->actingAs($this->user)->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => '1.200,00',
            'account_id' => $contaSubmetida,
            'date' => '2026-08-05',
            'description' => 'Compra grande no Pix',
            'funding_source' => FundingSource::CHEQUE_ESPECIAL,
        ])->assertSessionHasNoErrors();

        $this->assertSame(-200.0, $this->corrente->fresh()->available);
    }

    public function test_pix_nao_aceita_parcelamento(): void
    {
        $this->pix();
        $contaSubmetida = Account::paymentOptions($this->user->id)->firstWhere('type', 'pix')->id;

        // Parcelar é do cartão de crédito. Pix não tem fatura nem limite.
        $this->actingAs($this->user)->post(route('faturas.lancar'), [
            'description' => 'Geladeira',
            'amount' => '900,00',
            'date' => '2026-08-05',
            'account_id' => $contaSubmetida,
            'mode' => 'parcelado',
            'installments' => 3,
        ])->assertSessionHasErrors('mode');
    }

    public function test_pix_nao_tem_limite_nem_fatura(): void
    {
        $pix = $this->pix();

        $this->assertFalse($pix->isCard());
        $this->assertTrue($pix->isPix());
        $this->assertTrue($pix->espelhaConta());
        $this->assertFalse($pix->isCash(), 'Pix não é caixa: quem tem saldo é a conta');
        $this->assertSame('debito', $pix->classe(), 'mesma classe do débito');
        $this->assertNull($pix->credit_limit);
    }

    // ================= Telas =================

    public function test_a_tela_de_metodos_mostra_o_pix_e_a_conta_de_origem(): void
    {
        $this->pix();

        $this->actingAs($this->user)->get(route('accounts.index'))
            ->assertOk()
            ->assertSee('Pix Nubank')
            ->assertSee('disponível para Pix')
            ->assertSee('Corrente Nubank');
    }

    public function test_o_formulario_oferece_pix_como_tipo(): void
    {
        $this->actingAs($this->user)->get(route('accounts.create'))
            ->assertOk()
            ->assertSee('value="pix"', false)
            ->assertSee('Conta da chave Pix');
    }

    public function test_a_promessa_da_tela_de_faturas_agora_e_verdade(): void
    {
        $this->pix();

        // A tela diz "débito, Pix e conta já descontam na hora" desde antes de o
        // Pix existir. Este teste amarra o texto ao método realmente existir.
        $this->actingAs($this->user)->get(route('faturas.index'))
            ->assertOk()
            ->assertSee('Pix');

        $this->assertTrue(
            Account::where('user_id', $this->user->id)->where('type', 'pix')->exists(),
        );
        $this->assertArrayHasKey('pix', Account::TYPES);
    }
}
