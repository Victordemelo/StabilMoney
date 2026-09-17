<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FundingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auditoria dos Form Requests (17/09/2026), o lado do CORPO: um id de OUTRA família
 * mandado num campo (conta, categoria, autor, investimento da fonte, conta vinculada)
 * tem de receber EXATAMENTE a mesma resposta que um id que não existe.
 *
 * Qualquer diferença — outra mensagem, um erro a mais ou a menos, outro status — vira
 * sonda: dá para varrer ids e descobrir quais existem, de que tipo são (corrente ×
 * cartão) ou quanto têm. O padrão que garante isso é `Rule::exists` escopado por
 * `ownerId()` com mensagem genérica, MAIS toda consulta auxiliar (closure de regra,
 * `withValidator`, `prepareForValidation`) escopada do mesmo jeito. Um `where` de
 * família esquecido numa delas e este teste fica vermelho.
 *
 * Cada caso manda um corpo VÁLIDO exceto pelo campo em teste — a resposta do id
 * inexistente precisa ter erro só nele (ou no par que ele alimenta, no Pix). Assim o
 * teste não passa "de graça" com um corpo que já seria recusado por outro motivo.
 */
class IdAlheioNoCorpoIgualAIdInexistenteTest extends TestCase
{
    use RefreshDatabase;

    private const ID_INEXISTENTE = 999999;

    private const SEGREDOS = [
        'Corrente Secreta do Vizinho',
        'Poupança Secreta do Vizinho',
        'Categoria Secreta do Vizinho',
        'Investimento Secreto do Vizinho',
        'Vizinho Secreto',
        // Os saldos das contas alheias (ver setUp).
        '7.777,00',
        '8.888,00',
    ];

    private User $eu;

    private Account $corrente;

    private Account $poupanca;

    private Account $cartao;

    private User $vizinho;

    private Account $correnteAlheia;

    private Account $poupancaAlheia;

    private Category $categoriaAlheia;

    private Investment $investimentoAlheio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eu = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $this->corrente = Account::factory()->for($this->eu)->create(['type' => 'checking', 'initial_balance' => 100000]);
        $this->poupanca = Account::factory()->for($this->eu)->create(['type' => 'savings', 'initial_balance' => 5000]);
        $this->cartao = Account::factory()->creditCard()->for($this->eu)->create();

        $this->vizinho = User::factory()->create(['name' => 'Vizinho Secreto', 'is_admin' => true, 'account_owner_id' => null]);
        $this->correnteAlheia = Account::factory()->for($this->vizinho)->create([
            'name' => 'Corrente Secreta do Vizinho', 'type' => 'checking', 'initial_balance' => 7777,
        ]);
        $this->poupancaAlheia = Account::factory()->for($this->vizinho)->create([
            'name' => 'Poupança Secreta do Vizinho', 'type' => 'savings', 'initial_balance' => 8888,
        ]);
        $this->categoriaAlheia = Category::factory()->expense()->for($this->vizinho)->create(['name' => 'Categoria Secreta do Vizinho']);
        $this->investimentoAlheio = Investment::factory()->for($this->vizinho)->create(['name' => 'Investimento Secreto do Vizinho']);
    }

    /**
     * Manda o corpo duas vezes — com o id alheio e com um id inexistente no `$campo` — e
     * exige respostas idênticas, recusadas com erro só em `$errosEsperados`.
     *
     * @param  array<string, mixed>  $corpo
     * @param  list<string>|null  $errosEsperados  padrão: só o próprio `$campo`
     */
    private function assertIdAlheioIgualAInexistente(string $metodo, string $url, array $corpo, string $campo, int $idAlheio, ?array $errosEsperados = null): void
    {
        $alheio = $this->actingAs($this->eu)->json($metodo, $url, [...$corpo, $campo => $idAlheio]);
        $inexistente = $this->actingAs($this->eu)->json($metodo, $url, [...$corpo, $campo => self::ID_INEXISTENTE]);

        $contexto = strtoupper($metodo)." {$url} [{$campo}]";

        $this->assertSame(
            [$inexistente->status(), json_decode($inexistente->getContent(), true) ?? $inexistente->getContent()],
            [$alheio->status(), json_decode($alheio->getContent(), true) ?? $alheio->getContent()],
            "{$contexto}: o id de outra família recebe resposta diferente da de um id inexistente — sonda de ids alheios.",
        );

        $inexistente->assertStatus(422);
        $this->assertSame(
            $errosEsperados ?? [$campo],
            array_keys($inexistente->json('errors')),
            "{$contexto}: o corpo-base deveria ser válido fora do campo em teste.",
        );

        foreach (self::SEGREDOS as $segredo) {
            $this->assertStringNotContainsString($segredo, $alheio->getContent(), "{$contexto}: vazou \"{$segredo}\".");
        }
    }

    private function comFonteResgate(array $corpo): array
    {
        return [...$corpo, 'funding_source' => FundingSource::RESGATE_INVESTIMENTO];
    }

    /** StoreTransactionRequest (despesa/receita). */
    public function test_lancamento(): void
    {
        $corpo = [
            'type' => 'expense',
            'amount' => '10,00',
            'account_id' => $this->corrente->id,
            'date' => now()->toDateString(),
            'description' => 'Mercado',
        ];
        $url = route('transactions.store');

        $this->assertIdAlheioIgualAInexistente('POST', $url, $corpo, 'account_id', $this->correnteAlheia->id);
        $this->assertIdAlheioIgualAInexistente('POST', $url, $corpo, 'category_id', $this->categoriaAlheia->id);
        $this->assertIdAlheioIgualAInexistente('POST', $url, $corpo, 'made_by_user_id', $this->vizinho->id);
        $this->assertIdAlheioIgualAInexistente('POST', $url, $this->comFonteResgate($corpo), 'funding_investment_id', $this->investimentoAlheio->id);
    }

    /** StoreTransactionRequest com `type=transfer` (o caminho da fila offline) e StoreTransferRequest. */
    public function test_transferencia(): void
    {
        $corpo = [
            'amount' => '10,00',
            'account_id' => $this->corrente->id,
            'to_account_id' => $this->poupanca->id,
            'date' => now()->toDateString(),
        ];

        $pelaFila = route('transactions.store');
        $this->assertIdAlheioIgualAInexistente('POST', $pelaFila, [...$corpo, 'type' => 'transfer'], 'account_id', $this->correnteAlheia->id);
        $this->assertIdAlheioIgualAInexistente('POST', $pelaFila, [...$corpo, 'type' => 'transfer'], 'to_account_id', $this->poupancaAlheia->id);

        $pelaRota = route('transactions.transfer');
        $this->assertIdAlheioIgualAInexistente('POST', $pelaRota, $corpo, 'to_account_id', $this->poupancaAlheia->id);
        $this->assertIdAlheioIgualAInexistente('POST', $pelaRota, $corpo, 'made_by_user_id', $this->vizinho->id);
        $this->assertIdAlheioIgualAInexistente('POST', $pelaRota, $this->comFonteResgate($corpo), 'funding_investment_id', $this->investimentoAlheio->id);
    }

    /** UpdateTransactionRequest. */
    public function test_edicao_de_lancamento(): void
    {
        $lancamento = Transaction::factory()->expense()->for($this->eu)->create([
            'account_id' => $this->corrente->id,
            'amount' => 50,
            'date' => now()->toDateString(),
        ]);

        $corpo = [
            'type' => 'expense',
            'amount' => '50,00',
            'account_id' => $this->corrente->id,
            'date' => now()->toDateString(),
            'description' => 'Mercado',
        ];
        $url = route('transactions.update', $lancamento);

        $this->assertIdAlheioIgualAInexistente('PATCH', $url, $corpo, 'account_id', $this->correnteAlheia->id);
        $this->assertIdAlheioIgualAInexistente('PATCH', $url, $corpo, 'category_id', $this->categoriaAlheia->id);
    }

    /** StoreFaturaLaunchRequest — inclusive o `withValidator` do parcelado, que busca a conta. */
    public function test_lancamento_em_faturas(): void
    {
        $corpo = [
            'description' => 'Compra',
            'amount' => '30,00',
            'date' => now()->toDateString(),
            'account_id' => $this->cartao->id,
            'mode' => 'avista',
        ];
        $url = route('faturas.lancar');

        $this->assertIdAlheioIgualAInexistente('POST', $url, $corpo, 'account_id', $this->correnteAlheia->id);
        $this->assertIdAlheioIgualAInexistente('POST', $url, $corpo, 'category_id', $this->categoriaAlheia->id);
        $this->assertIdAlheioIgualAInexistente('POST', $url, $corpo, 'made_by_user_id', $this->vizinho->id);
        $this->assertIdAlheioIgualAInexistente('POST', $url, $this->comFonteResgate($corpo), 'funding_investment_id', $this->investimentoAlheio->id);

        // Parcelado numa CORRENTE alheia: se a busca do `withValidator` esquecesse a
        // família, somaria "parcelamento só em cartão" — e diria o tipo da conta alheia.
        $parcelado = [...$corpo, 'mode' => 'parcelado', 'installments' => 3];
        $this->assertIdAlheioIgualAInexistente('POST', $url, $parcelado, 'account_id', $this->correnteAlheia->id);
    }

    /** StoreAccountRequest / UpdateAccountRequest — contas vinculadas do débito e do Pix. */
    public function test_contas_vinculadas_de_debito_e_pix(): void
    {
        $url = route('accounts.store');
        $debito = ['name' => 'Débito novo', 'type' => 'debit_card', 'bank' => 'itau'];

        $this->assertIdAlheioIgualAInexistente('POST', $url, $debito, 'checking_account_id', $this->correnteAlheia->id);
        $this->assertIdAlheioIgualAInexistente('POST', $url, $debito, 'savings_account_id', $this->poupancaAlheia->id);

        // O Pix tem um select só; o `prepareForValidation` busca a conta para decidir a
        // coluna. Conta alheia e inexistente têm de dar no mesmo: nenhuma vinculada.
        $this->assertIdAlheioIgualAInexistente(
            'POST', $url, ['name' => 'Pix novo', 'type' => 'pix', 'bank' => 'nubank'],
            'pix_account_id', $this->correnteAlheia->id, ['checking_account_id', 'savings_account_id'],
        );

        $meuDebito = Account::factory()->debitCard($this->corrente->id)->for($this->eu)->create(['bank' => 'itau']);
        $this->assertIdAlheioIgualAInexistente(
            'PATCH', route('accounts.update', $meuDebito), $debito, 'checking_account_id', $this->correnteAlheia->id,
        );
    }

    /** StoreFixedBillRequest / UpdateFixedBillRequest. */
    public function test_contas_fixas(): void
    {
        $corpo = [
            'name' => 'Internet',
            'amount' => '100,00',
            'due_day' => 5,
            'starts_on' => now()->startOfMonth()->toDateString(),
        ];

        $this->assertIdAlheioIgualAInexistente('POST', route('contas-fixas.store'), $corpo, 'account_id', $this->correnteAlheia->id);
        $this->assertIdAlheioIgualAInexistente('POST', route('contas-fixas.store'), $corpo, 'category_id', $this->categoriaAlheia->id);

        $minha = FixedBill::factory()->create(['user_id' => $this->eu->id, 'amount' => 100, 'due_day' => 5]);
        $this->assertIdAlheioIgualAInexistente('PATCH', route('contas-fixas.update', $minha), $corpo, 'account_id', $this->correnteAlheia->id);
    }

    /**
     * Store/WithdrawGoalContributionRequest e Store/WithdrawInvestmentContributionRequest.
     * A closure do valor consulta a conta informada (disponível no aporte, o que ela
     * guardou no resgate): conta alheia não pode chegar a ser lida.
     */
    public function test_aportes_e_resgates(): void
    {
        $meta = Goal::factory()->for($this->eu)->create();
        GoalContribution::factory()->for($meta)->for($this->corrente)->aporte()->create(['amount' => 100]);

        $investimento = Investment::factory()->for($this->eu)->create();
        InvestmentContribution::factory()->for($investimento)->for($this->corrente)->aporte()->create(['amount' => 100]);

        // APORTE acima do disponível da conta alheia (R$ 7.777,00) e dentro do da minha:
        // se a closure lesse a conta alheia, a mensagem de "maior que o disponível"
        // apareceria com o saldo dela. RESGATE de qualquer valor já basta: conta que
        // nunca aportou ali cai em "a conta “…” não tem nada guardado", com o nome.
        $aporte = ['amount' => '9.000,00', 'account_id' => $this->corrente->id];
        $resgate = ['amount' => '10,00', 'account_id' => $this->corrente->id];

        foreach ([
            [route('metas.aportes.store', $meta), $aporte],
            [route('metas.resgates.store', $meta), $resgate],
            [route('investimentos.aportes.store', $investimento), $aporte],
            [route('investimentos.resgates.store', $investimento), $resgate],
        ] as [$url, $corpo]) {
            $this->assertIdAlheioIgualAInexistente('POST', $url, $corpo, 'account_id', $this->correnteAlheia->id);
            $this->assertIdAlheioIgualAInexistente('POST', $url, $corpo, 'made_by_user_id', $this->vizinho->id);
        }
    }

    /** StoreInvestmentRequest — o aporte inicial também consulta o disponível da conta. */
    public function test_investimento_com_aporte_inicial(): void
    {
        $corpo = [
            'name' => 'CDB novo',
            'classe' => 'renda_fixa',
            'indexador' => 'CDI',
            'taxa' => '100',
            // Acima do disponível da conta alheia, dentro do da minha (ver aportes).
            'valor_inicial' => '9.000,00',
            'account_id' => $this->corrente->id,
            'date' => now()->toDateString(),
        ];
        $url = route('investimentos.store');

        $this->assertIdAlheioIgualAInexistente('POST', $url, $corpo, 'account_id', $this->correnteAlheia->id);
        $this->assertIdAlheioIgualAInexistente('POST', $url, $corpo, 'made_by_user_id', $this->vizinho->id);
    }

    /** PayInvoiceRequest e PayFixedBillRequest — conta que paga e investimento da fonte. */
    public function test_pagamentos(): void
    {
        $fatura = route('faturas.fatura.pagar', $this->cartao);
        $corpoFatura = ['pay_account_id' => $this->corrente->id];

        $this->assertIdAlheioIgualAInexistente('POST', $fatura, $corpoFatura, 'pay_account_id', $this->correnteAlheia->id);
        $this->assertIdAlheioIgualAInexistente('POST', $fatura, $this->comFonteResgate($corpoFatura), 'funding_investment_id', $this->investimentoAlheio->id);

        $minha = FixedBill::factory()->create([
            'user_id' => $this->eu->id,
            'amount' => 500,
            'due_day' => 10,
            'starts_on' => now()->startOfMonth()->toDateString(),
        ]);
        $contaFixa = route('contas-fixas.pagar', [$minha, now()->format('Y-m')]);
        $corpoContaFixa = ['account_id' => $this->corrente->id, 'amount' => '500,00'];

        $this->assertIdAlheioIgualAInexistente('POST', $contaFixa, $corpoContaFixa, 'account_id', $this->correnteAlheia->id);
        $this->assertIdAlheioIgualAInexistente('POST', $contaFixa, $this->comFonteResgate($corpoContaFixa), 'funding_investment_id', $this->investimentoAlheio->id);
    }
}
