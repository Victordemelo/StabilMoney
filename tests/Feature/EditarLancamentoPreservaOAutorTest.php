<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A-11 da auditoria de 05/09/2026: editar um lançamento trocava o AUTOR em silêncio.
 *
 * Dois caminhos:
 *
 * - `TransactionController::update` fazia `made_by_user_id ?? $request->user()->id`:
 *   sem o campo no envio (família de uma pessoa só, onde o seletor nem aparece; um
 *   replay), a compra do dependente passava a ser do titular que só corrigiu a descrição.
 * - No formulário, um autor gravado NULO (dependente excluído, lançamento antigo) não
 *   casava com opção nenhuma do "Quem fez a compra". O navegador marcava a PRIMEIRA
 *   pessoa da lista e salvar gravava essa pessoa como autora.
 *
 * Agora a ausência do campo preserva o autor gravado, e o formulário mostra "Não
 * informado" em vez de escolher alguém pela pessoa.
 */
class EditarLancamentoPreservaOAutorTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private User $filha;

    private Account $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['name' => 'Ana Titular', 'is_admin' => true]);
        $this->filha = User::factory()->create([
            'name' => 'Bia Dependente', 'is_admin' => false, 'account_owner_id' => $this->titular->id,
        ]);
        $this->conta = Account::factory()->for($this->titular)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 1000,
        ]);
    }

    private function despesa(?int $autor): Transaction
    {
        return Transaction::factory()->for($this->titular)->for($this->conta)->expense()->create([
            'amount' => 80,
            'date' => now()->toDateString(),
            'description' => 'Farmácia',
            'made_by_user_id' => $autor,
        ]);
    }

    private function editar(Transaction $t, array $extra = []): TestResponse
    {
        return $this->actingAs($this->titular)
            ->from(route('transactions.edit', $t))
            ->put(route('transactions.update', $t), $extra + [
                'type' => $t->type,
                'amount' => number_format((float) $t->amount, 2, ',', '.'),
                'account_id' => $t->account_id,
                'date' => $t->date->toDateString(),
                'description' => 'Farmácia (remédio da Bia)',
            ]);
    }

    /**
     * Só o "Quem fez a compra" DESTE formulário: o modal Lançar (no shell de toda página)
     * tem o seletor dele, marcado em quem está logado.
     */
    private function seletorDeAutor(TestResponse $resposta): string
    {
        $resposta->assertOk();
        $this->assertSame(1, preg_match('/<select[^>]*id="made_by_user_id"[^>]*>(.*?)<\/select>/s', $resposta->getContent(), $m));

        return $m[1];
    }

    /** A opção desta pessoa vem marcada? (o `selected` pode estar na linha de baixo) */
    private function marcada(string $select, int $id): bool
    {
        return preg_match('/value="'.$id.'"\s+selected/', $select) === 1;
    }

    /** O caso do relatório: o titular corrige a descrição e a compra da filha vira dele. */
    public function test_editar_sem_mandar_o_autor_preserva_quem_lancou(): void
    {
        $t = $this->despesa($this->filha->id);

        $this->editar($t)->assertSessionHasNoErrors();

        $this->assertSame('Farmácia (remédio da Bia)', $t->fresh()->description);
        $this->assertSame($this->filha->id, $t->fresh()->made_by_user_id, 'O autor mudou para quem editou.');
    }

    /** Autor nulo (dependente excluído, lançamento antigo) continua nulo — não vira de quem editou. */
    public function test_autor_nulo_continua_nulo_ao_editar(): void
    {
        $t = $this->despesa(null);

        $this->editar($t)->assertSessionHasNoErrors();

        $this->assertNull($t->fresh()->made_by_user_id);
    }

    /** O "Não informado" do formulário chega vazio: também preserva o gravado. */
    public function test_salvar_com_nao_informado_mantem_o_autor_nulo(): void
    {
        $t = $this->despesa(null);

        $this->editar($t, ['made_by_user_id' => ''])->assertSessionHasNoErrors();

        $this->assertNull($t->fresh()->made_by_user_id);
    }

    /** Escolher alguém de propósito continua funcionando. */
    public function test_trocar_o_autor_de_proposito_continua_valendo(): void
    {
        $t = $this->despesa(null);

        $this->editar($t, ['made_by_user_id' => $this->filha->id])->assertSessionHasNoErrors();

        $this->assertSame($this->filha->id, $t->fresh()->made_by_user_id);
    }

    /** Autor nulo: o formulário mostra "Não informado" marcado, e NINGUÉM da lista. */
    public function test_o_formulario_mostra_nao_informado_quando_o_autor_e_nulo(): void
    {
        $t = $this->despesa(null);

        $select = $this->seletorDeAutor($this->actingAs($this->titular)->get(route('transactions.edit', $t)));

        $this->assertStringContainsString('<option value="" selected>Não informado</option>', $select);
        $this->assertFalse($this->marcada($select, $this->titular->id));
        $this->assertFalse($this->marcada($select, $this->filha->id));
    }

    /** Autor conhecido: ele vem marcado e o "Não informado" nem aparece. */
    public function test_o_formulario_marca_o_autor_gravado(): void
    {
        $t = $this->despesa($this->filha->id);

        $select = $this->seletorDeAutor($this->actingAs($this->titular)->get(route('transactions.edit', $t)));

        $this->assertTrue($this->marcada($select, $this->filha->id));
        $this->assertFalse($this->marcada($select, $this->titular->id));
        $this->assertStringNotContainsString('Não informado', $select);
    }

    /** Criar continua como sempre: quem está logado vem marcado, sem "Não informado". */
    public function test_o_formulario_de_criacao_continua_marcando_quem_esta_logado(): void
    {
        $select = $this->seletorDeAutor($this->actingAs($this->filha)->get(route('transactions.create')));

        $this->assertTrue($this->marcada($select, $this->filha->id));
        $this->assertStringNotContainsString('Não informado', $select);
    }

    /** Transferência: editar a data sem o autor preserva o autor nas DUAS pontas. */
    public function test_editar_a_transferencia_sem_o_autor_preserva_nas_duas_pontas(): void
    {
        $poupanca = Account::factory()->for($this->titular)->create([
            'type' => 'savings', 'name' => 'Poupança', 'initial_balance' => 0,
        ]);

        $this->actingAs($this->filha)->post(route('transactions.transfer'), [
            'type' => 'transfer',
            'amount' => '100,00',
            'account_id' => $this->conta->id,
            'to_account_id' => $poupanca->id,
            'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $saida = Transaction::where('account_id', $this->conta->id)->whereNotNull('transfer_group_id')->sole();
        $this->assertSame($this->filha->id, $saida->made_by_user_id);

        $this->editar($saida, ['date' => now()->subDay()->toDateString(), 'description' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->filha->id, $saida->fresh()->made_by_user_id);
        $this->assertSame($this->filha->id, $saida->fresh()->contrapartida()->made_by_user_id);
    }
}
