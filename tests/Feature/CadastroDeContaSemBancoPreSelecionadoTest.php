<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O cadastro de método de pagamento vinha com o banco PRÉ-SELECIONADO em "Nubank"
 * (`accounts/_form.blade.php`: `old('bank', $account->bank ?? 'nubank')`). Quem não
 * mexia no select cadastrava tudo como Nubank — com a logo do Nubank no card de uma conta
 * do Itaú. (Observação da auditoria de 05/09/2026.)
 *
 * Agora nenhum banco vem escolhido no cadastro: o select abre em "Selecione o banco", o
 * `required` do navegador pede a escolha, e o servidor explica quando ela não vem. O
 * preview da logo fica escondido até haver banco, em vez de uma imagem quebrada.
 */
class CadastroDeContaSemBancoPreSelecionadoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** O `<select name="bank">` de UM formulário (a lista tem um por modal). */
    private function seletorDeBanco(TestResponse $resposta, string $uid): string
    {
        $resposta->assertOk();
        $this->assertSame(1, preg_match('/<select[^>]*id="bank-'.$uid.'"[^>]*>(.*?)<\/select>/s', $resposta->getContent(), $m));

        return $m[1];
    }

    /** O preview do banco daquele formulário (o bloco antes do nome). */
    private function previewDoBanco(TestResponse $resposta, string $uid): string
    {
        $this->assertSame(1, preg_match('/id="acct-form-'.$uid.'".*?(<div class="bank-preview".*?<\/div>)/s', $resposta->getContent(), $m));

        return $m[1];
    }

    /** O caso do relatório: a página de cadastro não escolhe banco nenhum. */
    public function test_a_pagina_de_cadastro_nao_pre_seleciona_banco(): void
    {
        $resposta = $this->actingAs($this->user)->get(route('accounts.create'));
        $select = $this->seletorDeBanco($resposta, 'novo');

        $this->assertStringContainsString('<option value="" selected>Selecione o banco</option>', $select);
        $this->assertSame(0, preg_match('/value="[a-z_]+"\s+selected/', $select), 'Nenhum banco pode vir marcado.');

        // Sem banco, o preview some — nada de imagem apontando para ".png".
        $preview = $this->previewDoBanco($resposta, 'novo');
        $this->assertStringContainsString('hidden', $preview);
        $this->assertStringNotContainsString('banks/.png', $resposta->getContent());
    }

    /** O mesmo no modal de "Novo método" da lista. */
    public function test_o_modal_de_novo_metodo_tambem_nao_pre_seleciona_banco(): void
    {
        $select = $this->seletorDeBanco($this->actingAs($this->user)->get(route('accounts.index')), 'novo');

        $this->assertStringContainsString('<option value="" selected>Selecione o banco</option>', $select);
        $this->assertSame(0, preg_match('/value="[a-z_]+"\s+selected/', $select));
    }

    /** Editar mostra o banco GRAVADO marcado, com a logo dele. */
    public function test_editar_mostra_o_banco_gravado(): void
    {
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'bank' => 'itau']);

        $resposta = $this->actingAs($this->user)->get(route('accounts.edit', $conta));
        $select = $this->seletorDeBanco($resposta, 'c'.$conta->id);

        $this->assertMatchesRegularExpression('/value="itau"\s+selected/', $select);
        $this->assertSame(0, preg_match('/value="nubank"\s+selected/', $select));
        $this->assertStringNotContainsString('<option value="" selected>', $select);
        $this->assertStringContainsString('banks/itau.png', $this->previewDoBanco($resposta, 'c'.$conta->id));
    }

    /** Conta antiga sem banco gravado também não ganha "Nubank" de presente na edição. */
    public function test_conta_sem_banco_gravado_abre_sem_banco_na_edicao(): void
    {
        $conta = Account::factory()->for($this->user)->create(['type' => 'checking', 'bank' => null]);

        $select = $this->seletorDeBanco($this->actingAs($this->user)->get(route('accounts.edit', $conta)), 'c'.$conta->id);

        $this->assertStringContainsString('<option value="" selected>Selecione o banco</option>', $select);
        $this->assertSame(0, preg_match('/value="[a-z_]+"\s+selected/', $select));
    }

    /** Enviar sem escolher: o servidor explica, e nada é criado. */
    public function test_enviar_sem_banco_explica_que_falta_escolher(): void
    {
        $this->actingAs($this->user)->from(route('accounts.create'))->post(route('accounts.store'), [
            'name' => 'Conta do salário',
            'type' => 'checking',
            'bank' => '',
            'initial_balance' => '0,00',
        ])->assertSessionHasErrors(['bank' => 'Escolha o banco.']);

        $this->assertSame(0, Account::count());
    }

    /** Com banco escolhido, cria normalmente — com o banco que a pessoa escolheu. */
    public function test_com_banco_escolhido_cria_com_ele(): void
    {
        $this->actingAs($this->user)->post(route('accounts.store'), [
            'name' => 'Conta do salário',
            'type' => 'checking',
            'bank' => 'itau',
            'initial_balance' => '0,00',
        ])->assertSessionHasNoErrors();

        $this->assertSame('itau', Account::sole()->bank);
    }
}
