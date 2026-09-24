<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\CreditSettlement;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\Investment;
use App\Models\InvestmentContribution;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\FaturaService;
use App\Services\FixedBillService;
use App\Services\SidebarService;
use App\Support\FundingSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * INVARIANTES DO DINHEIRO POR SEQUÊNCIA DE OPERAÇÕES (teste de propriedades).
 *
 * As auditorias anteriores atacaram o núcleo caso a caso. Aqui o ataque é por
 * COMBINAÇÃO: com uma semente fixa (`mt_srand`), monta uma família completa
 * (titular + dependente, corrente com cheque especial, poupança, cartão de
 * crédito, débito e Pix espelhando, uma meta, dois investimentos, duas contas
 * fixas) e executa dezenas de operações aleatórias pelas ROTAS HTTP, do jeito que
 * a tela oferece — lançar, transferir, parcelar, pagar e estornar fatura, quitar
 * pelo crédito e desfazer, excluir e editar, aportar e resgatar, pagar conta
 * fixa, avançar a recorrência, andar com o relógio. Cada 409 de "de onde sai o
 * dinheiro" é respondido como o modal responderia (ou com desistência).
 *
 * Depois de CADA operação confere:
 *
 *  1. um LIVRO-SOMBRA mantido pelo próprio teste, a partir do que o usuário pediu
 *     (e não do que o servidor calculou): saldo de cada conta de caixa, dívida em
 *     aberto de cada cartão e o guardado de cada conta em cada meta/investimento.
 *     O resgate que cobre uma despesa é previsto aqui (valor − disponível) e
 *     comparado com o gravado;
 *  2. o PISO do cheque especial (`available ≥ −overdraft_limit`, I1 da spec) —
 *     só uma obrigação vencida paga (e o estorno de um pagamento de fatura, por
 *     decisão) pode furá-lo; recusa de uma trava é desfecho válido da operação;
 *  3. a ESTRUTURA: transferência em par, compra paga no cartão sempre com a
 *     quitação que a pagou (e a quitação valendo a soma do que marcou), quitação
 *     pelo crédito somando zero, resgate ligado a uma despesa viva e do mesmo
 *     valor da auditoria, uma parcela/ocorrência por ciclo;
 *  4. as TELAS: sidebar, dashboard, /faturas e o select de pagamento contando a
 *     mesma história que as contas;
 *  5. que operação recusada (409 com desistência, 422, aviso de erro) não deixa
 *     rastro no banco — e que nenhuma operação responde 5xx.
 *
 * Para explorar mais sementes: INVARIANTES_SEMENTES=1-200 INVARIANTES_OPERACOES=80. Acima de
 * ~150 sementes num processo só, rode o PHPUnit direto com mais memória
 * (`php -d memory_limit=1G vendor/bin/phpunit --filter=...`): o `artisan test` abre um
 * subprocesso que não herda o `-d`, e a memória do processo cresce um pouco a cada caso.
 * (Em 24/09/2026 ele achou, entre outros já corrigidos pela varredura do mesmo dia,
 * `EditarDespesaPagaComResgateRespeitaOPisoTest`, `ReenvioDoPagamentoDeContaFixaTest` e
 * `PagarFaturaComParcelaDatadaNoFuturoTest`.)
 */
class InvariantesDoDinheiroEmSequenciaTest extends TestCase
{
    use RefreshDatabase;

    private const EPS = 0.005;

    private User $titular;

    private User $dependente;

    /** @var array<string, Account> corrente, poupanca, cartao, debito, pix */
    private array $contas = [];

    private int $catDespesa;

    private int $catReceita;

    // ------------------------------------------------------------------ livro-sombra

    /** @var array<int, float> conta de caixa => saldo bruto esperado */
    private array $saldo = [];

    /** @var array<int, float> cartão => Σ com sinal do que não foi pago (compra +, estorno −) */
    private array $divida = [];

    /** @var array<string, array<int, float>> 'meta:ID' | 'inv:ID' => [conta => guardado a partir dela] */
    private array $guardado = [];

    /** @var array<int, true> linhas editadas pelo Histórico (a data de uma ocorrência é do usuário) */
    private array $editadas = [];

    /** @var array<int, true> despesas cujo resgate sumiu junto com o investimento excluído */
    private array $resgatesOrfaos = [];

    /** @var list<int> contas de caixa que a operação atual pode deixar abaixo do piso (obrigação vencida) */
    private array $obrigacoes = [];

    /** @var list<string> */
    private array $log = [];

    private string $descricao = '';

    /** @var array{0: string, 1: string, 2: array}|null último envio aceito que o reenvio pode repetir */
    private ?array $ultimoEnvio = null;

    private int $semente = 0;

    private int $passoAtual = 0;

    /** @return array<string, array{int}> */
    public static function sementes(): array
    {
        // Padrão da suíte: 8 sementes × 50 operações, ~12 s. Para explorar, ver o docblock.
        $bruto = trim((string) (getenv('INVARIANTES_SEMENTES') ?: '1-8'));
        $numeros = [];

        foreach (explode(',', $bruto) as $parte) {
            if (preg_match('/^(\d+)-(\d+)$/', trim($parte), $m)) {
                $numeros = array_merge($numeros, range((int) $m[1], (int) $m[2]));
            } elseif (trim($parte) !== '') {
                $numeros[] = (int) trim($parte);
            }
        }

        $casos = [];
        foreach ($numeros as $n) {
            $casos['semente '.$n] = [$n];
        }

        return $casos;
    }

    #[DataProvider('sementes')]
    public function test_invariantes_do_dinheiro_sobrevivem_a_sequencias_aleatorias(int $semente): void
    {
        $this->semente = $semente;
        mt_srand($semente);

        // Começa num dia diferente por semente: o ciclo do cartão, o vencimento das
        // contas fixas e o mês do dashboard caem em pontos diferentes.
        $this->travelTo(CarbonImmutable::parse('2026-01-05 10:00:00')->addDays(($semente * 53) % 330));

        $this->montarFamilia();
        $this->conferirTudo($this->disponiveisReais());

        $total = (int) (getenv('INVARIANTES_OPERACOES') ?: 50);
        for ($i = 1; $i <= $total; $i++) {
            $this->passoAtual = $i;
            $this->passo();
        }
    }

    protected function tearDown(): void
    {
        // O PHPUnit guarda o objeto de cada caso até o fim da suíte: sem isto, cada
        // semente deixaria os models, o livro e o log presos na memória.
        $this->contas = [];
        $this->saldo = [];
        $this->divida = [];
        $this->guardado = [];
        $this->resgatesOrfaos = [];
        $this->editadas = [];
        $this->log = [];
        $this->ultimoEnvio = null;

        // A semente fixa não vaza para os testes seguintes da suíte.
        mt_srand();

        parent::tearDown();
    }

    // ================================================================= montagem

    private function montarFamilia(): void
    {
        $this->titular = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
        $this->dependente = User::factory()->create(['is_admin' => false, 'account_owner_id' => $this->titular->id]);

        $this->catDespesa = Category::factory()->for($this->titular)->expense()->create(['name' => 'Mercado'])->id;
        $this->catReceita = Category::factory()->for($this->titular)->income()->create(['name' => 'Salário'])->id;

        $fechamento = mt_rand(1, 28);
        $vencimento = mt_rand(1, 28);

        $this->contas['corrente'] = Account::factory()->for($this->titular)->create([
            'name' => 'Corrente', 'type' => 'checking', 'bank' => 'itau',
            'initial_balance' => [0, 1500, 3000][mt_rand(0, 2)],
            'overdraft_limit' => [0, 500, 1500][mt_rand(0, 2)],
        ]);
        $this->contas['poupanca'] = Account::factory()->for($this->titular)->create([
            'name' => 'Poupança', 'type' => 'savings', 'bank' => 'caixa', 'initial_balance' => [0, 800, 2000][mt_rand(0, 2)],
        ]);
        $this->contas['cartao'] = Account::factory()->for($this->titular)->create([
            'name' => 'Cartão', 'type' => 'credit_card', 'bank' => 'nubank', 'initial_balance' => null,
            'credit_limit' => [1500, 4000][mt_rand(0, 1)], 'closing_day' => $fechamento, 'due_day' => $vencimento,
        ]);
        $this->contas['debito'] = Account::factory()->for($this->titular)->create([
            'name' => 'Débito', 'type' => 'debit_card', 'bank' => 'itau', 'initial_balance' => null,
            'checking_account_id' => $this->contas['corrente']->id,
            'savings_account_id' => $this->contas['poupanca']->id,
        ]);
        $this->contas['pix'] = Account::factory()->for($this->titular)->create([
            'name' => 'Pix', 'type' => 'pix', 'bank' => 'caixa', 'initial_balance' => null,
            'savings_account_id' => $this->contas['poupanca']->id,
        ]);

        foreach (['corrente', 'poupanca'] as $nome) {
            $this->saldo[$this->contas[$nome]->id] = (float) $this->contas[$nome]->initial_balance;
        }
        $this->divida[$this->contas['cartao']->id] = 0.0;

        // Uma receita inicial para as contas terem com o que brincar.
        $this->lancarDireto('income', 2500, $this->contas['corrente']->id);
        $this->lancarDireto('income', 1000, $this->contas['poupanca']->id);

        // Meta e dois investimentos, pelas rotas (o aporte inicial passa pela trava).
        $this->http('POST', route('metas.store'), [
            'name' => 'Viagem', 'target_amount' => '5.000,00', 'emoji' => '✈️', 'color' => '#1C9A70',
        ], $this->titular)->assertRedirect();
        $meta = Goal::firstOrFail();
        $this->guardado['meta:'.$meta->id] = [];

        foreach ([['CDB', 'corrente', 700], ['Tesouro', 'poupanca', 400]] as [$nome, $conta, $valor]) {
            $this->http('POST', route('investimentos.store'), [
                'name' => $nome, 'classe' => 'renda_fixa', 'indexador' => 'CDI', 'taxa' => '100',
                'valor_inicial' => $this->brl($valor), 'account_id' => $this->contas[$conta]->id,
            ], $this->titular)->assertRedirect();
            $inv = Investment::where('name', $nome)->firstOrFail();
            $this->guardado['inv:'.$inv->id] = [$this->contas[$conta]->id => (float) $valor];
        }

        $this->http('POST', route('metas.aportes.store', $meta), [
            'amount' => '300,00', 'account_id' => $this->contas['poupanca']->id,
        ], $this->titular)->assertRedirect();
        $this->guardado['meta:'.$meta->id][$this->contas['poupanca']->id] = 300.0;

        // Duas contas fixas começando no passado: nascem competências vencidas.
        $hoje = CarbonImmutable::today();
        foreach ([['Aluguel', 'corrente', 900], ['Streaming', 'cartao', 45]] as [$nome, $conta, $valor]) {
            $this->http('POST', route('contas-fixas.store'), [
                'name' => $nome, 'amount' => $this->brl($valor), 'due_day' => mt_rand(1, 31),
                'account_id' => $this->contas[$conta]->id, 'category_id' => $this->catDespesa,
                'starts_on' => $hoje->subDays(mt_rand(20, 75))->toDateString(),
            ], $this->titular)->assertRedirect();
        }

        $this->log[] = 'montagem: corrente '.$this->contas['corrente']->initial_balance
            .' (cheque '.$this->contas['corrente']->overdraft_limit.'), poupança '.$this->contas['poupanca']->initial_balance
            .', cartão limite '.$this->contas['cartao']->credit_limit.' fecha '.$fechamento.' vence '.$vencimento
            .', hoje '.$hoje->toDateString();
    }

    private function lancarDireto(string $tipo, float $valor, int $conta): void
    {
        $this->http('POST', route('transactions.store'), [
            'type' => $tipo, 'amount' => $this->brl($valor), 'account_id' => $conta,
            'date' => CarbonImmutable::today()->toDateString(),
            'category_id' => $tipo === 'income' ? $this->catReceita : $this->catDespesa,
        ], $this->titular)->assertCreated();
        $this->saldo[$conta] += $tipo === 'income' ? $valor : -$valor;
    }

    // ================================================================= o passo

    private function passo(): void
    {
        $disponivelAntes = $this->disponiveisReais();
        $retratoAntes = $this->retrato();
        $this->obrigacoes = [];
        $this->descricao = '';

        $operacoes = [
            'Receita' => 9, 'Despesa' => 13, 'Transferencia' => 6, 'LancarNaFatura' => 10,
            'PagarFatura' => 8, 'EstornarFatura' => 3, 'QuitarPeloCredito' => 2, 'DesfazerQuitacao' => 2,
            'Excluir' => 8, 'ExcluirCompra' => 4, 'Editar' => 10, 'Aporte' => 4, 'Resgate' => 4,
            'PagarContaFixa' => 5, 'Recorrente' => 3, 'ExcluirCofrinho' => 1, 'Relogio' => 7,
            'EditarConta' => 3, 'EditarCartao' => 1, 'Reenvio' => 4,
        ];
        $op = $this->sortearPeso($operacoes);

        // O reenvio só repete o lançamento da operação ANTERIOR (o relógio pode andar no
        // meio, como na fila offline): depois de uma exclusão, reenviar recriaria a linha
        // — o que é certo, mas não é o que este passo confere.
        $envioAnterior = $this->ultimoEnvio;
        $this->ultimoEnvio = null;
        if ($op === 'Reenvio' || $op === 'Relogio') {
            $this->ultimoEnvio = $envioAnterior;
        }

        $desfecho = $this->{'op'.$op}();

        $this->log[] = '#'.$this->passoAtual.' '.$op.': '.$this->descricao.' → '.$desfecho;

        if ($desfecho === 'recusada') {
            $this->assertSame($retratoAntes, $this->retrato(), $this->contexto('operação RECUSADA deixou rastro no banco'));
        }

        $this->conferirTudo($disponivelAntes);
    }

    // ================================================================= operações

    private function opReceita(): string
    {
        $noCartao = mt_rand(1, 6) === 1;
        $conta = $noCartao ? $this->contas['cartao']->id : $this->contaDeCaixa();
        $valor = $this->valor();
        $uuid = (string) Str::uuid();
        $dados = [
            'type' => 'income', 'amount' => $this->brl($valor), 'account_id' => $conta,
            'date' => $this->data(), 'category_id' => mt_rand(0, 1) ? $this->catReceita : null,
            'made_by_user_id' => $this->membro()->id, 'client_uuid' => $uuid, 'description' => 'receita',
        ];
        $this->descricao = ($noCartao ? 'estorno no cartão ' : 'receita em #'.$conta.' ').$this->brl($valor).' em '.$dados['date'];

        $r = $this->http('POST', route('transactions.store'), $dados);
        $this->assertSame(201, $r->status(), $this->contexto('receita válida não foi criada: '.$r->getContent()));

        if ($noCartao) {
            $this->divida[$conta] -= $valor;
        } else {
            $this->saldo[$conta] += $valor;
        }

        // Reenvio da fila offline com o mesmo uuid: nada muda.
        if (mt_rand(1, 4) === 1) {
            $antes = $this->retrato();
            $this->assertSame(200, $this->http('POST', route('transactions.store'), $dados)->status(), $this->contexto('reenvio da receita'));
            $this->assertSame($antes, $this->retrato(), $this->contexto('reenvio com o mesmo client_uuid gravou de novo'));
        }

        return 'ok';
    }

    private function opDespesa(): string
    {
        $opcao = $this->opcaoDePagamento();
        $conta = (int) $opcao->id;
        $valor = $opcao->isCard ? $this->valor($this->limiteLivreSombra($conta)) : $this->valor($this->disponivelSombra($conta));
        $dados = [
            'type' => 'expense', 'amount' => $this->brl($valor), 'account_id' => $conta,
            'date' => $this->data(), 'category_id' => mt_rand(0, 3) ? $this->catDespesa : null,
            'made_by_user_id' => $this->membro()->id, 'client_uuid' => (string) Str::uuid(), 'description' => 'despesa',
        ];
        $this->descricao = 'despesa '.$this->brl($valor).' por '.$opcao->name.' (#'.$conta.') em '.$dados['date'];

        return $this->gastar('POST', route('transactions.store'), $dados, $conta, $valor);
    }

    private function opTransferencia(): string
    {
        $origem = $this->contaDeCaixa();
        $destino = $origem === $this->contas['corrente']->id ? $this->contas['poupanca']->id : $this->contas['corrente']->id;
        $valor = $this->valor($this->disponivelSombra($origem));
        $dados = [
            'amount' => $this->brl($valor), 'account_id' => $origem, 'to_account_id' => $destino,
            'date' => $this->data(), 'client_uuid' => (string) Str::uuid(), 'made_by_user_id' => $this->membro()->id,
        ];
        // Metade pela rota própria, metade pelo caminho da fila offline (type=transfer).
        $pelaFila = mt_rand(0, 1) === 1;
        $url = $pelaFila ? route('transactions.store') : route('transactions.transfer');
        if ($pelaFila) {
            $dados['type'] = 'transfer';
        }
        $this->descricao = 'transferência '.$this->brl($valor).' #'.$origem.' → #'.$destino.' em '.$dados['date'];

        return $this->gastar('POST', $url, $dados, $origem, $valor, depois: function () use ($destino, $valor) {
            $this->saldo[$destino] += $valor;
        });
    }

    private function opLancarNaFatura(): string
    {
        $opcao = $this->opcaoDePagamento();
        $conta = (int) $opcao->id;
        $modo = $opcao->isCard ? ['avista', 'parcelado', 'recorrente'][mt_rand(0, 2)] : 'avista';
        $parcelas = $modo === 'parcelado' ? mt_rand(2, 12) : null;
        $valor = $opcao->isCard ? $this->valor($this->limiteLivreSombra($conta)) : $this->valor($this->disponivelSombra($conta));
        if ($parcelas) {
            $valor = max($valor, $parcelas / 100);
        }

        $dados = array_filter([
            'description' => 'compra '.$modo, 'amount' => $this->brl($valor), 'date' => $this->data(),
            'account_id' => $conta, 'category_id' => $this->catDespesa, 'mode' => $modo,
            'installments' => $parcelas, 'made_by_user_id' => $this->membro()->id,
            'client_uuid' => (string) Str::uuid(),
        ], fn ($v) => $v !== null);
        $this->descricao = 'lançar '.$modo.($parcelas ? ' '.$parcelas.'x' : '').' '.$this->brl($valor)
            .' por '.$opcao->name.' (#'.$conta.') em '.$dados['date'];

        return $this->gastar('POST', route('faturas.lancar'), $dados, $conta, $valor);
    }

    private function opPagarFatura(): string
    {
        $cartao = $this->contas['cartao'];
        $info = $this->cardInfo();
        $opcoes = [];
        if ($info['canPay']) {
            $opcoes[] = ['aberto', (float) $info['invoiceDue']];
        }
        if ($info['closedInvoice']) {
            $opcoes[] = ['fechado', (float) $info['closedInvoice']['valor']];
        }
        if (! $opcoes) {
            $this->descricao = 'nada a pagar no cartão';

            return 'nada';
        }

        [$ciclo, $mostrado] = $opcoes[mt_rand(0, count($opcoes) - 1)];
        $caixa = $this->contaDeCaixa();
        $pagoEm = CarbonImmutable::today();
        $this->assertTrue(! $info['payFloor'] || $info['payFloor'] <= $pagoEm->toDateString(),
            $this->contexto('/faturas: piso da data do pagamento depois de hoje — nenhuma data seria aceita'));
        if ($info['payFloor'] && mt_rand(1, 3) === 1) {
            $piso = CarbonImmutable::parse($info['payFloor']);
            $pagoEm = $piso->greaterThan($pagoEm) ? $pagoEm : $pagoEm->subDays(mt_rand(0, max(0, (int) $piso->diffInDays($pagoEm))));
        }
        $dados = ['pay_account_id' => $caixa, 'ciclo' => $ciclo, 'paid_on' => $pagoEm->toDateString()];
        $this->descricao = 'pagar fatura '.$ciclo.' (mostrada '.$this->brl($mostrado).') pela #'.$caixa.' em '.$pagoEm->toDateString();
        $ultimoId = (int) Transaction::max('id');

        return $this->gastar('POST', route('faturas.fatura.pagar', $cartao), $dados, $caixa, $mostrado, obrigacao: true,
            depois: function () use ($ultimoId, $cartao, $mostrado) {
                $quitacao = Transaction::where('id', '>', $ultimoId)->where('settles_account_id', $cartao->id)->first();
                $this->assertNotNull($quitacao, $this->contexto('pagamento aceito sem quitação gravada'));
                $this->assertEqualsWithDelta($mostrado, (float) $quitacao->amount, self::EPS,
                    $this->contexto('a fatura cobrou valor diferente do mostrado na tela'));
                $this->divida[$cartao->id] -= (float) $quitacao->amount;
            });
    }

    private function opEstornarFatura(): string
    {
        $info = $this->cardInfo();
        /** @var Transaction|null $quitacao */
        $quitacao = $info['settlement'];
        if (! $quitacao) {
            $this->descricao = 'sem pagamento para estornar';

            return 'nada';
        }

        $valor = (float) $quitacao->amount;
        $caixa = (int) $quitacao->account_id;
        $resgates = $this->resgatesLigados([$quitacao->id]);
        $linhas = Transaction::where('settled_by_id', $quitacao->id)->get();
        $this->descricao = 'estornar pagamento #'.$quitacao->id.' ('.$this->brl($valor).', '.$linhas->count().' linhas) da #'.$caixa;

        // Desfazer um pagamento feito errado não pode ser bloqueado (decisão de 24/09/2026,
        // TirarDinheiroDaContaRespeitaOPisoTest): devolvendo um resgate que cobriu o vermelho
        // de antes, ele pode deixar a conta abaixo do piso — como uma obrigação vencida.
        $this->obrigacoes[] = $caixa;

        $r = $this->http('DELETE', route('faturas.fatura.estornar', $quitacao));
        if ($this->classificar($r) !== 'ok') {
            return 'recusada';
        }

        $this->saldo[$caixa] += $valor;
        $this->devolverResgates($resgates);
        $this->divida[$this->contas['cartao']->id] += $this->somaComSinal($linhas);

        return 'ok';
    }

    private function opQuitarPeloCredito(): string
    {
        $info = $this->cardInfo();
        if ($info['estado'] !== 'coberta') {
            $this->descricao = 'fatura não está coberta ('.($info['estado'] ?? 'null').')';

            return 'nada';
        }
        $this->descricao = 'quitar pelo crédito';

        $r = $this->http('POST', route('faturas.fatura.quitar-pelo-credito', $this->contas['cartao']->id));

        return $this->classificar($r) === 'ok' ? 'ok' : 'recusada';
    }

    private function opDesfazerQuitacao(): string
    {
        $info = $this->cardInfo();
        $quitacao = $info['quitacaoPeloCredito'];
        if (! $quitacao) {
            $this->descricao = 'sem quitação pelo crédito';

            return 'nada';
        }
        $this->descricao = 'desfazer quitação pelo crédito #'.$quitacao->id;

        $r = $this->http('DELETE', route('faturas.fatura.desfazer-quitacao', $quitacao->id));

        return $this->classificar($r) === 'ok' ? 'ok' : 'recusada';
    }

    private function opExcluir(): string
    {
        $linha = $this->transacaoAleatoria();
        if (! $linha) {
            $this->descricao = 'nada para excluir';

            return 'nada';
        }

        $alvo = $linha->isTransferencia()
            ? Transaction::where('transfer_group_id', $linha->transfer_group_id)->get()
            : collect([$linha]);
        $resgates = $this->resgatesLigados($alvo->pluck('id')->all());
        $encerrar = $linha->isOcorrenciaRecorrente() && mt_rand(0, 1) === 1;
        $this->descricao = 'excluir pelo Histórico '.$this->rotulo($linha).($encerrar ? ' (encerrando a recorrência)' : '');

        $r = $this->http('DELETE', route('transactions.destroy', $linha), $encerrar ? ['encerrar_recorrencia' => '1'] : []);
        if ($this->classificar($r) !== 'ok') {
            return 'recusada';
        }

        $this->assertSame(0, Transaction::whereIn('id', $alvo->pluck('id'))->count(), $this->contexto('exclusão aceita não apagou'));
        foreach ($alvo as $l) {
            $this->desfazerLinhaNaSombra($l);
        }
        $this->devolverResgates($resgates);

        return 'ok';
    }

    private function opExcluirCompra(): string
    {
        $dados = app(FaturaService::class)->build($this->titular->id);
        $candidatas = collect($dados['cards'])->flatMap(fn ($c) => $c['items'])->concat($dados['accountExpenses'])->values();
        if ($candidatas->isEmpty()) {
            $this->descricao = 'nada na tela de Pagar despesas';

            return 'nada';
        }

        /** @var Transaction $linha */
        $linha = $candidatas[mt_rand(0, $candidatas->count() - 1)];
        $alvo = $linha->group_id
            ? Transaction::where('group_id', $linha->group_id)->whereNull('paid_at')->get()
            : collect([$linha]);
        $resgates = $this->resgatesLigados($alvo->pluck('id')->all());
        $this->descricao = 'excluir em Pagar despesas '.$this->rotulo($linha).' ('.$alvo->count().' linhas em aberto)';

        $r = $this->http('DELETE', route('faturas.compra.destroy', $linha));
        if ($this->classificar($r) !== 'ok') {
            return 'recusada';
        }

        $this->assertSame(0, Transaction::whereIn('id', $alvo->pluck('id'))->count(), $this->contexto('exclusão aceita não apagou'));
        foreach ($alvo as $l) {
            $this->desfazerLinhaNaSombra($l);
        }
        $this->devolverResgates($resgates);

        return 'ok';
    }

    private function opEditar(): string
    {
        $linha = $this->transacaoAleatoria();
        if (! $linha) {
            $this->descricao = 'nada para editar';

            return 'nada';
        }

        $novo = [
            'type' => $linha->type,
            'amount' => $this->brl((float) $linha->amount),
            'account_id' => $linha->account_id,
            'category_id' => $linha->category_id,
            'date' => $linha->date->toDateString(),
            'description' => (string) $linha->description,
            'made_by_user_id' => $linha->made_by_user_id,
        ];

        $mudancas = [];
        foreach (range(1, mt_rand(1, 2)) as $_) {
            switch (mt_rand(1, 6)) {
                case 1:
                case 2:
                    $novo['amount'] = $this->brl($this->valor((float) $linha->amount));
                    $mudancas[] = 'valor '.$novo['amount'];
                    break;
                case 3:
                    $novo['date'] = $this->data();
                    $mudancas[] = 'data '.$novo['date'];
                    break;
                case 4:
                    $novo['description'] = 'editada '.mt_rand(1, 999);
                    $mudancas[] = 'descrição';
                    break;
                case 5:
                    $novo['account_id'] = (int) $this->opcaoDePagamento()->id;
                    $mudancas[] = 'conta #'.$novo['account_id'];
                    break;
                case 6:
                    $novo['type'] = $novo['type'] === 'income' ? 'expense' : 'income';
                    $mudancas[] = 'tipo '.$novo['type'];
                    break;
            }
        }
        if ($linha->isTransferencia()) {
            $novo['category_id'] = null;
        } elseif ($novo['type'] !== $linha->type || $novo['category_id'] === null) {
            $novo['category_id'] = $novo['type'] === 'income' ? $this->catReceita : $this->catDespesa;
        }

        $antes = $linha->replicate()->forceFill(['id' => $linha->id]);
        $resgatesAntes = $this->resgatesLigados([$linha->id]);
        $this->descricao = 'editar '.$this->rotulo($linha).': '.implode(', ', $mudancas);

        $mexeNoDinheiro = $novo['type'] !== $linha->type
            || (int) $novo['account_id'] !== (int) $linha->account_id
            || abs($this->lerValor($novo['amount']) - (float) $linha->amount) > 0.001;

        // Disponível que a conta NOVA terá quando a linha antiga (e o resgate dela)
        // deixar de contar — é contra ele que o resgate da edição é calculado.
        $sombra = [$this->saldo, $this->divida, $this->guardado];
        if ($mexeNoDinheiro) {
            $this->desfazerLinhaNaSombra($antes);
            $this->devolverResgates($resgatesAntes);
        }
        $dispNaNova = $this->disponivelSombra((int) $novo['account_id']);
        [$this->saldo, $this->divida, $this->guardado] = $sombra;

        [$desfecho, $escolha] = $this->comFonte('PUT', route('transactions.update', $linha), $novo, null);
        if ($desfecho !== 'ok') {
            return 'recusada';
        }

        $depois = $linha->fresh();
        $resgatesDepois = $this->resgatesLigados([$linha->id]);
        $this->editadas[$linha->id] = true;

        if (! $mexeNoDinheiro) {
            $this->assertEquals($this->totaisDeResgate($resgatesAntes), $this->totaisDeResgate($resgatesDepois),
                $this->contexto('edição NEUTRA mexeu no resgate ligado'));

            return 'ok';
        }

        $this->desfazerLinhaNaSombra($antes);
        $this->devolverResgates($resgatesAntes);
        $this->aplicarLinhaNaSombra($depois);

        $esperado = 0.0;
        if ($escolha && $escolha['funding_source'] === FundingSource::RESGATE_INVESTIMENTO) {
            $esperado = round((float) $depois->amount - $dispNaNova, 2);
            $this->guardado['inv:'.$escolha['funding_investment_id']][(int) $depois->account_id] =
                ($this->guardado['inv:'.$escolha['funding_investment_id']][(int) $depois->account_id] ?? 0.0) - $esperado;
        }
        $this->assertEqualsWithDelta($esperado, array_sum(array_map('array_sum', $this->totaisDeResgate($resgatesDepois))), self::EPS,
            $this->contexto('resgate da edição diferente do faltante (valor − disponível)'));

        return 'ok';
    }

    private function opAporte(): string
    {
        [$chave, $pai] = $this->cofrinhoAleatorio();
        if (! $pai) {
            $this->descricao = 'sem cofrinho';

            return 'nada';
        }
        $conta = $this->contaDeCaixa();
        $disp = $this->disponivelSombra($conta);
        $valor = $this->valor(max(0.0, $disp));
        $this->descricao = 'aporte '.$this->brl($valor).' em '.$chave.' a partir da #'.$conta.' (disponível '.$this->brl($disp).')';

        $rota = str_starts_with($chave, 'meta:') ? 'metas.aportes.store' : 'investimentos.aportes.store';
        $envio = ['POST', route($rota, $pai), [
            'amount' => $this->brl($valor), 'account_id' => $conta, 'date' => $this->data(permiteFuturo: false),
            'made_by_user_id' => $this->membro()->id, 'client_uuid' => (string) Str::uuid(),
        ]];
        $r = $this->http(...$envio);
        $desfecho = $this->classificar($r);
        $previsto = $valor <= $disp + 0.001 ? 'ok' : 'recusa';
        $this->assertSame($previsto, $desfecho, $this->contexto('aporte: o servidor decidiu diferente do disponível'));

        if ($desfecho !== 'ok') {
            return 'recusada';
        }
        $this->ultimoEnvio = $envio;
        $this->guardado[$chave][$conta] = ($this->guardado[$chave][$conta] ?? 0.0) + $valor;

        return 'ok';
    }

    private function opResgate(): string
    {
        [$chave, $pai] = $this->cofrinhoAleatorio();
        if (! $pai) {
            $this->descricao = 'sem cofrinho';

            return 'nada';
        }
        $conta = $this->contaDeCaixa();
        $guardado = round($this->guardado[$chave][$conta] ?? 0.0, 2);
        $valor = $this->valor(max(0.0, $guardado));
        $this->descricao = 'resgate '.$this->brl($valor).' de '.$chave.' para a #'.$conta.' (guardado '.$this->brl($guardado).')';

        $rota = str_starts_with($chave, 'meta:') ? 'metas.resgates.store' : 'investimentos.resgates.store';
        $envio = ['POST', route($rota, $pai), [
            'amount' => $this->brl($valor), 'account_id' => $conta, 'date' => $this->data(permiteFuturo: false),
            'client_uuid' => (string) Str::uuid(),
        ]];
        $r = $this->http(...$envio);
        $desfecho = $this->classificar($r);
        $previsto = $valor <= $guardado + 0.001 ? 'ok' : 'recusa';
        $this->assertSame($previsto, $desfecho, $this->contexto('resgate: o servidor decidiu diferente do guardado'));

        if ($desfecho !== 'ok') {
            return 'recusada';
        }
        $this->ultimoEnvio = $envio;
        $this->guardado[$chave][$conta] -= $valor;

        return 'ok';
    }

    private function opPagarContaFixa(): string
    {
        $abertas = app(FixedBillService::class)->currentAndOverdue($this->titular->id)
            ->reject(fn ($o) => $o['paga'])->values();
        if ($abertas->isEmpty()) {
            $this->descricao = 'nenhuma conta fixa em aberto';

            return 'nada';
        }

        $o = $abertas[mt_rand(0, $abertas->count() - 1)];
        /** @var FixedBill $bill */
        $bill = $o['bill'];
        $opcao = $this->opcaoDePagamento();
        $conta = (int) $opcao->id;
        $valor = round((float) $o['valor'] * [1, 1, 0.8, 1.3, 2.5][mt_rand(0, 4)], 2);
        $vencida = $o['vencimento']->lessThanOrEqualTo(CarbonImmutable::today());
        $dados = [
            'account_id' => $conta, 'amount' => $this->brl($valor),
            'paid_on' => CarbonImmutable::today()->toDateString(),
        ];
        $this->descricao = 'pagar conta fixa '.$bill->name.' '.$o['competence']->format('Y-m').' (vence '
            .$o['vencimento']->toDateString().($vencida ? ', vencida' : '').') '.$this->brl($valor).' por '.$opcao->name;

        return $this->gastar('POST', route('contas-fixas.pagar', [$bill, $o['competence']->format('Y-m')]), $dados, $conta, $valor,
            obrigacao: $vencida);
    }

    private function opRecorrente(): string
    {
        $info = $this->cardInfo();
        $candidatas = collect($info['recorrenciasParaAvancar'])
            ->concat(collect($info['items'])->filter(fn ($t) => isset($info['lancarProxima'][$t->id])))
            ->values();
        if ($candidatas->isEmpty()) {
            $this->descricao = 'nenhuma recorrência para avançar';

            return 'nada';
        }

        /** @var Transaction $ocorrencia */
        $ocorrencia = $candidatas[mt_rand(0, $candidatas->count() - 1)];
        $antes = Transaction::where('group_id', $ocorrencia->group_id)->count();
        $this->descricao = 'avançar recorrência '.$this->rotulo($ocorrencia);

        $r = $this->http('POST', route('faturas.recorrente.pagar', $ocorrencia));
        $desfecho = $this->classificar($r);
        if ($desfecho !== 'ok') {
            return 'recusada';
        }

        $depois = Transaction::where('group_id', $ocorrencia->group_id)->count();
        $this->assertLessThanOrEqual($antes + 1, $depois, $this->contexto('um clique gerou mais de uma ocorrência'));
        if ($depois === $antes + 1) {
            $this->divida[$this->contas['cartao']->id] += (float) $ocorrencia->amount;
        }

        return 'ok';
    }

    private function opExcluirCofrinho(): string
    {
        [$chave, $pai] = $this->cofrinhoAleatorio();
        if (! $pai) {
            $this->descricao = 'sem cofrinho';

            return 'nada';
        }
        $this->descricao = 'excluir '.$chave;
        $ligadas = $pai instanceof Investment
            ? InvestmentContribution::where('investment_id', $pai->id)->whereNotNull('transaction_id')->pluck('transaction_id')->all()
            : [];

        $rota = $pai instanceof Goal ? 'metas.destroy' : 'investimentos.destroy';
        $r = $this->http('DELETE', route($rota, $pai));
        if ($this->classificar($r) !== 'ok') {
            return 'recusada';
        }

        unset($this->guardado[$chave]);
        foreach ($ligadas as $id) {
            $this->resgatesOrfaos[(int) $id] = true;
        }

        return 'ok';
    }

    private function opRelogio(): string
    {
        // Às vezes um salto longo (vários ciclos e competências de uma vez), e a hora
        // muda — perto da meia-noite também, onde data e instante discordam.
        $dias = mt_rand(1, 8) === 1 ? mt_rand(30, 75) : mt_rand(1, 20);
        [$hora, $minuto] = [[10, 0], [0, 5], [23, 55], [mt_rand(0, 23), mt_rand(0, 59)]][mt_rand(0, 3)];
        $this->travelTo(now()->addDays($dias)->setTime($hora, $minuto));
        $this->descricao = '+'.$dias.' dias → '.now()->format('Y-m-d H:i');

        return 'ok';
    }

    private function opEditarConta(): string
    {
        $nome = mt_rand(0, 2) ? 'corrente' : 'poupanca';
        $conta = Account::findOrFail($this->contas[$nome]->id);
        $inicial = round((float) $conta->initial_balance, 2);
        $limite = round((float) $conta->overdraft_limit, 2);

        $novoInicial = match (mt_rand(1, 4)) {
            1 => $inicial,
            2 => round($inicial + mt_rand(1, 100000) / 100, 2),
            3 => max(0.0, round($inicial - mt_rand(1, 200000) / 100, 2)),
            default => 0.0,
        };
        $novoLimite = $nome === 'corrente' ? (float) [$limite, 0, 500, 1500, mt_rand(0, 300000) / 100][mt_rand(0, 4)] : 0.0;

        // As duas regras do UpdateAccountRequest, pelo livro: o disponível que valerá
        // depois da edição contra o limite que valerá depois dela.
        $projetado = round($this->disponivelSombra($conta->id) - $inicial + $novoInicial, 2);
        $okInicial = $novoInicial + 0.001 >= $inicial || $projetado + 0.001 >= -$novoLimite;
        $usado = max(0.0, -$projetado);
        $okLimite = $nome !== 'corrente' || $usado <= 0.001 || $novoLimite >= $limite || $novoLimite + 0.001 >= $usado;
        $previsto = $okInicial && $okLimite ? 'ok' : 'recusa';

        $this->descricao = 'editar '.$nome.': saldo inicial '.$this->brl($inicial).' → '.$this->brl($novoInicial)
            .($nome === 'corrente' ? ', cheque '.$this->brl($limite).' → '.$this->brl($novoLimite) : '');

        $r = $this->http('PATCH', route('accounts.update', $conta), [
            'name' => $conta->name, 'type' => $conta->type, 'bank' => $conta->bank,
            'initial_balance' => $this->brl($novoInicial), 'overdraft_limit' => $this->brl($novoLimite),
        ]);
        $desfecho = $this->classificar($r);
        $this->assertSame($previsto, $desfecho, $this->contexto('editar conta: o servidor decidiu diferente das regras do piso'));

        if ($desfecho !== 'ok') {
            return 'recusada';
        }
        $this->saldo[$conta->id] += $novoInicial - $inicial;

        return 'ok';
    }

    private function opEditarCartao(): string
    {
        $cartao = Account::findOrFail($this->contas['cartao']->id);
        $novo = [1000, 1500, 3000, 5000][mt_rand(0, 3)];
        $this->descricao = 'limite do cartão '.$cartao->credit_limit.' → '.$novo;

        $r = $this->http('PATCH', route('accounts.update', $cartao), [
            'name' => $cartao->name, 'type' => 'credit_card', 'bank' => $cartao->bank,
            'credit_limit' => $this->brl($novo), 'closing_day' => $cartao->closing_day, 'due_day' => $cartao->due_day,
        ]);

        return $this->classificar($r) === 'ok' ? 'ok' : 'recusada';
    }

    /**
     * Reenvio do último lançamento aceito (fila offline, duplo clique, "voltar"):
     * mesmo payload, mesmo client_uuid (ou a mesma competência). Nada pode mudar.
     */
    private function opReenvio(): string
    {
        if (! $this->ultimoEnvio) {
            $this->descricao = 'nada a reenviar';

            return 'nada';
        }

        [$metodo, $url, $dados] = $this->ultimoEnvio;
        $this->descricao = 'reenvio de '.$metodo.' '.parse_url($url, PHP_URL_PATH);
        $antes = $this->retrato();

        $r = $this->http($metodo, $url, $dados);
        $this->assertNotSame(409, $r->status(), $this->contexto('reenvio perguntou a fonte de novo'));
        $this->assertSame($antes, $this->retrato(), $this->contexto('REENVIO gravou de novo'));

        return 'ok';
    }

    // ================================================================= gasto (409)

    /**
     * Um gasto pela rota: prevê pelo livro-sombra se o servidor deve aceitar, perguntar
     * a fonte ou recusar; responde o 409 como o modal; e, aceito, lança na sombra —
     * com o resgate calculado AQUI (valor − disponível), nunca lido do servidor.
     */
    private function gastar(string $metodo, string $url, array $dados, int $conta, float $valor,
        bool $obrigacao = false, ?\Closure $depois = null): string
    {
        $ehCartao = $conta === $this->contas['cartao']->id;
        $disp = $ehCartao ? 0.0 : $this->disponivelSombra($conta);
        $previsto = $ehCartao
            ? ($valor <= $this->limiteLivreSombra($conta) + 0.001 ? 'ok' : 'recusa')
            : $this->preverGasto($conta, $valor, $obrigacao);

        // Entre aprovar a fonte e gravar, a conta pode mudar (outra pessoa da família
        // lança, o lançamento dorme na fila offline): às vezes, isso acontece aqui.
        $intervalo = $ehCartao ? null : fn () => $this->mexerNaContaEntreAprovarEGravar($conta);

        [$desfecho, $escolha, $primeira] = $this->comFonte($metodo, $url, $dados, $previsto, $intervalo);

        if ($primeira->status() === 409) {
            $this->assertEqualsWithDelta(round($valor - $disp, 2), (float) $primeira->json('fonte.faltante'), self::EPS,
                $this->contexto('409: o faltante oferecido não é valor − disponível'));
        }

        if ($desfecho !== 'ok') {
            return $desfecho;
        }

        // O resgate é calculado com o disponível do envio que GRAVOU (o intervalo pode
        // tê-lo mudado depois do primeiro 409) — e só existe se, naquele envio, a conta
        // ainda precisava de fonte: se coube no disponível (ou, numa obrigação, se nada
        // mais cobria), a despesa grava sem fonte nenhuma.
        $disp = $ehCartao ? 0.0 : $this->disponivelSombra($conta);
        $usouFonte = ! $ehCartao && $escolha !== null && $this->preverGasto($conta, $valor) === 'fonte';

        if (isset($dados['client_uuid']) || str_contains($url, '/contas-fixas/')) {
            $this->ultimoEnvio = [$metodo, $url, $dados + ($escolha ?? [])];
        }

        if ($ehCartao) {
            $this->divida[$conta] += $valor;
        } else {
            $this->saldo[$conta] -= $valor;
            if ($obrigacao) {
                $this->obrigacoes[] = $conta;
            }
            if ($usouFonte && $escolha['funding_source'] === FundingSource::RESGATE_INVESTIMENTO) {
                $chave = 'inv:'.$escolha['funding_investment_id'];
                $this->guardado[$chave][$conta] = ($this->guardado[$chave][$conta] ?? 0.0) - round($valor - $disp, 2);
            }
        }

        if ($depois) {
            $depois();
        }

        return 'ok';
    }

    /**
     * Envia; no 409 escolhe uma fonte que COBRE (como o modal, que desabilita as
     * outras) — ou desiste — e reenvia o mesmo payload com a escolha.
     *
     * @return array{0: string, 1: ?array, 2: TestResponse}
     */
    private function comFonte(string $metodo, string $url, array $dados, ?string $previsto, ?\Closure $intervalo = null): array
    {
        $r = $this->http($metodo, $url, $dados);
        $desfecho = $this->classificar($r);

        if ($previsto !== null) {
            $this->assertSame($previsto, $desfecho, $this->contexto('o servidor decidiu "'.$desfecho.'" onde o livro previa "'
                .$previsto.'" — '.mb_substr((string) $r->getContent(), 0, 300)));
        }

        if ($desfecho === 'recusa') {
            return ['recusada', null, $r];
        }
        if ($desfecho === 'ok') {
            return ['ok', null, $r];
        }

        $escolha = $this->escolherFonte($r->json('fonte'));
        if ($escolha === null) {
            $this->descricao .= ' [desistiu da fonte]';

            return ['recusada', null, $r];
        }

        // Aprovado no modal; antes de gravar, a conta às vezes muda.
        $mudou = $intervalo !== null && mt_rand(1, 4) === 1 && $intervalo();
        $retratoDoIntervalo = $mudou ? $this->retrato() : null;

        for ($tentativa = 1; ; $tentativa++) {
            $r2 = $this->http($metodo, $url, $dados + $escolha);
            $desfecho2 = $this->classificar($r2);

            if ($desfecho2 === 'ok') {
                return ['ok', $escolha, $r];
            }

            // Só o intervalo explica uma segunda resposta que não seja "gravado".
            $this->assertTrue($mudou, $this->contexto('a fonte escolhida no modal foi recusada: '
                .mb_substr((string) $r2->getContent(), 0, 300).' '.json_encode(session('errors')?->all())));

            if ($desfecho2 === 'recusa' || $tentativa > 2) {
                // O dinheiro que o modal prometia não existe mais: recusa inteira, e o
                // que o intervalo gravou continua lá (ele era outra operação).
                $this->assertSame($retratoDoIntervalo, $this->retrato(), $this->contexto('recusa depois do intervalo deixou rastro'));
                $this->descricao .= ' [recusada depois do intervalo]';

                return ['mudou', null, $r];
            }

            // 409 de novo: o teto aprovado não cobre mais o que falta. As opções vêm
            // recalculadas, e a pessoa decide de novo com o número certo.
            $this->assertTrue(isset($escolha['funding_max_amount']), $this->contexto('409 repetido sem teto que o explique'));
            $escolha = $this->escolherFonte($r2->json('fonte'), desistir: false);
            $this->assertNotNull($escolha, $this->contexto('409 depois do intervalo sem fonte que cubra'));
            $this->descricao .= ' [409 de novo, fonte: '.$escolha['funding_source'].']';
        }
    }

    /**
     * A escolha do modal: uma fonte que COBRE (o modal desabilita as outras), com o
     * teto = o faltante mostrado (o `funding.js` o manda nas duas escolhas). Null = a
     * pessoa desistiu.
     */
    private function escolherFonte(array $fonte, bool $desistir = true): ?array
    {
        $opcoes = [];
        foreach ($fonte['fontes'] as $f) {
            if (! $f['cobre']) {
                continue;
            }
            if ($f['id'] === FundingSource::CHEQUE_ESPECIAL) {
                $opcoes[] = [
                    'funding_source' => FundingSource::CHEQUE_ESPECIAL,
                    'funding_max_amount' => number_format((float) $fonte['faltante'], 2, '.', ''),
                ];
            }
            if ($f['id'] === FundingSource::RESGATE_INVESTIMENTO) {
                foreach ($f['itens'] as $item) {
                    if ($item['cobre']) {
                        $opcoes[] = [
                            'funding_source' => FundingSource::RESGATE_INVESTIMENTO,
                            'funding_investment_id' => $item['id'],
                            'funding_max_amount' => number_format((float) $fonte['faltante'], 2, '.', ''),
                        ];
                    }
                }
            }
        }

        $this->assertNotEmpty($opcoes, $this->contexto('409 sem nenhuma fonte que cubra (beco sem saída)'));

        if ($desistir && mt_rand(1, 6) === 1) {
            return null;
        }

        $escolha = $opcoes[mt_rand(0, count($opcoes) - 1)];
        $this->descricao .= ' [fonte: '.$escolha['funding_source']
            .(isset($escolha['funding_investment_id']) ? ' #'.$escolha['funding_investment_id'] : '').']';

        return $escolha;
    }

    /**
     * Outra operação na mesma conta entre o 409 e o reenvio: uma receita (o
     * disponível sobe) ou uma despesa no limite do cheque especial (desce — é o caso
     * que o teto do resgate existe para barrar). Lança na sombra. Devolve se mexeu.
     */
    private function mexerNaContaEntreAprovarEGravar(int $conta): bool
    {
        $disp = $this->disponivelSombra($conta);

        if (mt_rand(0, 1) === 1) {
            $valor = mt_rand(1, 50000) / 100;
            $r = $this->http('POST', route('transactions.store'), [
                'type' => 'income', 'amount' => $this->brl($valor), 'account_id' => $conta,
                'date' => CarbonImmutable::today()->toDateString(), 'description' => 'no intervalo',
            ]);
            $this->assertSame(201, $r->status(), $this->contexto('receita do intervalo'));
            $this->saldo[$conta] += $valor;
            $this->descricao .= ' {intervalo: receita '.$this->brl($valor).'}';

            return true;
        }

        $limite = Account::findOrFail($conta)->overdraftLimitValue;
        $teto = round(max(0.0, $disp) + max(0.0, $limite - max(0.0, -$disp)), 2);
        if ($teto < 0.01) {
            return false;
        }

        $valor = mt_rand(1, (int) round($teto * 100)) / 100;
        $r = $this->http('POST', route('transactions.store'), [
            'type' => 'expense', 'amount' => $this->brl($valor), 'account_id' => $conta,
            'date' => CarbonImmutable::today()->toDateString(), 'description' => 'no intervalo',
            'funding_source' => FundingSource::CHEQUE_ESPECIAL,
        ]);
        $this->assertSame(201, $r->status(), $this->contexto('despesa do intervalo: '.mb_substr((string) $r->getContent(), 0, 300)));
        $this->saldo[$conta] -= $valor;
        $this->descricao .= ' {intervalo: despesa '.$this->brl($valor).'}';

        return true;
    }

    /** 'ok' | 'fonte' | 'recusa', pelo livro-sombra (a mesma regra do SpendingGuard). */
    private function preverGasto(int $conta, float $valor, bool $obrigacao = false): string
    {
        $disp = $this->disponivelSombra($conta);
        if ($valor <= $disp + 0.001) {
            return 'ok';
        }

        $limite = Account::findOrFail($conta)->overdraftLimitValue;
        $chequeLivre = max(0.0, $limite - max(0.0, -$disp));
        $cobreCheque = $limite > 0 && ($valor - max(0.0, $disp)) <= $chequeLivre + 0.001;

        $maior = 0.0;
        foreach ($this->guardado as $chave => $porConta) {
            if (str_starts_with($chave, 'inv:')) {
                $maior = max($maior, round($porConta[$conta] ?? 0.0, 2));
            }
        }
        $cobreResgate = $maior > 0.001 && ($valor - $disp) <= $maior + 0.001;

        if ($cobreCheque || $cobreResgate) {
            return 'fonte';
        }

        return $obrigacao ? 'ok' : 'recusa';
    }

    // ================================================================= sombra

    private function disponivelSombra(int $conta): float
    {
        if (! array_key_exists($conta, $this->saldo)) {
            return 0.0;
        }

        $reservado = 0.0;
        foreach ($this->guardado as $porConta) {
            $reservado += $porConta[$conta] ?? 0.0;
        }

        return round($this->saldo[$conta] - $reservado, 2);
    }

    private function limiteLivreSombra(int $cartao): float
    {
        return round((float) Account::findOrFail($cartao)->credit_limit - max(0.0, $this->divida[$cartao]), 2);
    }

    /** Tira da sombra o efeito de uma linha (exclusão ou a versão antiga numa edição). */
    private function desfazerLinhaNaSombra(Transaction $linha): void
    {
        $this->efeitoNaSombra($linha, -1);
    }

    private function aplicarLinhaNaSombra(Transaction $linha): void
    {
        $this->efeitoNaSombra($linha, 1);
    }

    private function efeitoNaSombra(Transaction $linha, int $sinal): void
    {
        $conta = (int) $linha->account_id;
        $valor = (float) $linha->amount;

        if (array_key_exists($conta, $this->saldo)) {
            $this->saldo[$conta] += $sinal * ($linha->type === 'income' ? $valor : -$valor);

            return;
        }

        if (array_key_exists($conta, $this->divida) && $linha->paid_at === null) {
            $this->divida[$conta] += $sinal * ($linha->type === 'expense' ? $valor : -$valor);
        }
    }

    /**
     * Resgates ligados a estas transações, por cofrinho e conta.
     *
     * @return list<array{chave: string, conta: int, valor: float, id: int}>
     */
    private function resgatesLigados(array $ids): array
    {
        $out = [];
        foreach (InvestmentContribution::whereIn('transaction_id', $ids)->get() as $c) {
            $out[] = ['chave' => 'inv:'.$c->investment_id, 'conta' => (int) $c->account_id, 'valor' => (float) $c->amount, 'id' => $c->id];
        }
        foreach (GoalContribution::whereIn('transaction_id', $ids)->get() as $c) {
            $out[] = ['chave' => 'meta:'.$c->goal_id, 'conta' => (int) $c->account_id, 'valor' => (float) $c->amount, 'id' => $c->id];
        }

        return $out;
    }

    /** @return array<string, array<int, float>> */
    private function totaisDeResgate(array $resgates): array
    {
        $t = [];
        foreach ($resgates as $r) {
            $t[$r['chave']][$r['conta']] = round(($t[$r['chave']][$r['conta']] ?? 0.0) + $r['valor'], 2);
        }
        ksort($t);

        return $t;
    }

    private function devolverResgates(array $resgates): void
    {
        foreach ($resgates as $r) {
            if (isset($this->guardado[$r['chave']])) {
                $this->guardado[$r['chave']][$r['conta']] = ($this->guardado[$r['chave']][$r['conta']] ?? 0.0) + $r['valor'];
            }
        }
    }

    // ================================================================= invariantes

    /** @param  array<int, float>  $disponivelAntes */
    private function conferirTudo(array $disponivelAntes): void
    {
        $this->conferirLivro();
        $this->conferirPiso($disponivelAntes);
        $this->conferirEstrutura();
        $this->conferirTelas();
    }

    private function conferirLivro(): void
    {
        foreach ($this->saldo as $id => $esperado) {
            $conta = Account::findOrFail($id);
            $this->assertEqualsWithDelta(round($esperado, 2), $conta->balance, self::EPS, $this->contexto('saldo da conta #'.$id));

            $reservado = 0.0;
            foreach ($this->guardado as $porConta) {
                $reservado += $porConta[$id] ?? 0.0;
            }
            $this->assertEqualsWithDelta(round($reservado, 2), $conta->reserved, self::EPS, $this->contexto('reservado da conta #'.$id));
            $this->assertGreaterThanOrEqual(-self::EPS, $conta->reserved, $this->contexto('reservado NEGATIVO na conta #'.$id));
        }

        foreach ($this->divida as $id => $esperado) {
            $cartao = Account::findOrFail($id);
            $this->assertEqualsWithDelta(max(0.0, round($esperado, 2)), $cartao->committed, self::EPS,
                $this->contexto('comprometido do cartão #'.$id));
        }

        // Cada cofrinho existente: por conta, e no total (saved/aplicado).
        foreach (Goal::all() as $meta) {
            $this->conferirCofrinho('meta:'.$meta->id, GoalContribution::where('goal_id', $meta->id), $meta->saved);
        }
        foreach (Investment::all() as $inv) {
            $this->conferirCofrinho('inv:'.$inv->id, InvestmentContribution::where('investment_id', $inv->id), $inv->aplicado);
        }
    }

    private function conferirCofrinho(string $chave, $query, float $total): void
    {
        $porConta = $query->groupBy('account_id')->selectRaw('account_id')
            ->selectRaw("SUM(CASE WHEN type = 'aporte' THEN amount ELSE -amount END) AS liquido")
            ->pluck('liquido', 'account_id');

        $esperado = $this->guardado[$chave] ?? [];
        foreach (array_unique(array_merge(array_keys($esperado), $porConta->keys()->all())) as $conta) {
            $real = round((float) ($porConta[$conta] ?? 0), 2);
            $this->assertEqualsWithDelta(round($esperado[$conta] ?? 0.0, 2), $real, self::EPS,
                $this->contexto($chave.' guardado a partir da conta #'.$conta));
            $this->assertGreaterThanOrEqual(-self::EPS, $real, $this->contexto($chave.' ficou NEGATIVO na conta #'.$conta));
        }
        $this->assertEqualsWithDelta(round(array_sum($esperado), 2), $total, self::EPS, $this->contexto($chave.' total'));
    }

    /** @param  array<int, float>  $antes */
    private function conferirPiso(array $antes): void
    {
        foreach ($this->disponiveisReais() as $id => $depois) {
            $conta = Account::findOrFail($id);
            $piso = -$conta->overdraftLimitValue;
            if ($depois >= $piso - self::EPS || in_array($id, $this->obrigacoes, true)) {
                continue;
            }
            // Abaixo do piso só se a operação NÃO o piorou (ele já vinha de uma obrigação vencida).
            $this->assertGreaterThanOrEqual(($antes[$id] ?? $depois) - self::EPS, $depois, $this->contexto(
                'PISO do cheque especial furado: a conta #'.$id.' foi de '.$this->brl($antes[$id] ?? 0).' para '
                .$this->brl($depois).' com limite '.$this->brl(-$piso)));
        }
    }

    private function conferirEstrutura(): void
    {
        $dono = $this->titular->id;
        $todas = Transaction::where('user_id', $dono)->get()->keyBy('id');
        $caixa = [$this->contas['corrente']->id, $this->contas['poupanca']->id];
        $cartao = $this->contas['cartao']->id;
        $hoje = CarbonImmutable::today()->toDateString();

        foreach ($todas as $t) {
            $this->assertGreaterThan(0, (float) $t->amount, $this->contexto('valor não positivo na #'.$t->id));
            $this->assertContains((int) $t->account_id, array_merge($caixa, [$cartao]),
                $this->contexto('lançamento em método espelho (débito/Pix) #'.$t->id));

            if ((int) $t->account_id === $cartao) {
                $this->assertNull($t->funding_source, $this->contexto('compra no cartão com fonte #'.$t->id));
                if ($t->paid_at !== null) {
                    $this->assertTrue(($t->settled_by_id === null) !== ($t->credit_settlement_id === null),
                        $this->contexto('compra PAGA no cartão sem (ou com duas) quitação #'.$t->id));
                } else {
                    $this->assertNull($t->settled_by_id, $this->contexto('compra em aberto apontando para quitação #'.$t->id));
                    $this->assertNull($t->credit_settlement_id, $this->contexto('compra em aberto apontando para quitação pelo crédito #'.$t->id));
                }
                if ($t->settled_by_id !== null) {
                    $q = $todas->get($t->settled_by_id);
                    $this->assertNotNull($q, $this->contexto('compra #'.$t->id.' aponta para quitação que não existe'));
                    $this->assertSame($cartao, (int) $q->settles_account_id, $this->contexto('quitação de outro cartão #'.$t->id));
                }
                if ($t->credit_settlement_id !== null) {
                    $this->assertTrue(CreditSettlement::whereKey($t->credit_settlement_id)->where('account_id', $cartao)->exists(),
                        $this->contexto('compra #'.$t->id.' aponta para quitação pelo crédito que não existe'));
                }
            }

            if ($t->settles_account_id !== null) {
                $this->assertContains((int) $t->account_id, $caixa, $this->contexto('quitação fora do caixa #'.$t->id));
                $this->assertSame('expense', $t->type, $this->contexto('quitação que não é saída #'.$t->id));
                $linhas = $todas->where('settled_by_id', $t->id);
                $this->assertNotEmpty($linhas, $this->contexto('quitação #'.$t->id.' sem compra nenhuma'));
                $this->assertSame((int) round((float) $t->amount * 100), $this->centavos($linhas),
                    $this->contexto('quitação #'.$t->id.' não vale a soma do que marcou como pago'));
            }

            if ($t->funding_source === FundingSource::RESGATE_INVESTIMENTO && ! isset($this->resgatesOrfaos[$t->id])) {
                $ligados = InvestmentContribution::where('transaction_id', $t->id)->get();
                $this->assertCount(1, $ligados, $this->contexto('despesa financiada por resgate sem o resgate ligado #'.$t->id));
                $this->assertEqualsWithDelta((float) $t->funding_amount, (float) $ligados[0]->amount, self::EPS,
                    $this->contexto('auditoria funding_amount ≠ resgate #'.$t->id));
            }
            if ($t->funding_source === FundingSource::CHEQUE_ESPECIAL) {
                $this->assertSame($this->contas['corrente']->id, (int) $t->account_id, $this->contexto('cheque especial fora da corrente #'.$t->id));
                $this->assertGreaterThan(0, (float) $t->funding_amount, $this->contexto('cheque especial sem valor #'.$t->id));
            }
        }

        // Transferência: sempre em par.
        foreach ($todas->whereNotNull('transfer_group_id')->groupBy('transfer_group_id') as $grupo => $pontas) {
            $this->assertCount(2, $pontas, $this->contexto('transferência '.$grupo.' sem par'));
            $this->assertEqualsCanonicalizing(['expense', 'income'], $pontas->pluck('type')->all(), $this->contexto('pontas '.$grupo));
            $this->assertSame(1, $pontas->pluck('amount')->map(fn ($v) => (string) $v)->unique()->count(), $this->contexto('valores das pontas '.$grupo));
            $this->assertSame(1, $pontas->map(fn ($p) => $p->date->toDateString())->unique()->count(), $this->contexto('datas das pontas '.$grupo));
            $this->assertSame(2, $pontas->pluck('account_id')->unique()->count(), $this->contexto('pontas na mesma conta '.$grupo));
        }

        // Quitação pelo crédito: as linhas dela se anulam.
        foreach (CreditSettlement::where('user_id', $dono)->get() as $cs) {
            $linhas = $todas->where('credit_settlement_id', $cs->id);
            $this->assertNotEmpty($linhas, $this->contexto('quitação pelo crédito #'.$cs->id.' vazia'));
            $this->assertSame(0, $this->centavos($linhas), $this->contexto('quitação pelo crédito #'.$cs->id.' não soma zero'));
        }

        // Resgates ligados a despesas.
        foreach (InvestmentContribution::whereNotNull('transaction_id')->get() as $c) {
            $t = $todas->get($c->transaction_id);
            $this->assertNotNull($t, $this->contexto('resgate #'.$c->id.' ligado a despesa que não existe mais'));
            $this->assertSame('resgate', $c->type, $this->contexto('ligado que não é resgate #'.$c->id));
            $this->assertSame((int) $t->account_id, (int) $c->account_id, $this->contexto('resgate #'.$c->id.' em outra conta'));
            $this->assertSame(FundingSource::RESGATE_INVESTIMENTO, $t->funding_source, $this->contexto('resgate #'.$c->id.' sem auditoria'));
            $this->assertLessThanOrEqual($t->date->toDateString(), $c->date->toDateString(), $this->contexto('resgate #'.$c->id.' depois da despesa'));
            $this->assertLessThanOrEqual($hoje, $c->date->toDateString(), $this->contexto('resgate #'.$c->id.' no futuro'));
        }
        // Comparado em PHP pela data: o cast `date` grava "Y-m-d H:i:s" no sqlite, e
        // comparar o texto com "Y-m-d" daria "futuro" para qualquer movimento de hoje.
        $futuras = GoalContribution::all()->concat(InvestmentContribution::all())
            ->filter(fn ($c) => $c->date->toDateString() > $hoje);
        $this->assertCount(0, $futuras, $this->contexto('aporte/resgate com data futura'));

        // Parcelas e recorrências no cartão: uma por ciclo. (Ocorrência recorrente cuja
        // data o usuário editou no Histórico pode ir para onde ele quiser — fica de fora.)
        $conta = Account::findOrFail($cartao);
        foreach ($todas->where('account_id', $cartao)->whereNotNull('group_id')->groupBy('group_id') as $grupo => $linhas) {
            if ($linhas->contains(fn ($l) => isset($this->editadas[$l->id]))) {
                continue;
            }
            $ciclos = $linhas->map(fn ($l) => $conta->billingCycle(CarbonImmutable::parse($l->date))[1]->toDateString());
            $this->assertSame($linhas->count(), $ciclos->unique()->count(), $this->contexto('duas linhas do grupo '.$grupo.' no mesmo ciclo'));
            if ($linhas->first()->installments) {
                $this->assertSame($linhas->count(), $linhas->pluck('installment_no')->unique()->count(), $this->contexto('parcela repetida '.$grupo));
            }
        }
    }

    private function conferirTelas(): void
    {
        $dono = $this->titular->id;
        $caixa = Account::whereIn('id', array_keys($this->saldo))->get();
        $somaSaldo = round($caixa->sum(fn (Account $c) => $c->balance), 2);
        $somaDisp = round($caixa->sum(fn (Account $c) => $c->available), 2);

        $sidebar = app(SidebarService::class)->build($dono);
        $this->assertEqualsWithDelta($somaSaldo, $sidebar['saldoTotal'], self::EPS, $this->contexto('sidebar: patrimônio'));
        $this->assertEqualsWithDelta($somaDisp, $sidebar['disponivel'], self::EPS, $this->contexto('sidebar: disponível'));

        $dash = app(DashboardService::class)->build($dono);
        $this->assertEqualsWithDelta($somaSaldo, $dash['totalBalance'], self::EPS, $this->contexto('dashboard: patrimônio'));
        foreach (['semana', 'mes', 'ano'] as $periodo) {
            $this->assertEqualsWithDelta($somaDisp, $dash['payload']['periods'][$periodo]['stats']['saldo'], self::EPS,
                $this->contexto('dashboard: saldo ('.$periodo.')'));
        }
        foreach ($dash['accounts'] as $a) {
            if ($caixa->contains('id', $a->id)) {
                $this->assertEqualsWithDelta($caixa->firstWhere('id', $a->id)->available, $a->current_balance, self::EPS,
                    $this->contexto('dashboard: lista de contas #'.$a->id));
            }
        }

        // Receitas e despesas do período pela regra documentada (estorno só abate cartão, piso 0).
        $hoje = CarbonImmutable::today();
        $periodos = [
            'semana' => [$hoje->startOfWeek(CarbonImmutable::MONDAY), $hoje->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)],
            'mes' => [$hoje->startOfMonth(), $hoje->endOfMonth()],
            'ano' => [$hoje->startOfYear(), $hoje->endOfYear()],
        ];
        foreach ($periodos as $periodo => [$de, $ate]) {
            [$receitas, $despesas] = $this->fluxoEsperado($de, $ate);
            $stats = $dash['payload']['periods'][$periodo]['stats'];
            $this->assertEqualsWithDelta($receitas, $stats['receitas'], self::EPS, $this->contexto('dashboard: receitas ('.$periodo.')'));
            $this->assertEqualsWithDelta($despesas, $stats['despesas'], self::EPS, $this->contexto('dashboard: despesas ('.$periodo.')'));
        }

        // Sem lançamento de caixa datado no futuro, a linha do saldo termina no card.
        $futuro = Transaction::where('user_id', $dono)->whereIn('account_id', array_keys($this->saldo))
            ->where('date', '>', $hoje->toDateString())->exists();
        //
        // ⚠️ Só no sqlite: aporte/resgate têm cast `date` (não `date:Y-m-d`), então o
        // sqlite guarda "Y-m-d 00:00:00" e as bordas das comparações por texto da spark
        // (`<= véspera da janela` e `BETWEEN ... hoje`) deixam de fora o movimento feito
        // EXATAMENTE nesses dois dias. No MySQL a coluna é DATE e a conta fecha. Nesses
        // dois dias a comparação da spark do dashboard é pulada no sqlite.
        $spark = $dash['payload']['sparks']['saldo'];
        $bordas = [$hoje->toDateString(), $hoje->subDays(7)->toDateString()];
        $movimentoNaBorda = DB::connection()->getDriverName() === 'sqlite'
            && GoalContribution::all()->concat(InvestmentContribution::all())
                ->contains(fn ($c) => in_array($c->date->toDateString(), $bordas, true));
        if (! $futuro && $spark !== []) {
            if (! $movimentoNaBorda) {
                $this->assertEqualsWithDelta($somaDisp, (float) end($spark), self::EPS,
                    $this->contexto('dashboard: último ponto da spark do saldo'));
            }
            $this->assertEqualsWithDelta($somaSaldo, (float) end($sidebar['spark']), self::EPS,
                $this->contexto('sidebar: último ponto da spark do patrimônio'));
        }

        // Pagar despesas: fatura mostrada = régua de quem quita; topo = card do dashboard.
        $faturas = app(FaturaService::class)->build($dono);
        $card = collect($faturas['cards'])->first();
        /** @var Account $conta */
        $conta = Account::findOrFail($this->contas['cartao']->id);
        $lote = Account::liquidoComSinal($conta->linhasAQuitar('aberto'));
        $this->assertEqualsWithDelta(max(0.0, $lote), (float) $card['invoiceDue'], self::EPS, $this->contexto('/faturas: fatura aberta × lote'));
        $this->assertEqualsWithDelta($conta->committed, (float) $card['committed'], self::EPS, $this->contexto('/faturas: comprometido'));
        $this->assertEqualsWithDelta(
            round((float) $faturas['stats']['totalFaturas'] + (float) $faturas['stats']['totalContas'], 2),
            (float) $dash['faturasResumo']['total'], self::EPS, $this->contexto('dashboard × /faturas: total a pagar'));

        // Despesas avulsas de /faturas (mês corrente): despesa de caixa, fora quitação
        // de fatura e transferência.
        $mes = [$hoje->startOfMonth()->toDateString(), $hoje->endOfMonth()->toDateString()];
        $avulsas = Transaction::where('user_id', $dono)->where('type', 'expense')
            ->whereIn('account_id', array_keys($this->saldo))
            ->whereNull('settles_account_id')->whereNull('transfer_group_id')
            ->whereBetween('date', $mes)->get();
        $this->assertSame($this->centavos($avulsas), $this->centavos(collect($faturas['accountExpenses'])),
            $this->contexto('/faturas: despesas avulsas do mês'));

        // Resumos do dashboard = soma dos cofrinhos.
        $this->assertEqualsWithDelta(round(Goal::all()->sum(fn (Goal $g) => $g->saved), 2),
            (float) $dash['metasResumo']['total'], self::EPS, $this->contexto('dashboard: total das metas'));
        $this->assertEqualsWithDelta(round(Investment::all()->sum(fn (Investment $i) => $i->aplicado), 2),
            (float) $dash['investimentosResumo']['total'], self::EPS, $this->contexto('dashboard: total investido'));

        // Dependentes: o gasto do mês de cada pessoa é o que ELA lançou de despesa no
        // mês — fora transferência e pagamento de fatura (o gasto foi a compra).
        $gastos = Transaction::where('user_id', $dono)->where('type', 'expense')
            ->whereNull('transfer_group_id')->whereNull('settles_account_id')
            ->whereBetween('date', $mes)->get()->groupBy('made_by_user_id');
        $gastoDe = fn (User $u) => ($gastos->has($u->id) ? $this->centavos($gastos[$u->id]) : 0) / 100;
        $this->flushSession();
        $tela = $this->actingAs($this->titular)->get(route('dependentes'))->assertOk();
        $this->assertEqualsWithDelta($gastoDe($this->titular), (float) $tela->viewData('gastoTitular'), self::EPS,
            $this->contexto('dependentes: gasto do mês do titular'));
        $this->assertEqualsWithDelta($gastoDe($this->dependente),
            (float) $tela->viewData('dependents')->firstWhere('id', $this->dependente->id)->gasto, self::EPS,
            $this->contexto('dependentes: gasto do mês da dependente'));
        $this->assertEqualsWithDelta($gastoDe($this->titular) + $gastoDe($this->dependente), (float) $tela->viewData('gastoFamilia'),
            self::EPS, $this->contexto('dependentes: gasto da família'));

        // Select de pagamento: saldo de cada opção = disponível da conta (ou limite livre).
        foreach (Account::paymentOptions($dono) as $opcao) {
            $alvo = Account::findOrFail($opcao->id);
            $this->assertEqualsWithDelta($opcao->isCard ? $alvo->availableLimitDisplay : $alvo->available, (float) $opcao->saldo,
                self::EPS, $this->contexto('select de pagamento: '.$opcao->name));
        }
    }

    /** @return array{0: float, 1: float} [receitas, despesas] do período, pela regra documentada */
    private function fluxoEsperado(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $linhas = Transaction::where('user_id', $this->titular->id)
            ->whereNull('settles_account_id')->whereNull('transfer_group_id')
            ->where('date', '>=', $de->toDateString())->where('date', '<=', $ate->toDateString())
            ->get();
        $cartao = $this->contas['cartao']->id;

        $receitas = 0;
        $caixa = 0;
        $noCartao = 0;
        foreach ($linhas as $l) {
            $c = (int) round((float) $l->amount * 100);
            if ((int) $l->account_id === $cartao) {
                $noCartao += $l->type === 'expense' ? $c : -$c;
            } elseif ($l->type === 'income') {
                $receitas += $c;
            } else {
                $caixa += $c;
            }
        }

        return [$receitas / 100, ($caixa + max(0, $noCartao)) / 100];
    }

    // ================================================================= utilidades

    /** @return array<int, float> disponível real de cada conta de caixa */
    private function disponiveisReais(): array
    {
        $out = [];
        foreach (array_keys($this->saldo) as $id) {
            $out[$id] = Account::findOrFail($id)->available;
        }

        return $out;
    }

    /** Estado do dinheiro no banco, para provar que uma recusa não deixou rastro. */
    private function retrato(): array
    {
        $linhas = fn (string $tabela) => DB::table($tabela)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return [
            'transactions' => $linhas('transactions'),
            'goal_contributions' => $linhas('goal_contributions'),
            'investment_contributions' => $linhas('investment_contributions'),
            'credit_settlements' => $linhas('credit_settlements'),
            'ended_recurrences' => $linhas('ended_recurrences'),
            'goals' => $linhas('goals'),
            'investments' => $linhas('investments'),
            'fixed_bills' => $linhas('fixed_bills'),
            'accounts' => $linhas('accounts'),
        ];
    }

    private function http(string $metodo, string $url, array $dados = [], ?User $ator = null): TestResponse
    {
        $this->flushSession();
        $ator ??= mt_rand(1, 4) === 1 ? $this->dependente : $this->titular;

        $r = $this->actingAs($ator)->json($metodo, $url, $dados);

        if ($r->status() >= 500) {
            $this->fail($this->contexto('HTTP '.$r->status().' em '.$metodo.' '.$url.': '
                .($r->exception ? get_class($r->exception).': '.$r->exception->getMessage() : mb_substr((string) $r->getContent(), 0, 500))));
        }

        return $r;
    }

    /** 'ok' | 'fonte' | 'recusa' */
    private function classificar(TestResponse $r): string
    {
        $status = $r->status();
        if ($status === 409) {
            return 'fonte';
        }
        if ($status === 422) {
            $this->descricao .= ' {422: '.implode(' | ', array_map(fn ($m) => implode(' ', (array) $m), (array) $r->json('errors'))).'}';

            return 'recusa';
        }
        $this->assertLessThan(400, $status, $this->contexto('status inesperado '.$status.': '.mb_substr((string) $r->getContent(), 0, 300)));

        $erros = session('errors');
        if ($erros && $erros->any()) {
            $this->descricao .= ' {'.implode(' | ', $erros->all()).'}';

            return 'recusa';
        }
        if (session('erro')) {
            $this->descricao .= ' {'.session('erro').'}';

            return 'recusa';
        }

        return 'ok';
    }

    private function cardInfo(): Fluent
    {
        return collect(app(FaturaService::class)->build($this->titular->id)['cards'])->first();
    }

    private function opcaoDePagamento(): Fluent
    {
        $opcoes = Account::paymentOptions($this->titular->id);

        return $opcoes[mt_rand(0, $opcoes->count() - 1)];
    }

    private function contaDeCaixa(): int
    {
        return mt_rand(0, 2) ? $this->contas['corrente']->id : $this->contas['poupanca']->id;
    }

    private function membro(): User
    {
        return mt_rand(0, 2) ? $this->titular : $this->dependente;
    }

    private function transacaoAleatoria(): ?Transaction
    {
        $ids = Transaction::where('user_id', $this->titular->id)->orderBy('id')->pluck('id');

        return $ids->isEmpty() ? null : Transaction::find($ids[mt_rand(0, $ids->count() - 1)]);
    }

    /** @return array{0: ?string, 1: Goal|Investment|null} */
    private function cofrinhoAleatorio(): array
    {
        $chaves = array_keys($this->guardado);
        if (! $chaves) {
            return [null, null];
        }
        $chave = $chaves[mt_rand(0, count($chaves) - 1)];
        [$tipo, $id] = explode(':', $chave);

        return [$chave, $tipo === 'meta' ? Goal::find($id) : Investment::find($id)];
    }

    /** Valor aleatório; perto da referência às vezes, para bater nas bordas. */
    private function valor(float $referencia = 0.0): float
    {
        $modo = mt_rand(1, 10);
        if ($referencia > 0.02 && $modo <= 4) {
            $alvo = [$referencia, $referencia + 0.01, $referencia - 0.01, $referencia * 1.25, $referencia * 0.5][mt_rand(0, 4)];

            return max(0.01, round($alvo, 2));
        }

        return mt_rand(1, 180000) / 100;
    }

    private function data(bool $permiteFuturo = true): string
    {
        $hoje = CarbonImmutable::today();
        $sorteio = mt_rand(1, 10);

        return match (true) {
            $sorteio <= 6 => $hoje->toDateString(),
            $sorteio <= 9 || ! $permiteFuturo => $hoje->subDays(mt_rand(1, 45))->toDateString(),
            default => $hoje->addDays(mt_rand(1, 45))->toDateString(),
        };
    }

    /** @param  array<string, int>  $pesos */
    private function sortearPeso(array $pesos): string
    {
        $sorteio = mt_rand(1, array_sum($pesos));
        foreach ($pesos as $nome => $peso) {
            $sorteio -= $peso;
            if ($sorteio <= 0) {
                return $nome;
            }
        }

        return array_key_first($pesos);
    }

    private function brl(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }

    private function lerValor(string $brl): float
    {
        return round((float) str_replace(['.', ','], ['', '.'], $brl), 2);
    }

    /** Σ com sinal (despesa +, receita −) em centavos. */
    private function centavos(Collection $linhas): int
    {
        return (int) $linhas->sum(fn ($l) => ($l->type === 'expense' ? 1 : -1) * (int) round((float) $l->amount * 100));
    }

    private function somaComSinal(Collection $linhas): float
    {
        return $this->centavos($linhas) / 100;
    }

    private function rotulo(Transaction $t): string
    {
        return '#'.$t->id.' ('.$t->type.' '.$this->brl((float) $t->amount).' na #'.$t->account_id.' em '.$t->date->toDateString()
            .($t->paid_at ? ', paga' : '').($t->transfer_group_id ? ', transferência' : '').($t->settles_account_id ? ', quitação' : '')
            .($t->installments ? ', parcela '.$t->installment_no.'/'.$t->installments : '').($t->recurring ? ', recorrente' : '')
            .($t->funding_source ? ', '.$t->funding_source.' '.$t->funding_amount : '').')';
    }

    private function contexto(string $mensagem): string
    {
        return $mensagem.PHP_EOL.'semente '.$this->semente.', passo '.$this->passoAtual.PHP_EOL
            .'operação: '.$this->descricao.PHP_EOL
            .'últimas operações:'.PHP_EOL.'  '.implode(PHP_EOL.'  ', array_slice($this->log, -25));
    }
}
