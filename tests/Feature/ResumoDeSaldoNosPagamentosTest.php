<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resumo "saldo atual − o que sai = saldo depois" nas janelas de pagamento (out/2026 — pedido
 * do Victor). O JS desenha (`sm/resumo-saldo.js`, testado no Vitest); aqui se confere que o
 * servidor entrega os números certos: o DISPONÍVEL de cada conta (já fora o que está guardado
 * em metas), cru, na `<option>`, e o valor de cada fatura no botão que abre o modal.
 */
class ResumoDeSaldoNosPagamentosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $corrente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->corrente = Account::factory()->for($this->user)->create([
            'name' => 'Corrente', 'type' => 'checking', 'bank' => 'nubank', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
        // R$ 200 guardados numa meta: o saldo do resumo é o disponível, 800.
        $meta = Goal::factory()->for($this->user)->create();
        $meta->contributions()->create(['account_id' => $this->corrente->id, 'type' => 'aporte', 'amount' => 200, 'date' => now()->toDateString()]);
    }

    public function test_pagar_fatura_conta_fixa_e_lancar_despesa_trazem_o_resumo_com_o_disponivel(): void
    {
        $cartao = Account::factory()->for($this->user)->create([
            'name' => 'Roxinho', 'type' => 'credit_card', 'bank' => 'nubank', 'credit_limit' => 3000, 'closing_day' => 28, 'due_day' => 5, 'initial_balance' => null,
        ]);
        Transaction::factory()->for($this->user)->create([
            'account_id' => $cartao->id, 'type' => 'expense', 'amount' => 350.25, 'date' => now()->toDateString(),
        ]);
        FixedBill::create([
            'user_id' => $this->user->id, 'name' => 'Aluguel', 'amount' => 900, 'due_day' => 10,
            'account_id' => $this->corrente->id, 'starts_on' => now()->startOfMonth()->toDateString(), 'active' => true,
        ]);

        $html = $this->actingAs($this->user)->get(route('faturas.index'))->assertOk()->getContent();

        // Os três modais de pagamento da tela têm o resumo — e o Lançar do shell, o quarto.
        $this->assertSame(4, substr_count($html, 'data-resumo-saldo'));
        $this->assertStringContainsString('data-rs-rotulo-valor>Esta fatura<', $html);
        $this->assertStringContainsString('data-rs-rotulo-valor>Esta conta<', $html);
        $this->assertStringContainsString('data-rs-rotulo-valor>Esta despesa<', $html);

        // "Debitar de": disponível da corrente, já fora a meta.
        $this->assertMatchesRegularExpression('/<option value="'.$this->corrente->id.'" data-saldo-valor="800.00" data-saldo-rotulo="disponível">/', $html);
        // A conta fixa diz se já venceu (obrigação): muda o aviso do resumo, como muda a regra no servidor.
        $this->assertMatchesRegularExpression('/data-fixa-pagar[^>]*data-obrigacao="[01]"/s', $html);
        // O botão de pagar a fatura leva o valor cru.
        $this->assertStringContainsString('data-amount-valor="350.25"', $html);
        // Cartão nos selects de método: o limite livre.
        $this->assertStringContainsString('data-saldo-valor="2649.75" data-saldo-rotulo="limite livre"', $html);
    }

    public function test_o_modal_lancar_traz_o_resumo_e_o_saldo_cru_de_cada_metodo(): void
    {
        $html = $this->actingAs($this->user)->get(route('transactions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-resumo-saldo', $html);
        $this->assertStringContainsString('data-saldo-valor="800.00"', $html);

        // O modal mora no shell: o select de contas acompanha o pjax, senão o "Saldo atual" ficava
        // o de quando a aba abriu, mesmo depois de lançar ou pagar algo.
        $this->assertMatchesRegularExpression('/<select class="input" id="lm-account"[^>]*data-pjax-atualizar/', $html);
        // Só o aviso é anunciado ao leitor de tela; o grupo inteiro falava a cada tecla.
        $this->assertDoesNotMatchRegularExpression('/<div class="resumo-saldo"[^>]*aria-live/', $html);
        $this->assertMatchesRegularExpression('/<p class="rs-aviso"[^>]*aria-live="polite"/', $html);
    }

    public function test_o_menos_e_o_igual_ficam_na_linha_dos_valores(): void
    {
        // Centralizados no bloco rótulo+valor, o − e o = flutuavam entre as duas linhas
        // (pedido do Victor). Alinhados pela base, com a mesma altura de linha dos valores.
        $css = file_get_contents(resource_path('css/forms.css'));

        $this->assertMatchesRegularExpression('/\.resumo-saldo \{[^}]*align-items: flex-end;/', $css);
        // E no meio dos dois números, na horizontal: colunas do tamanho do conteúdo, espaço igual.
        $this->assertMatchesRegularExpression('/\.resumo-saldo \{[^}]*justify-content: space-between;/', $css);
        $this->assertMatchesRegularExpression('/\.rs-item \{[^}]*flex: 0 1 auto;/', $css);
        preg_match('/\.rs-valor \{[^}]*line-height: (\d+px)/', $css, $valor);
        preg_match('/\.rs-op \{[^}]*line-height: (\d+px)/', $css, $operador);
        $this->assertNotEmpty($valor);
        $this->assertSame($valor[1], $operador[1] ?? null);
    }
}
