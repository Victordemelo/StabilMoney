<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Auditoria dos Form Requests atrás do padrão do A-3 (17/09/2026): regra que lê o
 * model da ROTA não pode virar sonda sobre o recurso de OUTRA família.
 *
 * O Form Request valida ANTES de a policy do controller rodar. Uma regra que dependa
 * de um dado do recurso da rota (data da compra mais antiga do cartão, uso do cheque
 * especial, valor previsto da conta fixa, e-mail do dependente) muda a resposta
 * conforme esse dado — e a DIFERENÇA entre duas respostas já entrega o segredo, mesmo
 * que mensagem nenhuma o cite. Nos quatro primeiros casos daqui o `authorize()` do
 * request fecha a porta hoje; o que faltava era um teste que ficasse vermelho no dia em
 * que alguém o tirasse.
 *
 * A prova é sempre a mesma: dois chutes, um de cada lado do dado secreto, recebem
 * respostas IDÊNTICAS — 403, sem erro de validação — e nada do recurso alheio aparece.
 * O último teste cobre os requests que deixam a posse para o controller: sem regra
 * sobre a rota, a validação do recurso alheio tem de ser a mesma do próprio.
 * (O caso das METAS, que vazava de verdade, está em `EditarMetaAlheiaNaoVazaPrazoTest`.)
 */
class RecursoAlheioNaRotaNaoViraSondaTest extends TestCase
{
    use RefreshDatabase;

    private User $vizinho;

    private User $intruso;

    private Account $contaDoIntruso;

    protected function setUp(): void
    {
        parent::setUp();

        // O corpo comparado é o que o atacante recebe em produção, sem rastro de
        // depuração: com o debug ligado, o JSON do 403 traz a pilha, e a pilha cita a
        // linha do teste de onde saiu cada requisição.
        config(['app.debug' => false]);

        $this->vizinho = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $this->intruso = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);

        // Conta de caixa DO INTRUSO: o corpo é válido para ele, então nenhuma regra de
        // FK recusa o pedido antes da regra que interessa.
        $this->contaDoIntruso = Account::factory()->for($this->intruso)->create([
            'type' => 'checking',
            'initial_balance' => 100000,
        ]);
    }

    /**
     * Os dois chutes têm de receber a MESMA resposta, e ela tem de ser o 403 da posse —
     * sem erro de validação e sem nenhum dos textos secretos.
     *
     * @param  list<string>  $segredos
     */
    private function assertMesmaResposta403(TestResponse $chuteA, TestResponse $chuteB, array $segredos, string $oQueVazaria): void
    {
        $this->assertSame(
            [$chuteA->status(), $this->corpo($chuteA)],
            [$chuteB->status(), $this->corpo($chuteB)],
            "A resposta muda conforme o dado do recurso alheio: sonda de {$oQueVazaria}.",
        );

        $chuteA->assertForbidden()->assertJsonMissingValidationErrors();

        $corpo = json_encode([$this->corpo($chuteA), $this->corpo($chuteB)], JSON_UNESCAPED_UNICODE);
        foreach ($segredos as $segredo) {
            $this->assertStringNotContainsString($segredo, $corpo);
        }
    }

    /**
     * O corpo decodificado — ou cru, quando não é JSON. Se a posse sumir, um dos chutes
     * pode passar direto e voltar como redirect HTML; a falha precisa mostrar isso, não
     * morrer num "JSON inválido".
     */
    private function corpo(TestResponse $resposta): mixed
    {
        return json_decode($resposta->getContent(), true) ?? $resposta->getContent();
    }

    /**
     * PayInvoiceRequest: o piso do `paid_on` é a data da compra EM ABERTO mais antiga do
     * cartão da rota. Sem a posse antes da validação, "um dia antes" dava 422 e "no dia"
     * passava para o 403 — em ~15 chutes (busca binária) saía a data da compra.
     */
    public function test_pagar_fatura_de_cartao_alheio_nao_revela_a_data_da_compra_mais_antiga(): void
    {
        $cartao = Account::factory()->creditCard()->for($this->vizinho)->create(['name' => 'Cartão Secreto do Vizinho']);
        $dataDaCompra = now()->subDays(10)->startOfDay();

        Transaction::factory()->expense()->for($this->vizinho)->create([
            'account_id' => $cartao->id,
            'amount' => 300,
            'date' => $dataDaCompra->toDateString(),
        ]);

        $pagar = fn (string $paidOn) => $this->actingAs($this->intruso)
            ->postJson(route('faturas.fatura.pagar', $cartao), [
                'pay_account_id' => $this->contaDoIntruso->id,
                'paid_on' => $paidOn,
            ]);

        $this->assertMesmaResposta403(
            $pagar($dataDaCompra->copy()->subDay()->toDateString()),
            $pagar($dataDaCompra->toDateString()),
            ['Cartão Secreto do Vizinho', 'compra mais antiga'],
            'data da compra mais antiga do cartão',
        );

        $this->assertSame(300.0, round((float) $cartao->fresh()->committed, 2), 'A fatura alheia foi mexida.');
    }

    /**
     * UpdateDependentRequest: o `unique` do e-mail ignora o id do dependente da rota. Sem
     * a posse antes da validação, mandar o e-mail CERTO daquele id passava e o errado
     * dava "Este e-mail já está em uso" — a sonda ligava id ↔ e-mail de quem não é seu.
     */
    public function test_editar_dependente_alheio_nao_revela_o_email_dele(): void
    {
        $dependente = User::factory()->create([
            'name' => 'Dependente Secreto',
            'email' => 'dependente.secreto@vizinho.test',
            'is_admin' => false,
            'account_owner_id' => $this->vizinho->id,
        ]);

        $editar = fn (string $email) => $this->actingAs($this->intruso)
            ->patchJson(route('dependentes.update', $dependente), ['name' => 'Invadido', 'email' => $email]);

        $this->assertMesmaResposta403(
            $editar('dependente.secreto@vizinho.test'),
            $editar($this->vizinho->email),
            ['Dependente Secreto', 'já está em uso'],
            'e-mail do dependente',
        );

        $dependente->refresh();
        $this->assertSame('Dependente Secreto', $dependente->name);
        $this->assertSame('dependente.secreto@vizinho.test', $dependente->email);
    }

    /**
     * UpdateAccountRequest, a TERCEIRA regra que lê a conta da rota: reduzir o cheque
     * especial abaixo do que está em uso. A trava de classe (A-3) e o piso do saldo
     * inicial já tinham teste de conta alheia; esta não. A mensagem cita quanto a conta
     * está usando — e, sem ela, "limite abaixo" × "limite acima" do uso ainda diria o valor.
     */
    public function test_editar_conta_alheia_nao_revela_quanto_do_cheque_especial_ela_usa(): void
    {
        $conta = Account::factory()->for($this->vizinho)->overdraft(1000)->create([
            'name' => 'Corrente Secreta do Vizinho',
            'bank' => 'itau',
            'initial_balance' => 0,
        ]);

        // Disponível em −400: R$ 400,00 do cheque especial em uso.
        Transaction::factory()->expense()->for($this->vizinho)->create([
            'account_id' => $conta->id,
            'amount' => 400,
            'date' => now()->toDateString(),
        ]);

        $editar = fn (string $limite) => $this->actingAs($this->intruso)
            ->patchJson(route('accounts.update', $conta), [
                'name' => 'Qualquer nome',
                'type' => 'checking',
                'bank' => 'itau',
                'initial_balance' => '0,00',
                'overdraft_limit' => $limite,
            ]);

        $this->assertMesmaResposta403(
            $editar('100,00'),
            $editar('900,00'),
            ['Corrente Secreta do Vizinho', '400,00', 'cheque especial'],
            'uso do cheque especial',
        );

        $this->assertSame(1000.0, (float) $conta->fresh()->overdraft_limit);
    }

    /**
     * PayFixedBillRequest: o teto do valor pago é 3× o previsto da conta fixa da rota, e
     * a mensagem cita o NOME e o PREVISTO. Duas camadas protegem (o `authorize()` e a
     * guarda de `contaFixa()`); o teste fica vermelho se as duas sumirem.
     */
    public function test_pagar_conta_fixa_alheia_nao_revela_nome_nem_valor_previsto(): void
    {
        $contaFixa = FixedBill::factory()->create([
            'user_id' => $this->vizinho->id,
            'name' => 'Mensalidade Secreta',
            'amount' => 1000,
            'due_day' => 10,
            'starts_on' => now()->startOfMonth()->subMonths(2)->toDateString(),
            'ends_on' => null,
            'active' => true,
        ]);

        $pagar = fn (string $valor) => $this->actingAs($this->intruso)
            ->postJson(route('contas-fixas.pagar', [$contaFixa, now()->format('Y-m')]), [
                'account_id' => $this->contaDoIntruso->id,
                'amount' => $valor,
            ]);

        $this->assertMesmaResposta403(
            $pagar('3.500,00'),
            $pagar('2.500,00'),
            ['Mensalidade Secreta', '1.000,00', 'maior que o previsto'],
            'nome e valor previsto da conta fixa',
        );

        $this->assertSame(0, Transaction::where('fixed_bill_id', $contaFixa->id)->count());
    }

    /**
     * Os cinco requests que devolvem `true` no `authorize()` e deixam a posse para o
     * controller: UpdateTransactionRequest, UpdateCategoryRequest, UpdateInvestmentRequest,
     * StoreGoalContributionRequest e StoreInvestmentContributionRequest. Hoje nenhuma
     * regra deles lê o model da rota — então a validação de um recurso alheio tem de ser
     * IDÊNTICA à do recurso próprio com o mesmo corpo inválido.
     *
     * Aceita também 403: se um dia ganharem a posse no `authorize()` (o remédio do A-3),
     * o teste não pode ficar no caminho de quem endurece.
     */
    public function test_requests_sem_regra_sobre_a_rota_respondem_igual_para_recurso_alheio_e_proprio(): void
    {
        // Nomes DIFERENTES nos dois lados: uma regra que interpolasse o nome do recurso
        // da rota tem de aparecer como diferença entre as respostas, não passar batida
        // por coincidência de texto.
        $do = fn (User $dono, string $quem) => [
            'lancamento' => Transaction::factory()->expense()->for($dono)->create([
                'account_id' => Account::factory()->for($dono)->create(['type' => 'checking'])->id,
                'amount' => 50,
                'description' => "Lançamento {$quem}",
            ]),
            'categoria' => Category::factory()->expense()->for($dono)->create(['name' => "Categoria {$quem}"]),
            'investimento' => Investment::factory()->for($dono)->create(['name' => "Investimento {$quem}"]),
            'meta' => Goal::factory()->for($dono)->create(['name' => "Meta {$quem}"]),
        ];
        $alheio = $do($this->vizinho, 'do Vizinho');
        $proprio = $do($this->intruso, 'do Intruso');

        // Corpos completos, inválidos só no valor/nome/classe: todas as regras rodam.
        $casos = [
            ['PATCH', 'transactions.update', 'lancamento', [
                'type' => 'expense', 'amount' => '0,00', 'account_id' => $this->contaDoIntruso->id,
                'date' => now()->toDateString(), 'description' => 'x',
            ]],
            ['PATCH', 'categories.update', 'categoria', ['name' => '', 'type' => 'expense', 'color' => '#0F6B47']],
            ['PATCH', 'investimentos.update', 'investimento', ['name' => 'x', 'classe' => 'nao-existe']],
            ['POST', 'metas.aportes.store', 'meta', ['amount' => '0,00', 'account_id' => $this->contaDoIntruso->id]],
            ['POST', 'investimentos.aportes.store', 'investimento', ['amount' => '0,00', 'account_id' => $this->contaDoIntruso->id]],
        ];

        foreach ($casos as [$metodo, $rota, $recurso, $corpo]) {
            $noAlheio = $this->actingAs($this->intruso)->json($metodo, route($rota, $alheio[$recurso]), $corpo);
            $noProprio = $this->actingAs($this->intruso)->json($metodo, route($rota, $proprio[$recurso]), $corpo);

            $this->assertContains($noAlheio->status(), [403, 422], "{$rota}: status inesperado para recurso alheio.");

            if ($noAlheio->status() === 422) {
                $this->assertSame(
                    $noProprio->json(),
                    $noAlheio->json(),
                    "{$rota}: a validação do recurso alheio difere da do próprio — alguma regra passou a ler a rota.",
                );
            }

            foreach (['Lançamento do Vizinho', 'Categoria do Vizinho', 'Investimento do Vizinho', 'Meta do Vizinho'] as $segredo) {
                $this->assertStringNotContainsString($segredo, $noAlheio->getContent(), "{$rota}: vazou \"{$segredo}\".");
            }
        }
    }
}
