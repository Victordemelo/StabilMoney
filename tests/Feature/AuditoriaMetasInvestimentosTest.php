<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DesligaEscopoDaFamiliaNaRota;
use Tests\TestCase;

/**
 * Correções da auditoria de 27/28-07-2026 na área de metas e investimentos
 * (docs/auditoria-completa-2026-07-28.md): C-1, A-5, A-7, M-2 e M-1.
 *
 * Cada teste aqui FALHAVA antes da correção.
 */
class AuditoriaMetasInvestimentosTest extends TestCase
{
    use DesligaEscopoDaFamiliaNaRota, RefreshDatabase;

    private User $titular;

    private Account $contaA;

    private Account $contaB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create();
        $this->contaA = Account::factory()->for($this->titular)->create([
            'type' => 'checking', 'name' => 'Conta A', 'initial_balance' => 1000,
        ]);
        // Conta vazia: sem saldo e sem cheque especial.
        $this->contaB = Account::factory()->for($this->titular)->create([
            'type' => 'checking', 'name' => 'Conta B', 'initial_balance' => 0,
        ]);
    }

    // ------------------------------------------------------------------
    // C-1 · Resgate para conta que nunca aportou criava dinheiro do nada
    // ------------------------------------------------------------------

    /**
     * C-1 (meta): A aporta 1.000; resgatar 1.000 PARA B tem de ser recusado.
     * Antes passava e B ficava com reservado −1.000 e disponível +1.000 —
     * R$ 1.000 "gastáveis" numa conta com saldo zero.
     */
    public function test_resgate_de_meta_para_conta_que_nunca_aportou_e_recusado(): void
    {
        $meta = Goal::factory()->for($this->titular)->create(['target_amount' => 5000]);

        $this->actingAs($this->titular)
            ->post(route('metas.aportes.store', $meta), ['amount' => '1.000,00', 'account_id' => $this->contaA->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->titular)
            ->post(route('metas.resgates.store', $meta), ['amount' => '1.000,00', 'account_id' => $this->contaB->id])
            ->assertSessionHasErrors('amount');

        // Nada foi gravado e nenhum bolso ficou negativo.
        $this->assertSame(1, GoalContribution::count(), 'só o aporte existe');

        $b = Account::find($this->contaB->id);
        $this->assertSame(0.0, $b->reserved, 'reservado de B não pode ficar negativo');
        $this->assertSame(0.0, $b->available, 'B não pode oferecer dinheiro que não tem');
        $this->assertSame(1000.0, $meta->fresh()->saved, 'o dinheiro continua guardado na meta');
    }

    /** C-1 (investimento): mesmo cenário do teste acima, no cofrinho de investimento. */
    public function test_resgate_de_investimento_para_conta_que_nunca_aportou_e_recusado(): void
    {
        $inv = Investment::factory()->for($this->titular)->create();

        $this->actingAs($this->titular)
            ->post(route('investimentos.aportes.store', $inv), ['amount' => '1.000,00', 'account_id' => $this->contaA->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->titular)
            ->post(route('investimentos.resgates.store', $inv), ['amount' => '1.000,00', 'account_id' => $this->contaB->id])
            ->assertSessionHasErrors('amount');

        $this->assertSame(1, InvestmentContribution::count());

        $b = Account::find($this->contaB->id);
        $this->assertSame(0.0, $b->reserved);
        $this->assertSame(0.0, $b->available);
        $this->assertSame(1000.0, $inv->fresh()->aplicado);
    }

    /**
     * C-1 (limite por conta): com A e B aportando na MESMA meta, cada conta só
     * pode resgatar o que ela mesma pôs. B aportou 400 → resgate de 500 para B
     * é recusado (embora a meta tenha 1.000 guardados) e o de 400 passa.
     */
    public function test_resgate_respeita_o_que_cada_conta_aportou(): void
    {
        $meta = Goal::factory()->for($this->titular)->create(['target_amount' => 5000]);
        $this->contaB->update(['initial_balance' => 500]);

        $this->actingAs($this->titular)
            ->post(route('metas.aportes.store', $meta), ['amount' => '600,00', 'account_id' => $this->contaA->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->titular)
            ->post(route('metas.aportes.store', $meta), ['amount' => '400,00', 'account_id' => $this->contaB->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(1000.0, $meta->fresh()->saved);

        // 500 > os 400 que B aportou, mesmo cabendo nos 1.000 da meta.
        $this->actingAs($this->titular)
            ->post(route('metas.resgates.store', $meta), ['amount' => '500,00', 'account_id' => $this->contaB->id])
            ->assertSessionHasErrors('amount');

        // Exatamente o que B aportou volta sem problema.
        $this->actingAs($this->titular)
            ->post(route('metas.resgates.store', $meta), ['amount' => '400,00', 'account_id' => $this->contaB->id])
            ->assertSessionHasNoErrors();

        $b = Account::find($this->contaB->id);
        $this->assertSame(0.0, $b->reserved);
        $this->assertSame(500.0, $b->available, 'B volta ao saldo original');
        $this->assertSame(600.0, $meta->fresh()->saved, 'só o que era de A continua guardado');
    }

    /** C-1: o accessor por conta (Σ aportes − Σ resgates daquela conta) nunca vai a negativo. */
    public function test_reserved_from_account_soma_so_a_conta_pedida(): void
    {
        $meta = Goal::factory()->for($this->titular)->create();
        GoalContribution::factory()->for($meta)->for($this->contaA)->aporte()->create(['amount' => 300]);
        GoalContribution::factory()->for($meta)->for($this->contaA)->resgate()->create(['amount' => 100]);
        GoalContribution::factory()->for($meta)->for($this->contaB)->aporte()->create(['amount' => 50]);

        $this->assertSame(200.0, $meta->reservedFromAccount($this->contaA->id));
        $this->assertSame(50.0, $meta->reservedFromAccount($this->contaB->id));
        $this->assertSame(250.0, $meta->fresh()->saved);

        $inv = Investment::factory()->for($this->titular)->create();
        InvestmentContribution::factory()->for($inv)->for($this->contaA)->aporte()->create(['amount' => 80]);
        $this->assertSame(80.0, $inv->reservedFromAccount($this->contaA->id));
        $this->assertSame(0.0, $inv->reservedFromAccount($this->contaB->id), 'conta sem aporte → zero, nunca negativo');
    }

    // ------------------------------------------------------------------
    // A-5 · InvestmentController::store sem recheque sob lock
    // ------------------------------------------------------------------

    /**
     * A-5: o aporte inicial do "Novo investimento" tem de rechecar o disponível
     * DENTRO da transação. Simulamos a corrida real (dois submits validados na
     * mesma janela) consumindo o saldo da conta entre o `Investment::create` e
     * a gravação do aporte — exatamente o intervalo que não tinha proteção.
     */
    public function test_aporte_inicial_do_investimento_recheca_o_disponivel_sob_lock(): void
    {
        $concorrente = Investment::factory()->for($this->titular)->create();

        // Entre criar o investimento e gravar o aporte inicial, outra requisição
        // reserva TODO o disponível da conta.
        $jaCorreu = false;
        Investment::created(function (Investment $novo) use (&$jaCorreu, $concorrente) {
            if ($jaCorreu || $novo->is($concorrente)) {
                return;
            }
            $jaCorreu = true;

            InvestmentContribution::factory()->for($concorrente)->for($this->contaA)
                ->aporte()->create(['amount' => 1000]);
        });

        $this->actingAs($this->titular)->post(route('investimentos.store'), [
            'name' => 'CDB do duplo submit',
            'classe' => 'renda_fixa',
            'indexador' => 'CDI',
            'taxa' => '110',
            'valor_inicial' => '1.000,00',
            'account_id' => $this->contaA->id,
        ])->assertSessionHasErrors('amount');

        // A transação inteira volta atrás: nem investimento, nem aporte duplicado.
        $this->assertDatabaseMissing('investments', ['name' => 'CDB do duplo submit']);
        $this->assertSame(0, InvestmentContribution::count(), 'o rollback desfaz tudo o que a transação escreveu');
        $this->assertSame(1000.0, Account::find($this->contaA->id)->available);
    }

    /** A-5 (caminho feliz): sem corrida, o aporte inicial continua funcionando. */
    public function test_aporte_inicial_do_investimento_continua_funcionando(): void
    {
        $this->actingAs($this->titular)->post(route('investimentos.store'), [
            'name' => 'CDB normal',
            'classe' => 'renda_fixa',
            'indexador' => 'CDI',
            'taxa' => '110',
            'valor_inicial' => '400,00',
            'account_id' => $this->contaA->id,
        ])->assertSessionHasNoErrors();

        $inv = Investment::where('name', 'CDB normal')->firstOrFail();
        $this->assertSame(400.0, $inv->aplicado);
        $this->assertSame(600.0, Account::find($this->contaA->id)->available);
    }

    // ------------------------------------------------------------------
    // A-7 · Ordem de lock (conta → pai), a mesma do FundingService
    // ------------------------------------------------------------------

    /**
     * A-7: o trait travava pai → conta e o FundingService, conta → pai — ordens
     * opostas = deadlock ABBA (1213 no MySQL) e 500 na cara do usuário.
     * Aqui gravamos a ordem em que as tabelas são consultadas na gravação:
     * `accounts` tem de vir ANTES de `goals`.
     * (Em sqlite o `lockForUpdate` é no-op, mas a ORDEM das leituras é a mesma.)
     */
    public function test_ordem_de_lock_e_conta_antes_do_pai(): void
    {
        $meta = Goal::factory()->for($this->titular)->create();

        $tabelas = [];
        DB::listen(function ($query) use (&$tabelas) {
            if (! str_starts_with(strtolower(trim($query->sql)), 'select')) {
                return;
            }
            foreach (['accounts', 'goals'] as $tabela) {
                if (str_contains($query->sql, '"'.$tabela.'"') || str_contains($query->sql, '`'.$tabela.'`')) {
                    $tabelas[] = $tabela;
                }
            }
        });

        $this->actingAs($this->titular)
            ->post(route('metas.aportes.store', $meta), ['amount' => '100,00', 'account_id' => $this->contaA->id])
            ->assertSessionHasNoErrors();

        // A gravação começa quando o Form Request termina: pegamos a ÚLTIMA
        // sequência conta→pai, que é a da transação.
        $ultimaConta = array_keys($tabelas, 'accounts');
        $ultimoPai = array_keys($tabelas, 'goals');

        $this->assertNotEmpty($ultimaConta);
        $this->assertNotEmpty($ultimoPai);
        $this->assertLessThan(
            end($ultimoPai),
            end($ultimaConta),
            'a conta tem de ser travada ANTES do pai (mesma ordem do FundingService)'
        );
    }

    // ------------------------------------------------------------------
    // M-2 · A mensagem de erro vazava o saldo de outra família
    // ------------------------------------------------------------------

    /**
     * M-2: um estranho postava um resgate absurdo numa meta alheia e a
     * validação (que roda ANTES da policy) respondia "…maior que o valor
     * guardado na meta (R$ 87.345,67)". A correção pôs o 403 antes da validação.
     *
     * Desde 23/09/2026 há uma porta antes dele: o binding só encontra meta da família
     * de quem pede, e a alheia recebe o 404 de um id que não existe. O 403 do
     * `authorize()` virou linha de trás — e continua provado, com o escopo desligado.
     */
    public function test_resgate_em_meta_de_outra_familia_nao_vaza_saldo(): void
    {
        $meta = Goal::factory()->for($this->titular)->create();
        GoalContribution::factory()->for($meta)->for($this->contaA)->aporte()->create(['amount' => 873.45]);

        $estranho = User::factory()->create();
        $contaDele = Account::factory()->for($estranho)->create(['type' => 'checking', 'initial_balance' => 10]);

        $resgatar = fn () => $this->actingAs($estranho)->post(route('metas.resgates.store', $meta), [
            'amount' => '999.999,00', 'account_id' => $contaDele->id,
        ]);

        $naPortaDaFrente = $resgatar();
        $naPortaDaFrente->assertNotFound();
        $naPortaDaFrente->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('873,45', $naPortaDaFrente->getContent());

        $this->desligarEscopoDaFamiliaNaRota('meta', Goal::class);

        $naLinhaDeTras = $resgatar();
        $naLinhaDeTras->assertForbidden();
        $naLinhaDeTras->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('873,45', $naLinhaDeTras->getContent());
    }

    /** M-2 (investimento): mesmo caso, no cofrinho de investimento. */
    public function test_resgate_em_investimento_de_outra_familia_nao_vaza_saldo(): void
    {
        $inv = Investment::factory()->for($this->titular)->create();
        InvestmentContribution::factory()->for($inv)->for($this->contaA)->aporte()->create(['amount' => 873.45]);

        $estranho = User::factory()->create();
        $contaDele = Account::factory()->for($estranho)->create(['type' => 'checking', 'initial_balance' => 10]);

        $resgatar = fn () => $this->actingAs($estranho)->post(route('investimentos.resgates.store', $inv), [
            'amount' => '999.999,00', 'account_id' => $contaDele->id,
        ]);

        $naPortaDaFrente = $resgatar();
        $naPortaDaFrente->assertNotFound();
        $naPortaDaFrente->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('873,45', $naPortaDaFrente->getContent());

        $this->desligarEscopoDaFamiliaNaRota('investimento', Investment::class);

        $naLinhaDeTras = $resgatar();
        $naLinhaDeTras->assertForbidden();
        $naLinhaDeTras->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('873,45', $naLinhaDeTras->getContent());
    }

    /** M-2: o dependente da própria família continua podendo resgatar (visão compartilhada). */
    public function test_dependente_continua_podendo_resgatar_da_familia(): void
    {
        $meta = Goal::factory()->for($this->titular)->create();
        GoalContribution::factory()->for($meta)->for($this->contaA)->aporte()->create(['amount' => 200]);

        $dependente = User::factory()->create([
            'account_owner_id' => $this->titular->id, 'is_admin' => false,
        ]);

        $this->actingAs($dependente)->post(route('metas.resgates.store', $meta), [
            'amount' => '200,00', 'account_id' => $this->contaA->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $meta->fresh()->saved);
    }

    // ------------------------------------------------------------------
    // M-1 · Rentabilidade: PHP × JS alinhados e rótulo de estimativa na tela
    // ------------------------------------------------------------------

    /**
     * M-1: sem indexador, o accessor devolvia 0 enquanto a prévia em JS usava a
     * taxa digitada — o card mostrava "0,0% a.a." e o modal, "30%".
     */
    public function test_gross_rate_sem_indexador_usa_a_taxa_informada(): void
    {
        $rv = Investment::factory()->for($this->titular)->rendaVariavel()->create(['taxa' => 30]);
        $this->assertSame(30.0, $rv->grossRate, 'PHP tem de bater com o grossRate do investimentos.js');

        $semTaxa = Investment::factory()->for($this->titular)->rendaVariavel()->create();
        $this->assertSame(0.0, $semTaxa->grossRate, 'sem taxa não há o que estimar');
    }

    /** M-1: a tela precisa dizer que a rentabilidade exibida é estimativa. */
    public function test_tela_de_investimentos_rotula_a_rentabilidade_como_estimativa(): void
    {
        Investment::factory()->for($this->titular)->create(['indexador' => 'CDI', 'taxa' => 110]);

        $resposta = $this->actingAs($this->titular)->get(route('investimentos.index'));

        $resposta->assertOk();
        $resposta->assertSee('Rentab. média estimada');
        $resposta->assertSee('a.a. est.');
        $resposta->assertSee('Projeção, não rendimento realizado');
        // A seta de alta sugeria ganho realizado — saiu do card do ativo.
        $this->assertStringNotContainsString('▲', $resposta->getContent());
    }
}
