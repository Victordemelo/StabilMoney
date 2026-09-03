<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\SidebarService;
use App\Support\FundingSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Transferência entre contas de caixa (corrente ↔ poupança da mesma família).
 *
 * Antes, mover R$ 300 exigia uma despesa + uma receita, que inflavam as duas
 * somas do mês no dashboard. Agora nascem DUAS linhas ligadas por
 * `transfer_group_id`: a saída passa pelo FundingService como qualquer despesa
 * (422/409 quando o disponível não cobre), o saldo de cada conta fecha sozinho,
 * e receitas/despesas/donut/trends ignoram as duas pontas.
 */
class TransferenciaEntreContasTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private Account $corrente;

    private Account $poupanca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $this->corrente = Account::factory()->for($this->titular)->create([
            'name' => 'Corrente', 'type' => 'checking', 'initial_balance' => 1000, 'overdraft_limit' => 0,
        ]);
        $this->poupanca = Account::factory()->for($this->titular)->create([
            'name' => 'Poupança', 'type' => 'savings', 'initial_balance' => 1000,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function transferir(User $quem, array $extra = [], bool $json = true): TestResponse
    {
        $payload = array_merge([
            'amount' => '300,00',
            'account_id' => $this->corrente->id,
            'to_account_id' => $this->poupanca->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ], $extra);

        $req = $this->actingAs($quem);

        return $json
            ? $req->postJson(route('transactions.transfer'), $payload)
            : $req->post(route('transactions.transfer'), $payload);
    }

    public function test_transferencia_move_o_dinheiro_e_liga_as_duas_pontas(): void
    {
        $this->transferir($this->titular, ['description' => ''])->assertCreated();

        $this->assertSame(700.0, $this->corrente->fresh()->available);
        $this->assertSame(1300.0, $this->poupanca->fresh()->available);

        $pontas = Transaction::where('user_id', $this->titular->id)->orderBy('id')->get();
        $this->assertCount(2, $pontas);

        [$saida, $entrada] = $pontas;
        $this->assertSame('expense', $saida->type);
        $this->assertSame('income', $entrada->type);
        $this->assertSame($this->corrente->id, $saida->account_id);
        $this->assertSame($this->poupanca->id, $entrada->account_id);
        $this->assertNotNull($saida->transfer_group_id);
        $this->assertSame($saida->transfer_group_id, $entrada->transfer_group_id);
        $this->assertNull($saida->category_id);
        $this->assertNull($entrada->category_id);
        $this->assertSame($this->titular->id, $saida->made_by_user_id);
        $this->assertSame('Transferência para Poupança', $saida->description);
        $this->assertSame('Transferência de Corrente', $entrada->description);
        $this->assertSame($entrada->id, $saida->contrapartida()->id);
    }

    public function test_dashboard_nao_conta_a_transferencia_como_receita_nem_despesa(): void
    {
        $this->transferir($this->titular)->assertCreated();

        $dados = app(DashboardService::class)->build($this->titular->id);
        $mes = $dados['payload']['periods']['mes']['stats'];

        $this->assertSame(0.0, $mes['receitas']);
        $this->assertSame(0.0, $mes['despesas']);
        $this->assertSame(2000.0, $mes['saldo']);
        $this->assertSame(2000.0, $dados['totalBalance']);
        $this->assertSame([], $dados['payload']['cats'], 'o donut não ganha fatia "Sem categoria"');

        // Séries de fluxo (semana e sparks) também zeradas; a de saldo termina em 2.000.
        $semana = $dados['payload']['periods']['semana'];
        $this->assertSame(0.0, array_sum($semana['receitas']));
        $this->assertSame(0.0, array_sum($semana['despesas']));
        $this->assertSame(0.0, array_sum($dados['payload']['sparks']['receitas']));
        $this->assertSame(0.0, array_sum($dados['payload']['sparks']['despesas']));
        $this->assertSame(2000.0, end($dados['payload']['sparks']['saldo']));

        // A sidebar só olha saldo: patrimônio segue 2.000.
        $sidebar = app(SidebarService::class)->build($this->titular->id);
        $this->assertSame(2000.0, $sidebar['saldoTotal']);
        $this->assertSame(2000.0, $sidebar['disponivel']);
    }

    public function test_origem_sem_saldo_e_sem_fonte_recusa_e_nao_grava_nada(): void
    {
        $this->corrente->update(['initial_balance' => 100]);

        $this->transferir($this->titular)->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(100.0, $this->corrente->fresh()->available);
        $this->assertSame(1000.0, $this->poupanca->fresh()->available);
    }

    public function test_com_cheque_especial_pergunta_a_fonte_e_grava_as_duas_pontas_ao_escolher(): void
    {
        $this->corrente->update(['initial_balance' => 100, 'overdraft_limit' => 500]);
        $uuid = (string) Str::uuid();

        $this->transferir($this->titular, ['client_uuid' => $uuid])
            ->assertStatus(409)
            ->assertJsonPath('precisa_fonte', true);
        $this->assertSame(0, Transaction::count(), 'enquanto não escolher, nada é gravado');

        $this->transferir($this->titular, [
            'client_uuid' => $uuid,
            'funding_source' => FundingSource::CHEQUE_ESPECIAL,
        ])->assertCreated();

        $this->assertSame(2, Transaction::count());
        $saida = Transaction::where('type', 'expense')->firstOrFail();
        $this->assertSame(FundingSource::CHEQUE_ESPECIAL, $saida->funding_source);
        // Faltavam 200 além dos 100 disponíveis: é o cheque especial que a saída consome.
        $this->assertSame(200.0, (float) $saida->funding_amount);
        $this->assertNull(Transaction::where('type', 'income')->firstOrFail()->funding_source);

        $this->assertSame(-200.0, $this->corrente->fresh()->available);
        $this->assertSame(1300.0, $this->poupanca->fresh()->available);
    }

    public function test_cartoes_e_pix_nao_sao_origem_nem_destino(): void
    {
        $credito = Account::factory()->for($this->titular)->creditCard()->create();
        $debito = Account::factory()->for($this->titular)->debitCard($this->corrente->id)->create();
        $pix = Account::factory()->for($this->titular)->pix($this->corrente->id)->create();

        foreach ([$credito, $debito, $pix] as $metodo) {
            $this->transferir($this->titular, ['account_id' => $metodo->id])
                ->assertStatus(422)->assertJsonValidationErrors('account_id');
            $this->transferir($this->titular, ['to_account_id' => $metodo->id])
                ->assertStatus(422)->assertJsonValidationErrors('to_account_id');
        }

        $this->assertSame(0, Transaction::count());
    }

    public function test_mesma_conta_e_conta_de_outra_familia_sao_recusadas(): void
    {
        $this->transferir($this->titular, ['to_account_id' => $this->corrente->id])
            ->assertStatus(422)->assertJsonValidationErrors('to_account_id');

        $outra = User::factory()->create(['is_admin' => true]);
        $contaAlheia = Account::factory()->for($outra)->create(['type' => 'savings']);

        $this->transferir($this->titular, ['to_account_id' => $contaAlheia->id])
            ->assertStatus(422)->assertJsonValidationErrors('to_account_id');
        $this->transferir($this->titular, ['account_id' => $contaAlheia->id])
            ->assertStatus(422)->assertJsonValidationErrors('account_id');

        $this->assertSame(0, Transaction::count());
    }

    public function test_client_uuid_repetido_grava_um_par_so(): void
    {
        $uuid = (string) Str::uuid();

        $this->transferir($this->titular, ['client_uuid' => $uuid])->assertCreated();
        $this->transferir($this->titular, ['client_uuid' => $uuid])->assertOk()->assertJsonPath('created', false);

        $this->assertSame(2, Transaction::count());
        $this->assertSame(700.0, $this->corrente->fresh()->available);
    }

    public function test_post_transactions_com_type_transfer_tambem_transfere(): void
    {
        // É o caminho da fila offline e do service worker, que só conhecem /transactions.
        $this->actingAs($this->titular)->postJson(route('transactions.store'), [
            'type' => 'transfer',
            'amount' => '300,00',
            'account_id' => $this->corrente->id,
            'to_account_id' => $this->poupanca->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ])->assertCreated();

        $this->assertSame(2, Transaction::whereNotNull('transfer_group_id')->count());
        $this->assertSame(0, Transaction::where('type', 'transfer')->count(), '`type` nunca vira "transfer"');
    }

    public function test_excluir_uma_ponta_apaga_as_duas_e_devolve_os_saldos(): void
    {
        $this->transferir($this->titular)->assertCreated();
        $entrada = Transaction::where('type', 'income')->firstOrFail();

        $this->actingAs($this->titular)
            ->delete(route('transactions.destroy', $entrada))
            ->assertRedirect(route('transactions.index'));

        $this->assertSame(0, Transaction::count());
        $this->assertSame(1000.0, $this->corrente->fresh()->available);
        $this->assertSame(1000.0, $this->poupanca->fresh()->available);
    }

    public function test_editar_descricao_e_data_vale_para_as_duas_pontas(): void
    {
        $this->transferir($this->titular)->assertCreated();
        $saida = Transaction::where('type', 'expense')->firstOrFail();
        $ontem = CarbonImmutable::yesterday()->toDateString();

        $this->actingAs($this->titular)->put(route('transactions.update', $saida), [
            'type' => 'expense',
            'amount' => '300,00',
            'account_id' => $this->corrente->id,
            'description' => 'Reserva do mês',
            'date' => $ontem,
        ])->assertRedirect(route('transactions.index'))->assertSessionHas('status');

        foreach (Transaction::all() as $ponta) {
            $this->assertSame('Reserva do mês', $ponta->description);
            $this->assertSame($ontem, $ponta->date->toDateString());
        }
    }

    public function test_editar_valor_conta_ou_categoria_de_uma_ponta_e_recusado(): void
    {
        $this->transferir($this->titular)->assertCreated();
        $saida = Transaction::where('type', 'expense')->firstOrFail();
        $categoria = Category::factory()->for($this->titular)->expense()->create();
        $base = [
            'type' => 'expense',
            'amount' => '300,00',
            'account_id' => $this->corrente->id,
            'date' => CarbonImmutable::today()->toDateString(),
        ];

        $tentativas = [
            ['amount' => '200,00'],
            ['account_id' => $this->poupanca->id],
            ['type' => 'income'],
            ['category_id' => $categoria->id],
        ];

        foreach ($tentativas as $mudanca) {
            $this->actingAs($this->titular)
                ->from(route('transactions.edit', $saida))
                ->put(route('transactions.update', $saida), array_merge($base, $mudanca))
                ->assertRedirect(route('transactions.edit', $saida))
                ->assertSessionHasErrors('transaction');
        }

        $this->assertSame(300.0, (float) $saida->fresh()->amount);
        $this->assertSame('expense', $saida->fresh()->type);
        $this->assertNull($saida->fresh()->category_id);
        $this->assertSame(700.0, $this->corrente->fresh()->available);
        $this->assertSame(1300.0, $this->poupanca->fresh()->available);
    }

    public function test_dependente_transfere_na_familia_e_outra_familia_recebe_403(): void
    {
        $dependente = User::factory()->create(['is_admin' => false, 'account_owner_id' => $this->titular->id]);

        $this->transferir($dependente)->assertCreated();
        $saida = Transaction::where('type', 'expense')->firstOrFail();
        $this->assertSame($this->titular->id, $saida->user_id, 'dono é o titular da família');
        $this->assertSame($dependente->id, $saida->made_by_user_id);

        $intruso = User::factory()->create(['is_admin' => true]);
        $this->actingAs($intruso)->get(route('transactions.edit', $saida))->assertForbidden();
        $this->actingAs($intruso)->delete(route('transactions.destroy', $saida))->assertForbidden();
        $this->assertSame(2, Transaction::count());
    }

    public function test_historico_mostra_o_selo_e_filtra_transferencias(): void
    {
        $this->transferir($this->titular)->assertCreated();
        Transaction::factory()->for($this->titular)->for($this->corrente)->income()->create([
            'description' => 'Salário', 'date' => CarbonImmutable::today()->toDateString(),
        ]);

        $this->actingAs($this->titular)->get(route('transactions.index'))
            ->assertOk()->assertSee('tx-tag')->assertSee('Transferência para Poupança');

        // "Receitas" NÃO lista a entrada da transferência; "Transferências" lista só as pontas.
        $this->actingAs($this->titular)->get(route('transactions.index', ['type' => 'income']))
            ->assertOk()->assertSee('Salário')->assertDontSee('Transferência de Corrente');
        $this->actingAs($this->titular)->get(route('transactions.index', ['type' => 'transfer']))
            ->assertOk()->assertSee('Transferência de Corrente')->assertDontSee('Salário');
    }

    public function test_sem_js_a_rota_web_redireciona_com_flash_e_o_modal_oferece_o_segmento(): void
    {
        $this->transferir($this->titular, [], json: false)
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHas('status', fn (string $msg) => str_contains($msg, 'Transferência de R$ 300,00'));

        $html = $this->actingAs($this->titular)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('lm-tt-transfer', $html);
        $this->assertStringContainsString('lm-to-account', $html);
        $this->assertStringContainsString(route('transactions.transfer'), $html);
    }

    public function test_com_uma_conta_de_caixa_so_o_modal_nao_oferece_transferencia(): void
    {
        $this->poupanca->delete();

        $html = $this->actingAs($this->titular)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('lm-tt-transfer', $html);
    }

    /**
     * A página cheia (`transactions/create`, o fallback sem JS do modal) oferece o
     * mesmo terceiro segmento e o select "Para" com as contas de CAIXA da família —
     * cartão e métodos espelho ficam de fora. Antes só o modal tinha isso, e quem
     * caía na página cheia (sem JS, ou pelo href do "Nova transação") não tinha
     * como transferir.
     */
    public function test_pagina_cheia_oferece_o_segmento_e_o_select_para_com_duas_contas_de_caixa(): void
    {
        $cartao = Account::factory()->for($this->titular)->creditCard()->create(['name' => 'Cartão Roxo']);
        $pix = Account::factory()->for($this->titular)->pix($this->corrente->id)->create(['name' => 'Chave Pix']);

        $html = $this->actingAs($this->titular)->get(route('transactions.create'))->assertOk()->getContent();

        $this->assertStringContainsString('id="tt-transfer"', $html);
        $this->assertStringContainsString('name="type" value="transfer"', $html);
        $this->assertStringContainsString('name="to_account_id"', $html);
        $this->assertStringContainsString('type-toggle tt-3', $html);

        // O "Para" lista só corrente/poupança: nem o cartão nem o Pix aparecem lá.
        preg_match('/<select[^>]*id="to_account_id"[^>]*>(.*?)<\/select>/s', $html, $m);
        $this->assertNotEmpty($m, 'o select "Para" precisa existir');
        $this->assertStringContainsString('value="'.$this->corrente->id.'"', $m[1]);
        $this->assertStringContainsString('value="'.$this->poupanca->id.'"', $m[1]);
        $this->assertStringNotContainsString('value="'.$cartao->id.'"', $m[1]);
        $this->assertStringNotContainsString('value="'.$pix->id.'"', $m[1]);
        $this->assertStringNotContainsString('Cartão Roxo', $m[1]);

        // A origem marca quem é caixa, para o JS filtrar o "De" em transferência.
        $this->assertMatchesRegularExpression(
            '/value="'.$this->corrente->id.'"[^>]*data-cash="1"/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/value="'.$cartao->id.'"[^>]*data-cash="0"/',
            $html,
        );
    }

    public function test_pagina_cheia_nao_oferece_transferencia_com_uma_conta_de_caixa_so(): void
    {
        $this->poupanca->delete();

        $html = $this->actingAs($this->titular)->get(route('transactions.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="tt-transfer"', $html);
        $this->assertStringNotContainsString('name="to_account_id"', $html);
        $this->assertStringNotContainsString('tt-3', $html);
    }

    /**
     * O POST da página cheia (form comum, sem JS, com a categoria que o select ainda
     * carrega quando o script não roda) transfere pela rota `transactions.store`,
     * redireciona com flash e grava as duas pontas — categoria ignorada.
     */
    public function test_post_do_formulario_da_pagina_cheia_transfere_e_redireciona_com_flash(): void
    {
        $categoria = Category::factory()->for($this->titular)->create(['type' => 'expense']);

        $this->actingAs($this->titular)
            ->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'type' => 'transfer',
                'amount' => '300,00',
                'account_id' => $this->corrente->id,
                'to_account_id' => $this->poupanca->id,
                'category_id' => $categoria->id,
                'date' => CarbonImmutable::today()->toDateString(),
                'description' => 'Guardando para a viagem',
            ])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $msg) => str_contains($msg, 'Transferência de R$ 300,00'));

        $pontas = Transaction::where('user_id', $this->titular->id)->orderBy('id')->get();
        $this->assertCount(2, $pontas);
        $this->assertSame([$pontas[0]->transfer_group_id], $pontas->pluck('transfer_group_id')->unique()->all());
        $this->assertSame(['expense', 'income'], $pontas->pluck('type')->all());
        $this->assertSame([null, null], $pontas->pluck('category_id')->all(), 'transferência não tem categoria');
        $this->assertSame(['Guardando para a viagem', 'Guardando para a viagem'], $pontas->pluck('description')->all());
        $this->assertSame(700.0, $this->corrente->fresh()->available);
        $this->assertSame(1300.0, $this->poupanca->fresh()->available);
    }

    /** Erro de validação na página cheia volta com o segmento Transferência marcado. */
    public function test_erro_de_validacao_da_pagina_cheia_reabre_no_segmento_transferencia(): void
    {
        $resposta = $this->actingAs($this->titular)
            ->from(route('transactions.create'))
            ->post(route('transactions.store'), [
                'type' => 'transfer',
                'amount' => '300,00',
                'account_id' => $this->corrente->id,
                'to_account_id' => $this->corrente->id, // mesma conta
                'date' => CarbonImmutable::today()->toDateString(),
            ])
            ->assertRedirect(route('transactions.create'))
            ->assertSessionHasErrors('to_account_id');

        $html = $this->actingAs($this->titular)->get(route('transactions.create'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="tt-transfer"[^>]*checked/', $html);
        $this->assertStringContainsString('data-type="transfer"', $html);
    }

    public function test_tela_de_edicao_da_ponta_esconde_valor_conta_e_categoria(): void
    {
        $this->transferir($this->titular)->assertCreated();
        $saida = Transaction::where('type', 'expense')->firstOrFail();

        $this->actingAs($this->titular)->get(route('transactions.edit', $saida))
            ->assertOk()
            ->assertSee('Transferência entre contas')
            ->assertDontSee('id="category_id"', escape: false)
            ->assertSee('name="account_id" value="'.$this->corrente->id.'"', escape: false);
    }

    /**
     * A ponta de saída não é "despesa avulsa" em /faturas nem "gasto do mês" no card
     * de dependentes: é o mesmo dinheiro chegando noutra conta da família.
     */
    public function test_faturas_e_dependentes_nao_contam_a_transferencia_como_gasto(): void
    {
        $this->transferir($this->titular)->assertSuccessful();

        $faturas = $this->actingAs($this->titular)->get(route('faturas.index'))->assertOk();
        $faturas->assertDontSee('Transferência para');

        $dependentes = $this->actingAs($this->titular)->get(route('dependentes'))->assertOk();
        $this->assertSame(0.0, (float) $dependentes->viewData('gastoFamilia'));
    }
}
