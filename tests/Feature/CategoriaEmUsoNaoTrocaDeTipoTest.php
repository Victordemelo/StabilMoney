<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DesligaEscopoDaFamiliaNaRota;
use Tests\TestCase;

/**
 * Categoria com lançamentos (ou usada por conta fixa) não pode trocar de tipo.
 *
 * O defeito: arrastar a categoria entre as colunas Receitas ↔ Despesas faz PATCH em
 * `categories.update` trocando o `type`, e isso era aceito mesmo com lançamentos nela. Eles
 * ficavam presos a uma categoria do tipo oposto: editar a despesa sem mudar nada passava a
 * dar 422 ("a categoria precisa casar com o tipo"), o donut do dashboard mostrava categoria
 * de receita entre as despesas, e pagar uma conta fixa gravava despesa numa categoria de
 * receita. A mesma rota atende o arraste (PATCH JSON), o modal (JSON) e a página cheia
 * (formulário).
 *
 * 🚨 A correção RECUSA a troca; nunca "conserta" mudando o tipo dos lançamentos — isso
 * trocaria o sinal do dinheiro e reescreveria saldos antigos. Os testes conferem que os
 * lançamentos saem intactos.
 */
class CategoriaEmUsoNaoTrocaDeTipoTest extends TestCase
{
    use DesligaEscopoDaFamiliaNaRota, RefreshDatabase;

    private User $user;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'initial_balance' => 5000,
        ]);
    }

    private function despesa(Category $categoria, float $valor = 100): Transaction
    {
        return Transaction::create([
            'user_id' => $this->user->id,
            'made_by_user_id' => $this->user->id,
            'account_id' => $this->conta->id,
            'category_id' => $categoria->id,
            'type' => 'expense',
            'amount' => $valor,
            'description' => 'Compra do mês',
            'date' => now()->toDateString(),
        ]);
    }

    /** O corpo que o arraste manda (categories.js::salvarTipo). */
    private function arrastar(Category $categoria, string $para)
    {
        return $this->actingAs($this->user)->patchJson(route('categories.update', $categoria), [
            'name' => $categoria->name,
            'type' => $para,
            'color' => $categoria->color,
            'icon' => $categoria->icon,
        ]);
    }

    // ══════════════════════════════════════════════════ com lançamentos

    public function test_arrastar_categoria_com_lancamentos_para_a_outra_coluna_e_recusado(): void
    {
        $mercado = Category::factory()->expense()->for($this->user)->create(['name' => 'Mercado']);
        $primeira = $this->despesa($mercado, 120);
        $segunda = $this->despesa($mercado, 80);

        $resposta = $this->arrastar($mercado, 'income')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        // A mensagem é o que o arraste mostra no aviso: diz o porquê e a saída.
        $mensagem = $resposta->json('errors.type.0');
        $this->assertStringContainsString('"Mercado" não pode virar receita', $mensagem);
        $this->assertStringContainsString('tem 2 lançamentos de despesa', $mensagem);
        $this->assertStringContainsString('crie uma categoria nova em Receitas', $mensagem);

        $this->assertSame('expense', $mercado->fresh()->type);

        // Lançamentos intactos — nem tipo, nem categoria, nem valor.
        foreach ([[$primeira, 120.0], [$segunda, 80.0]] as [$lancamento, $valor]) {
            $lancamento->refresh();
            $this->assertSame('expense', $lancamento->type);
            $this->assertSame($mercado->id, $lancamento->category_id);
            $this->assertSame($valor, (float) $lancamento->amount);
        }
    }

    /** A página cheia (sem JS) recebe a mesma recusa como erro do campo "tipo". */
    public function test_pela_pagina_cheia_a_recusa_volta_como_erro_do_campo_tipo(): void
    {
        $salario = Category::factory()->income()->for($this->user)->create(['name' => 'Salário']);
        Transaction::create([
            'user_id' => $this->user->id,
            'account_id' => $this->conta->id,
            'category_id' => $salario->id,
            'type' => 'income',
            'amount' => 3000,
            'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->user)
            ->from(route('categories.edit', $salario))
            ->put(route('categories.update', $salario), [
                'name' => 'Salário',
                'type' => 'expense',
            ])
            ->assertRedirect(route('categories.edit', $salario))
            ->assertSessionHasErrors([
                'type' => '"Salário" não pode virar despesa: tem 1 lançamento de receita, e ele ficaria numa categoria do tipo errado. '
                    .'Para lançar despesas, crie uma categoria nova em Despesas.',
            ]);

        $this->assertSame('income', $salario->fresh()->type);

        // E a tela mostra a mensagem, no formulário.
        $this->actingAs($this->user)->get(route('categories.edit', $salario))
            ->assertOk()
            ->assertSee('não pode virar despesa');
    }

    /**
     * A consequência que a trava evita: com o tipo trocado por baixo, a despesa deixava de
     * poder ser editada — nem salvar sem mudar nada passava.
     */
    public function test_depois_da_recusa_a_despesa_continua_editavel(): void
    {
        $mercado = Category::factory()->expense()->for($this->user)->create(['name' => 'Mercado']);
        $despesa = $this->despesa($mercado, 150);

        // Sem conferir a resposta de propósito: o que se mede aqui é o EFEITO do arraste
        // na despesa, aceito ou não. (A recusa em si é conferida nos testes acima.)
        $this->arrastar($mercado, 'income');

        $this->actingAs($this->user)->put(route('transactions.update', $despesa), [
            'type' => 'expense',
            'amount' => '150,00',
            'account_id' => $this->conta->id,
            'category_id' => $mercado->id,
            'date' => $despesa->date->toDateString(),
            'description' => 'Compra do mês',
        ])->assertSessionHasNoErrors();
    }

    // ══════════════════════════════════════════════════ conta fixa

    /** Conta fixa é sempre despesa: a categoria dela não vira receita, mesmo sem pagamento ainda. */
    public function test_categoria_usada_por_conta_fixa_nao_vira_receita(): void
    {
        $moradia = Category::factory()->expense()->for($this->user)->create(['name' => 'Moradia']);
        FixedBill::factory()->for($this->user)->create(['name' => 'Aluguel', 'category_id' => $moradia->id]);

        $resposta = $this->arrastar($moradia, 'income')->assertStatus(422);

        $this->assertSame(
            '"Moradia" não pode virar receita: é a categoria da conta fixa "Aluguel", e conta fixa é sempre despesa. '
                .'Para lançar receitas, crie uma categoria nova em Receitas.',
            $resposta->json('errors.type.0'),
        );
        $this->assertSame('expense', $moradia->fresh()->type);
    }

    // ══════════════════════════════════════════════════ o que continua valendo

    public function test_categoria_sem_lancamentos_continua_trocando_de_tipo(): void
    {
        $freelas = Category::factory()->expense()->for($this->user)->create(['name' => 'Freelas']);

        $this->arrastar($freelas, 'income')->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame('income', $freelas->fresh()->type);
    }

    /** Renomear e repintar categoria em uso segue livre — a trava é só do tipo. */
    public function test_categoria_com_lancamentos_continua_renomeavel(): void
    {
        $mercado = Category::factory()->expense()->for($this->user)->create(['name' => 'Mercado']);
        $this->despesa($mercado);

        $this->actingAs($this->user)->putJson(route('categories.update', $mercado), [
            'name' => 'Supermercado',
            'type' => 'expense',
            'color' => '#18B6BE',
            'icon' => '🛒',
        ])->assertOk();

        $this->assertSame('Supermercado', $mercado->fresh()->name);
    }

    /** Categoria fixa segue recusada com a mensagem dela, com ou sem lançamentos. */
    public function test_categoria_fixa_continua_recusada_com_a_mensagem_dela(): void
    {
        $saude = Category::factory()->expense()->for($this->user)->create(['name' => 'Saúde', 'is_locked' => true]);
        $this->despesa($saude);

        $resposta = $this->arrastar($saude, 'income')->assertStatus(422);

        $this->assertSame(
            'Esta é uma categoria fixa do sistema e não pode mudar de tipo.',
            $resposta->json('errors.type.0'),
        );
        $this->assertSame('expense', $saude->fresh()->type);
    }

    /**
     * A trava confere o INVARIANTE (cada lançamento casa com o tipo da categoria), não "tem
     * lançamento". Uma categoria que ficou do tipo errado por um arraste de antes desta
     * correção — receita com despesas dentro — pode ser arrastada de volta: é o que a conserta.
     */
    public function test_categoria_que_ficou_do_tipo_errado_pode_voltar_ao_tipo_dos_lancamentos(): void
    {
        $mercado = Category::factory()->income()->for($this->user)->create(['name' => 'Mercado']);
        $despesa = $this->despesa($mercado);

        $this->arrastar($mercado, 'expense')->assertOk();

        $this->assertSame('expense', $mercado->fresh()->type);
        $this->assertSame('expense', $despesa->fresh()->type);
    }

    /**
     * A recusa diz o que há dentro da categoria — só depois de saber que ela é da família.
     *
     * Desde 23/09/2026 a categoria alheia recebe o 404 de um id que não existe, no binding,
     * sem chegar ao controller. A ordem do controller (policy ANTES da recusa) ficou como
     * linha de trás — e continua provada aqui, com o escopo do binding desligado: 403, sem
     * a contagem de lançamentos.
     */
    public function test_categoria_de_outra_familia_nao_recebe_a_recusa_com_o_que_ha_dentro(): void
    {
        $outro = User::factory()->create();
        $alheia = Category::factory()->expense()->for($outro)->create(['name' => 'Alheia']);
        Transaction::factory()->for($outro)->expense()->create(['category_id' => $alheia->id]);

        $this->arrastar($alheia, 'income')->assertNotFound()->assertJsonMissingValidationErrors();

        $this->desligarEscopoDaFamiliaNaRota('category', Category::class);
        $this->arrastar($alheia, 'income')->assertForbidden()->assertJsonMissingValidationErrors();

        $this->assertSame('expense', $alheia->fresh()->type);
    }
}
