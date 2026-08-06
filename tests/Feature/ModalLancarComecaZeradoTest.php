<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Modal global de lançar: estado inicial e o saldo do método escolhido.
 *
 * O comportamento em si (zerar ao trocar de tipo, zerar ao reabrir) mora no
 * `sm/launch.js` e é JavaScript — a suíte PHP não o executa. O que dá para
 * proteger aqui é o que o SERVIDOR entrega: o padrão marcado no HTML e os dados
 * que o JS consome. Sem isso, um `checked` trocado por engano passaria despercebido.
 */
class ModalLancarComecaZeradoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_o_modal_abre_com_receita_marcada(): void
    {
        Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 1000]);

        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        // Receita é o padrão pedido pelo Victor. O `checked` vem do HTML para valer
        // também sem JS — o JS só reforça ao reabrir.
        $this->assertMatchesRegularExpression(
            '/id="lm-tt-income"[^>]*\bchecked\b/u',
            $html,
            'o modal precisa abrir com Receita marcada',
        );
        $this->assertDoesNotMatchRegularExpression('/id="lm-tt-expense"[^>]*\bchecked\b/u', $html);
    }

    public function test_o_campo_de_valor_nasce_vazio(): void
    {
        Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 1000]);

        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        // Sem `value=` no HTML: um valor pré-preenchido seria salvo por engano por
        // quem só quisesse ajustar a data.
        $this->assertMatchesRegularExpression('/id="lm-amount"(?![^>]*\bvalue=)[^>]*>/u', $html);
    }

    public function test_cada_metodo_carrega_o_proprio_saldo_para_a_tela(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 2500,
        ]);
        Account::factory()->for($this->user)->create([
            'type' => 'credit_card', 'name' => 'Nubank',
            'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);

        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        // É esta informação que evita a surpresa do 409: a pessoa vê o quanto tem
        // ANTES de digitar o valor, em vez de descobrir ao tentar salvar.
        $this->assertStringContainsString('data-saldo="R$ 2.500,00"', $html);
        $this->assertStringContainsString('data-saldo-rotulo="disponível"', $html);
        // No cartão o número que importa é o limite livre, não saldo.
        $this->assertStringContainsString('data-saldo-rotulo="limite livre"', $html);
    }

    public function test_conta_no_vermelho_vem_marcada_como_negativa(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'initial_balance' => 100, 'overdraft_limit' => 500,
        ]);
        Transaction::factory()->for($this->user)->for($conta)->expense()
            ->create(['amount' => 300, 'date' => now()->toDateString()]);

        $html = $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-negativo="1"', $html);
        $this->assertStringContainsString('data-saldo="−R$ 200,00"', $html);
    }

    public function test_o_select_de_metodo_nao_dispara_uma_query_por_conta(): void
    {
        for ($i = 0; $i < 8; $i++) {
            Account::factory()->for($this->user)->create([
                'type' => 'checking', 'initial_balance' => 100 * $i,
            ]);
        }

        // `paymentOptions` passou a ler saldo de cada conta; sem o `preloadMoney`
        // isso viraria uma leva de queries por conta dentro do map.
        DB::enableQueryLog();
        Account::paymentOptions($this->user->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(10, $queries, "8 contas não podem custar {$queries} queries");
    }
}
