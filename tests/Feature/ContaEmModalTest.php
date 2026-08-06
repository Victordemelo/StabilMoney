<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cadastrar e editar método de pagamento SEM sair da tela.
 *
 * Antes, "Nova conta" e "Editar" navegavam para uma página cheia: o usuário perdia
 * a lista de vista para mexer num cadastro de meia dúzia de campos. Agora abre um
 * modal na própria `/accounts` e o envio é por AJAX.
 *
 * O que este teste protege, além do óbvio:
 *
 * 1. **O formulário é UM só.** O modal inclui o mesmo `accounts/_form` da página
 *    cheia. Este é o formulário mais complexo do app — 5 tipos, campos condicionais
 *    por tipo, preview do banco, tipo travado — e mantê-lo em dois lugares garantiria
 *    que um dos dois ficasse para trás na próxima mudança. Os testes de tela abaixo
 *    conferem que os cinco tipos e os cinco grupos condicionais chegaram ao modal.
 *
 * 2. **O fallback sem JS continua inteiro.** Todo gatilho é um `<a href>` de verdade
 *    para `accounts.create`/`accounts.edit`; o modal só entra na frente quando há JS.
 *    As páginas cheias seguem respondendo 200.
 *
 * 3. **As regras de dinheiro não afrouxaram por causa do canal novo.** Validação,
 *    posse e a trava de classe do tipo valem igual em JSON — o modal não é uma porta
 *    dos fundos.
 */
class ContaEmModalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    // ================= A tela entrega o modal (e o fallback) =================

    public function test_a_lista_traz_o_modal_de_novo_metodo_com_href_de_fallback(): void
    {
        $resposta = $this->actingAs($this->user)->get(route('accounts.index'))->assertOk();

        // O gatilho é um link de verdade: sem JS ele navega para a página cheia.
        $resposta->assertSee('data-acct-open="novo"', false);
        $resposta->assertSee('href="'.route('accounts.create').'"', false);

        // E o modal existe na página, com o formulário dentro.
        $resposta->assertSee('id="acctModal-novo"', false);
        $resposta->assertSee('id="acct-form-novo"', false);
        $resposta->assertSee('action="'.route('accounts.store').'"', false);
    }

    public function test_o_modal_de_novo_metodo_existe_mesmo_sem_nenhuma_conta(): void
    {
        // Estado vazio: é justamente quando o botão mais importa.
        $this->assertSame(0, Account::count());

        $this->actingAs($this->user)->get(route('accounts.index'))
            ->assertOk()
            ->assertSee('Criar primeira conta')
            ->assertSee('id="acctModal-novo"', false);
    }

    public function test_cada_card_tem_o_proprio_modal_de_edicao_ja_preenchido(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking',
            'name' => 'Corrente Nubank',
            'bank' => 'nubank',
            'initial_balance' => 1500,
            'overdraft_limit' => 800,
        ]);

        $resposta = $this->actingAs($this->user)->get(route('accounts.index'))->assertOk();

        $resposta->assertSee('data-acct-open="'.$conta->id.'"', false);
        $resposta->assertSee('href="'.route('accounts.edit', $conta).'"', false);
        $resposta->assertSee('id="acctModal-'.$conta->id.'"', false);

        // Preenchido: nome, cheque especial e o método (PUT por spoofing).
        $resposta->assertSee('id="name-c'.$conta->id.'"', false);
        $resposta->assertSee('value="Corrente Nubank"', false);
        $resposta->assertSee('value="800,00"', false);
        $resposta->assertSee('name="_method" value="PUT"', false);
    }

    public function test_o_modal_preserva_os_cinco_tipos_e_os_campos_condicionais(): void
    {
        Account::factory()->for($this->user)->create(['type' => 'checking', 'initial_balance' => 0]);

        $resposta = $this->actingAs($this->user)->get(route('accounts.index'))->assertOk();

        // Os cinco tipos.
        foreach (['checking', 'savings', 'debit_card', 'credit_card', 'pix'] as $tipo) {
            $resposta->assertSee('value="'.$tipo.'"', false);
        }

        // Os cinco grupos que o JS mostra/esconde conforme o tipo — se um sumir no
        // modal, aquele tipo vira um formulário sem os campos dele.
        foreach ([
            'data-fields-account',    // saldo inicial (corrente/poupança)
            'data-fields-overdraft',  // cheque especial (só corrente)
            'data-fields-credit',     // limite + fechamento/vencimento
            'data-fields-debit',      // vínculo corrente e/ou poupança
            'data-fields-pix',        // UM select só: a chave vive numa conta
        ] as $grupo) {
            $resposta->assertSee($grupo, false);
        }

        // O preview do banco e o select do Pix (um só, não dois).
        $resposta->assertSee('data-bank-preview', false);
        $resposta->assertSee('Conta da chave Pix');
    }

    public function test_o_tipo_fica_travado_no_modal_de_conta_que_ja_tem_dinheiro(): void
    {
        $comDinheiro = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Com saldo', 'initial_balance' => 1000,
        ]);
        $vazia = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Zerada', 'initial_balance' => 0,
        ]);

        $resposta = $this->actingAs($this->user)->get(route('accounts.index'))->assertOk();

        // Travada: o select vai desabilitado (e desabilitado não envia valor, por isso
        // muda de nome) e o tipo verdadeiro viaja num hidden.
        $resposta->assertSee('id="type-c'.$comDinheiro->id.'" name="_type_travado"', false);
        $resposta->assertSee('<input type="hidden" name="type" value="checking">', false);

        // Sem dinheiro: o select é o próprio campo `type`.
        $resposta->assertSee('id="type-c'.$vazia->id.'" name="type"', false);
        // E no cadastro novo nunca há trava.
        $resposta->assertSee('id="type-novo" name="type"', false);
    }

    // ================= Criar por AJAX =================

    public function test_cria_conta_corrente_com_cheque_especial_por_ajax(): void
    {
        $this->actingAs($this->user)->postJson(route('accounts.store'), [
            'name' => 'Corrente do dia a dia',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '1.500,00',   // vírgula pt-BR, como o campo envia
            'overdraft_limit' => '800,00',
        ])->assertCreated()->assertJson(['ok' => true]);

        $conta = Account::where('name', 'Corrente do dia a dia')->firstOrFail();

        $this->assertSame($this->user->id, $conta->user_id);
        $this->assertSame('checking', $conta->type);
        $this->assertSame('1500.00', (string) $conta->initial_balance);
        $this->assertSame('800.00', (string) $conta->overdraft_limit);
        $this->assertNull($conta->credit_limit, 'conta não é cartão: sem limite de crédito');
    }

    public function test_cria_cartao_de_credito_com_limite_e_dias_por_ajax(): void
    {
        $this->actingAs($this->user)->postJson(route('accounts.store'), [
            'name' => 'Cartão Roxo',
            'type' => 'credit_card',
            'bank' => 'nubank',
            'credit_limit' => '5.000,00',
            'closing_day' => 2,
            'due_day' => 9,
        ])->assertCreated();

        $cartao = Account::where('name', 'Cartão Roxo')->firstOrFail();

        $this->assertSame('5000.00', (string) $cartao->credit_limit);
        $this->assertSame(2, $cartao->closing_day);
        $this->assertSame(9, $cartao->due_day);
        // Cartão não tem caixa: o `prepareForValidation` zera o que não é dele.
        $this->assertNull($cartao->initial_balance);
        $this->assertSame(0.0, (float) $cartao->overdraft_limit);
    }

    public function test_cria_pix_apontando_para_a_conta_corrente_por_ajax(): void
    {
        $corrente = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente', 'initial_balance' => 1000,
        ]);

        $this->actingAs($this->user)->postJson(route('accounts.store'), [
            'name' => 'Pix Nubank',
            'type' => 'pix',
            'bank' => 'nubank',
            'pix_account_id' => $corrente->id,   // o modal manda UM select só
        ])->assertCreated();

        $pix = Account::where('type', 'pix')->firstOrFail();

        // O servidor devolve o id para a coluna do tipo certo.
        $this->assertSame($corrente->id, $pix->checking_account_id);
        $this->assertNull($pix->savings_account_id);
    }

    // ================= Validação: o canal novo não afrouxa nada =================

    public function test_cartao_de_credito_sem_limite_devolve_422_e_nada_e_criado(): void
    {
        $this->actingAs($this->user)->postJson(route('accounts.store'), [
            'name' => 'Cartão sem limite',
            'type' => 'credit_card',
            'bank' => 'itau',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['credit_limit', 'closing_day', 'due_day']);

        $this->assertDatabaseMissing('accounts', ['name' => 'Cartão sem limite']);
        $this->assertSame(0, Account::count());
    }

    public function test_pix_sem_conta_vinculada_devolve_422(): void
    {
        $this->actingAs($this->user)->postJson(route('accounts.store'), [
            'name' => 'Pix solto',
            'type' => 'pix',
            'bank' => 'nubank',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('accounts', ['name' => 'Pix solto']);
    }

    // ================= Editar por AJAX =================

    public function test_edita_a_conta_por_ajax(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Nome Antigo', 'bank' => 'nubank',
            'initial_balance' => 200, 'overdraft_limit' => 0,
        ]);

        $this->actingAs($this->user)->putJson(route('accounts.update', $conta), [
            'name' => 'Nome Novo',
            'type' => 'checking',
            'bank' => 'itau',
            'initial_balance' => '300,00',
            'overdraft_limit' => '1.000,00',
        ])->assertOk()->assertJson(['ok' => true]);

        $conta->refresh();
        $this->assertSame('Nome Novo', $conta->name);
        $this->assertSame('itau', $conta->bank);
        $this->assertSame('300.00', (string) $conta->initial_balance);
        $this->assertSame('1000.00', (string) $conta->overdraft_limit);
    }

    public function test_conta_de_outra_familia_nao_pode_ser_editada_pelo_modal(): void
    {
        $estranho = User::factory()->create();
        $alheia = Account::factory()->for($estranho)->create([
            'type' => 'checking', 'name' => 'Conta do Vizinho', 'initial_balance' => 0,
        ]);

        // Payload perfeitamente válido: quem barra aqui é a Policy, não a validação.
        $this->actingAs($this->user)->putJson(route('accounts.update', $alheia), [
            'name' => 'Tomada',
            'type' => 'checking',
            'bank' => 'nubank',
            'initial_balance' => '0,00',
        ])->assertForbidden();

        $this->assertSame('Conta do Vizinho', $alheia->fresh()->name);

        // E a conta alheia também não aparece na lista de quem não é da família.
        $this->actingAs($this->user)->get(route('accounts.index'))
            ->assertOk()
            ->assertDontSee('Conta do Vizinho')
            ->assertDontSee('id="acctModal-'.$alheia->id.'"', false);
    }

    public function test_conta_com_historico_nao_muda_de_classe_nem_por_ajax(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'checking', 'name' => 'Corrente com vida', 'initial_balance' => 1000,
        ]);
        Transaction::factory()->for($this->user)->for($conta)->create();

        // Vira cartão de crédito com TODOS os campos de cartão preenchidos: o único
        // erro possível é a trava de classe. Trocar a fórmula do dinheiro numa conta
        // que já tem histórico faria o saldo sumir (ou contar duas vezes).
        $this->actingAs($this->user)->putJson(route('accounts.update', $conta), [
            'name' => 'Corrente com vida',
            'type' => 'credit_card',
            'bank' => 'nubank',
            'credit_limit' => '5.000,00',
            'closing_day' => 2,
            'due_day' => 9,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->assertSame('checking', $conta->fresh()->type);
        $this->assertSame('1000.00', (string) $conta->fresh()->initial_balance);
    }

    // ================= Fallback: as páginas cheias seguem de pé =================

    public function test_a_pagina_cheia_de_criacao_continua_respondendo(): void
    {
        $this->actingAs($this->user)->get(route('accounts.create'))
            ->assertOk()
            ->assertSee('id="acct-form-novo"', false)
            ->assertSee('Conta da chave Pix')
            ->assertSee('data-fields-credit', false);
    }

    public function test_a_pagina_cheia_de_edicao_continua_respondendo(): void
    {
        $conta = Account::factory()->for($this->user)->create([
            'type' => 'credit_card', 'name' => 'Cartão', 'initial_balance' => null,
            'credit_limit' => 5000, 'closing_day' => 10, 'due_day' => 20,
        ]);

        $this->actingAs($this->user)->get(route('accounts.edit', $conta))
            ->assertOk()
            ->assertSee('id="acct-form-c'.$conta->id.'"', false)
            ->assertSee('value="5.000,00"', false);
    }

    public function test_o_envio_sem_ajax_continua_redirecionando(): void
    {
        // Sem `Accept: application/json` nada muda: redirect + flash, como sempre.
        $this->actingAs($this->user)->post(route('accounts.store'), [
            'name' => 'Pela página cheia',
            'type' => 'savings',
            'bank' => 'caixa',
            'initial_balance' => '10,00',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('accounts.index'));

        $this->assertDatabaseHas('accounts', ['name' => 'Pela página cheia', 'type' => 'savings']);
    }

    // ================= Um modal por conta não pode virar N+1 =================

    public function test_os_modais_nao_acrescentam_query_por_conta(): void
    {
        // O modal de edição precisa saber se o tipo está travado — e a resposta vem
        // do `hasMoneyHistory()`, que sozinho custa 3 consultas POR CONTA. Por isso o
        // controller resolve tudo com `withExists` na mesma query da listagem.
        $poucas = User::factory()->create();
        Account::factory()->count(2)->for($poucas)->create(['type' => 'checking', 'initial_balance' => 1000]);

        $muitas = User::factory()->create();
        Account::factory()->count(12)->for($muitas)->create(['type' => 'checking', 'initial_balance' => 1000]);

        $com2 = $this->contarQueries($poucas);
        $com12 = $this->contarQueries($muitas);
        $porConta = ($com12 - $com2) / 10;

        $this->assertLessThan(
            1,
            $porConta,
            "A tela de contas voltou a crescer com o volume: {$porConta} queries por conta "
                ."(2 contas: {$com2}, 12 contas: {$com12})."
        );
    }

    private function contarQueries(User $user): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route('accounts.index'))->assertOk();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }
}
