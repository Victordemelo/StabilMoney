<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Atividade;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Registro de atividade — CADASTROS (métodos de pagamento, categorias, contas fixas) e
 * FAMÍLIA (dependentes e perfil), out/2026.
 *
 * O que importa além da frase: a edição registra só os campos de uma LISTA DE PERMISSÃO
 * (o `position` da categoria ou o `remember_token` do usuário mudam sozinhos e não viram
 * ruído), e dado pessoal que o titular não precisa ler (telefone, nascimento, sexo) entra
 * como "alterado", sem o valor.
 */
class AtividadeDeCadastrosEFamiliaTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->titular = User::factory()->create(['name' => 'Victor', 'is_admin' => true]);
    }

    public function test_metodo_de_pagamento_criado_e_editado(): void
    {
        $this->actingAs($this->titular)->post(route('accounts.store'), [
            'name' => 'Conta Corrente', 'type' => 'checking', 'bank' => 'nubank',
            'initial_balance' => '1.000,00', 'overdraft_limit' => '0,00',
        ])->assertSessionHasNoErrors();

        $conta = Account::sole();
        $this->assertSame(
            'Victor cadastrou o método de pagamento “Conta Corrente (Nubank)”, com saldo inicial de R$ 1.000,00',
            Atividade::where('acao', 'conta.criada')->sole()->descricao,
        );

        $this->actingAs($this->titular)->put(route('accounts.update', $conta), [
            'name' => 'Conta Corrente', 'type' => 'checking', 'bank' => 'nubank',
            'initial_balance' => '1.000,00', 'overdraft_limit' => '500,00',
        ])->assertSessionHasNoErrors();

        $edicao = Atividade::where('acao', 'conta.editada')->sole();
        $this->assertSame('Victor editou o método de pagamento “Conta Corrente (Nubank)”', $edicao->descricao);
        $this->assertSame(
            [['campo' => 'overdraft_limit', 'rotulo' => 'Cheque especial', 'antes' => 'R$ 0,00', 'depois' => 'R$ 500,00']],
            $edicao->mudancas,
        );
    }

    public function test_categoria_movida_de_tipo_e_reordenada_mas_a_posicao_sozinha_nao_vira_ruido(): void
    {
        $categoria = Category::factory()->for($this->titular)->create(['name' => 'Freela', 'icon' => '💼', 'type' => 'expense']);
        Atividade::query()->delete();

        $this->actingAs($this->titular)->patchJson(route('categories.update', $categoria), [
            'name' => 'Freela', 'icon' => '💼', 'type' => 'income', 'color' => $categoria->color,
        ])->assertSuccessful();

        $this->assertSame(
            'Victor moveu a categoria “💼 Freela” para Receitas',
            Atividade::where('acao', 'categoria.editada')->sole()->descricao,
        );

        // `position` muda pelo `update()` em massa da reordenação: uma linha só, e nenhuma
        // "editou a categoria" por causa da posição.
        $this->actingAs($this->titular)->patchJson(route('categories.ordenar'), ['ids' => [$categoria->id]])->assertOk();
        $this->assertSame(1, Atividade::where('acao', 'categoria.reordenada')->count());
        $this->assertSame(1, Atividade::where('acao', 'categoria.editada')->count());
    }

    public function test_conta_fixa_criada_e_desativada(): void
    {
        $conta = Account::factory()->for($this->titular)->create(['type' => 'checking', 'initial_balance' => 0]);
        $fixa = FixedBill::factory()->for($this->titular)->create([
            'name' => 'Aluguel', 'amount' => 1800, 'due_day' => 10, 'account_id' => $conta->id,
            'starts_on' => CarbonImmutable::today()->subMonths(2)->startOfMonth()->toDateString(),
        ]);

        $this->assertSame(
            'Sistema cadastrou a conta fixa “Aluguel” de R$ 1.800,00, com vencimento todo dia 10',
            Atividade::where('acao', 'conta_fixa.criada')->sole()->descricao,
            'Sem ninguém logado (factory, console), o autor é o "Sistema".',
        );

        $this->actingAs($this->titular);
        $fixa->update(['active' => false]);

        $this->assertSame(
            'Victor desativou a conta fixa “Aluguel”',
            Atividade::where('acao', 'conta_fixa.editada')->sole()->descricao,
        );
    }

    public function test_adicionar_editar_trocar_a_senha_e_remover_um_dependente(): void
    {
        $this->actingAs($this->titular)->post(route('dependentes.store'), [
            'name' => 'Maria', 'email' => 'maria@familia.test', 'password' => 'senha-forte-123',
            'relationship' => 'conjuge',
        ])->assertSessionHasNoErrors();

        $maria = User::where('email', 'maria@familia.test')->sole();
        $adicionado = Atividade::where('acao', 'dependente.adicionado')->sole();
        $this->assertSame('Victor adicionou Maria à família como cônjuge', $adicionado->descricao);
        $this->assertSame('familia', $adicionado->grupo);
        $this->assertSame($this->titular->id, $adicionado->owner_id);

        $this->actingAs($this->titular)->patch(route('dependentes.update', $maria), [
            'name' => 'Maria Clara', 'email' => 'maria@familia.test', 'relationship' => 'conjuge',
            'password' => 'outra-senha-forte-456',
        ])->assertSessionHasNoErrors();

        $editado = Atividade::where('acao', 'dependente.editado')->sole();
        $this->assertSame('Victor alterou o cadastro de Maria Clara', $editado->descricao);
        $this->assertSame([['campo' => 'name', 'rotulo' => 'Nome', 'antes' => 'Maria', 'depois' => 'Maria Clara']], $editado->mudancas);
        $this->assertSame(
            'Victor trocou a senha de Maria Clara (os aparelhos dele(a) foram desconectados)',
            Atividade::where('acao', 'dependente.senha_trocada')->sole()->descricao,
        );

        $this->actingAs($this->titular)->delete(route('dependentes.destroy', $maria), ['password' => 'password'])->assertSessionHasNoErrors();

        $this->assertSame(
            'Victor removeu Maria Clara da família',
            Atividade::where('acao', 'dependente.removido')->sole()->descricao,
        );
    }

    public function test_dependente_que_sai_deixa_as_acoes_mas_leva_ip_e_aparelho(): void
    {
        $maria = User::factory()->create(['name' => 'Maria', 'account_owner_id' => $this->titular->id, 'is_admin' => false]);
        $conta = Account::factory()->for($this->titular)->create(['type' => 'checking', 'initial_balance' => 1000]);

        $this->actingAs($maria)->post(route('transactions.store'), [
            'type' => 'expense', 'amount' => '50,00', 'description' => 'Padaria',
            'account_id' => $conta->id, 'date' => CarbonImmutable::today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $lancamento = Atividade::where('acao', 'transacao.criada')->sole();
        $this->assertNotNull($lancamento->ip);

        $this->actingAs($this->titular)->delete(route('dependentes.destroy', $maria), ['password' => 'password'])->assertSessionHasNoErrors();

        $lancamento->refresh();
        $this->assertSame('Maria lançou a despesa “Padaria” de R$ 50,00 em '.$conta->rotulo, $lancamento->descricao);
        $this->assertNull($lancamento->ip, 'O IP de quem saiu da família não fica para trás.');
        $this->assertNull($lancamento->aparelho);
    }

    public function test_perfil_registra_que_o_telefone_mudou_sem_guardar_o_numero(): void
    {
        $this->actingAs($this->titular)->patch(route('profile.update'), [
            'name' => 'Victor', 'email' => $this->titular->email, 'phone' => '(11) 98888-7777',
        ])->assertSessionHasNoErrors();

        $linha = Atividade::where('acao', 'perfil.editado')->sole();
        $this->assertSame('Victor atualizou o próprio perfil', $linha->descricao);
        $this->assertSame([['campo' => 'phone', 'rotulo' => 'Telefone', 'antes' => null, 'depois' => null]], $linha->mudancas);
        $this->assertStringNotContainsString('98888', json_encode($linha->getAttributes()));
    }
}
