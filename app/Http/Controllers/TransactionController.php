<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToAjax;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FundingService;
use App\Support\Brl;
use App\Support\FundingSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    use AuthorizesRequests;
    use RespondsToAjax;

    public function index(Request $request)
    {
        $userId = $request->user()->ownerId();

        // Contas do usuário (usadas no select de filtro)
        $accounts = Account::where('user_id', $userId)->orderBy('name')->get();

        // Categorias da família, na MESMA ordem da tela de Categorias (`position`),
        // agrupadas por tipo no select. Ordenar por nome aqui faria o filtro
        // discordar da ordem que o usuário arrumou à mão.
        $categories = Category::where('user_id', $userId)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('type');

        $query = Transaction::with(['account', 'category', 'madeBy'])
            ->where('user_id', $userId);

        // Filtros opcionais via GET — sempre restritos aos dados do próprio usuário
        // "Receitas"/"Despesas" mostram só receita e despesa DE VERDADE: as pontas
        // de uma transferência continuam `income`/`expense` no banco (é o que faz o
        // saldo fechar), mas listá-las em "Receitas" diria que entrou dinheiro que só
        // trocou de conta. Elas têm filtro próprio, "Transferências".
        $type = $request->query('type');
        if (in_array($type, ['income', 'expense'], true)) {
            $query->where('type', $type)->whereNull('transfer_group_id');
        } elseif ($type === 'transfer') {
            $query->whereNotNull('transfer_group_id');
        }

        $accountId = (int) $request->query('account');
        if ($accountId && $accounts->contains('id', $accountId)) {
            $query->where('account_id', $accountId);
        }

        // Categoria: o id só é aceito se for da própria família — senão o filtro
        // viraria uma sonda para descobrir a categoria dos outros pelo que a
        // lista devolve (ou deixa de devolver).
        $categoryId = (int) $request->query('category');
        $idsDeCategoria = $categories->flatten()->pluck('id');
        if ($categoryId && $idsDeCategoria->contains($categoryId)) {
            $query->where('category_id', $categoryId);
        }

        // Período: "de" e "até", os dois opcionais e independentes.
        //
        // ⚠️ `where()` com `->toDateString()`, NUNCA `whereDate()`: a coluna já é
        // DATE, e `whereDate()` embrulha em função e anula os índices
        // `(account_id, date)` — regra do CLAUDE.md.
        //
        // Data inválida é IGNORADA em vez de estourar: o filtro chega pela URL e
        // qualquer um pode digitar `?de=ontem`. Filtro é conveniência, não deve
        // derrubar a listagem do histórico inteiro.
        [$de, $ate] = $this->periodoDoFiltro($request);

        if ($de) {
            $query->where('date', '>=', $de->toDateString());
        }
        if ($ate) {
            $query->where('date', '<=', $ate->toDateString());
        }

        $transactions = $query
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        // Exibe "quem fez a compra" só quando a família tem dependentes.
        $showAuthor = User::where('account_owner_id', $userId)->exists();

        return view('transactions.index', [
            'transactions' => $transactions,
            'accounts' => $accounts,
            'categories' => $categories,
            'showAuthor' => $showAuthor,
            // Devolvidas normalizadas (Y-m-d) para reabastecer os inputs de data.
            'filtroDe' => $de?->toDateString(),
            'filtroAte' => $ate?->toDateString(),
        ]);
    }

    /**
     * Lê "de"/"até" da query string, tolerando lixo.
     *
     * Se vierem invertidos (de > até), são TROCADOS em vez de devolver lista
     * vazia: quem digita 30/09 no "de" e 01/09 no "até" quis setembro, e uma tela
     * em branco não ajuda a perceber o erro.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function periodoDoFiltro(Request $request): array
    {
        $ler = function (?string $valor): ?CarbonImmutable {
            $valor = trim((string) $valor);
            if ($valor === '') {
                return null;
            }

            // ⚠️ `createFromFormat` é TOLERANTE: "2026-13-45" não estoura, ele
            // transborda para 2027-02-14. Um mês 13 digitado por engano viraria um
            // filtro silenciosamente errado. Por isso a data é reformatada e
            // comparada com a entrada — só passa o que for exatamente Y-m-d válido.
            try {
                $data = CarbonImmutable::createFromFormat('Y-m-d', $valor);
            } catch (\Throwable) {
                return null;
            }

            return $data && $data->format('Y-m-d') === $valor ? $data->startOfDay() : null;
        };

        $de = $ler($request->query('de'));
        $ate = $ler($request->query('ate'));

        if ($de && $ate && $de->greaterThan($ate)) {
            return [$ate, $de];
        }

        return [$de, $ate];
    }

    public function create(Request $request)
    {
        $userId = $request->user()->ownerId();

        return view('transactions.create', [
            // paymentOptions: cartão de débito aparece, mas submete a conta que ele espelha.
            'accounts' => Account::paymentOptions($userId),
            'categories' => Category::where('user_id', $userId)
                ->orderBy('type')
                ->orderBy('name')
                ->get(),
            'familyMembers' => $this->familyMembers($userId),
        ]);
    }

    public function store(StoreTransactionRequest $request, FundingService $funding)
    {
        $data = $request->validated();

        // `type=transfer` na rota comum: é por aqui que a fila offline e o service
        // worker reenviam (eles só conhecem `POST /transactions`). O modal usa a
        // rota própria (`transfer`); os dois caminhos gravam pelo mesmo método.
        if (($data['type'] ?? null) === 'transfer') {
            return $this->transferir($request, $funding);
        }

        $ownerId = $request->user()->ownerId();
        $data['user_id'] = $ownerId;
        // Autor do lançamento: o informado no form, ou o usuário atual por padrão.
        $data['made_by_user_id'] = $data['made_by_user_id'] ?? $request->user()->id;

        // Idempotência: o replay da fila offline pode reenviar o mesmo lançamento.
        // Se já existe um com este client_uuid na família, devolve o existente
        // em vez de duplicar. Precisa vir ANTES do guard: senão o reenvio de uma
        // despesa já gravada tentaria resgatar do investimento outra vez.
        $clientUuid = $data['client_uuid'] ?? null;
        if ($clientUuid) {
            $existing = Transaction::where('user_id', $ownerId)
                ->where('client_uuid', $clientUuid)
                ->first();

            if ($existing) {
                return $this->storeResponse($request, $existing, created: false);
            }
        }

        // Escolha da fonte é instrução, não coluna: sai do payload da transação.
        // `funding_max_amount` é o teto que o usuário aprovou no modal — vai para o
        // guard, nunca para a tabela.
        $fonte = $data['funding_source'] ?? null;
        $investimentoId = $data['funding_investment_id'] ?? null;
        $maxFonte = $data['funding_max_amount'] ?? null;
        unset($data['funding_source'], $data['funding_investment_id'], $data['funding_max_amount']);

        // Receita não gasta nada: grava direto. Despesa passa pelo guard.
        if ($data['type'] !== 'expense') {
            return $this->storeResponse($request, Transaction::create($data), created: true);
        }

        $conta = Account::whereKey($data['account_id'])->firstOrFail();

        $transaction = $funding->spend(
            account: $conta,
            amount: (float) $data['amount'],
            source: $fonte,
            investmentId: $investimentoId ? (int) $investimentoId : null,
            write: fn (array $auditoria) => Transaction::create($data + $auditoria),
            madeByUserId: $data['made_by_user_id'],
            date: $data['date'],
            maxFonte: $maxFonte !== null ? (float) $maxFonte : null,
        );

        return $this->storeResponse($request, $transaction, created: true);
    }

    /** Transferência entre contas de caixa pela rota própria (`transactions.transfer`). */
    public function transfer(StoreTransferRequest $request, FundingService $funding)
    {
        return $this->transferir($request, $funding);
    }

    /**
     * TRANSFERÊNCIA entre duas contas de caixa da família (corrente ↔ poupança).
     *
     * Nascem DUAS linhas ligadas por `transfer_group_id`: a saída (`expense`) na
     * origem e a entrada (`income`) no destino — mesmo valor, mesma data, mesmo
     * autor, sem categoria. `type` continua `income|expense` de propósito: assim
     * `Account::balance`, o extrato e as séries de saldo fecham sem mudar nada;
     * quem precisa distinguir (dashboard, filtro do Histórico, guardas de
     * edição) pergunta por `isTransferencia()`.
     *
     * A SAÍDA é um gasto novo para a origem e passa pelo `FundingService` como
     * qualquer despesa: sem disponível e sem fonte, 422; com cheque especial ou
     * investimento que cubra, 409 pedindo a escolha — e o reenvio traz
     * `funding_source`/`funding_investment_id`/`funding_max_amount`, com o MESMO
     * `client_uuid`. As duas linhas são gravadas dentro do `write` do `spend`, ou
     * seja, na mesma `DB::transaction` e com a origem já travada: não existe
     * instante em que o dinheiro saiu de uma conta sem ter entrado na outra.
     */
    private function transferir(StoreTransactionRequest $request, FundingService $funding)
    {
        $data = $request->validated();
        $ownerId = $request->user()->ownerId();
        $madeBy = (int) ($data['made_by_user_id'] ?? $request->user()->id);

        // Idempotência (mesma regra do store): o uuid vive na SAÍDA, que é a linha
        // que passa pelo guard. Reenvio de uma transferência já gravada devolve a
        // existente — sem isso, o replay da fila resgataria do investimento de novo.
        $clientUuid = $data['client_uuid'] ?? null;
        if ($clientUuid) {
            $existente = Transaction::where('user_id', $ownerId)
                ->where('client_uuid', $clientUuid)
                ->first();

            if ($existente) {
                return $this->storeResponse($request, $existente, created: false);
            }
        }

        $fonte = $data['funding_source'] ?? null;
        $investimentoId = $data['funding_investment_id'] ?? null;
        $maxFonte = isset($data['funding_max_amount']) ? (float) $data['funding_max_amount'] : null;

        $origem = Account::whereKey($data['account_id'])->firstOrFail();
        $destino = Account::whereKey($data['to_account_id'])->firstOrFail();
        $valor = round((float) $data['amount'], 2);
        $descricao = trim((string) ($data['description'] ?? ''));
        $grupo = (string) Str::uuid();

        $saida = $funding->spend(
            account: $origem,
            amount: $valor,
            source: $fonte,
            investmentId: $investimentoId ? (int) $investimentoId : null,
            write: function (array $auditoria) use ($ownerId, $madeBy, $clientUuid, $origem, $destino, $valor, $descricao, $grupo, $data) {
                $comum = [
                    'user_id' => $ownerId,
                    'made_by_user_id' => $madeBy,
                    'category_id' => null,
                    'amount' => $valor,
                    'date' => $data['date'],
                    'transfer_group_id' => $grupo,
                ];

                $saida = Transaction::create($comum + $auditoria + [
                    'client_uuid' => $clientUuid,
                    'account_id' => $origem->id,
                    'type' => 'expense',
                    'description' => $descricao !== '' ? $descricao : 'Transferência para '.$destino->name,
                ]);

                Transaction::create($comum + [
                    'account_id' => $destino->id,
                    'type' => 'income',
                    'description' => $descricao !== '' ? $descricao : 'Transferência de '.$origem->name,
                ]);

                return $saida;
            },
            madeByUserId: $madeBy,
            date: $data['date'],
            maxFonte: $maxFonte,
        );

        return $this->storeResponse(
            $request,
            $saida,
            created: true,
            mensagem: 'Transferência de '.Brl::format($valor).' de '.$origem->name.' para '.$destino->name.' registrada.',
        );
    }

    /**
     * Resposta do store conforme o cliente: JSON para o replay da fila offline
     * (Accept: application/json) — 201 criado, 200 se já existia (dedupe) —, e
     * redirect com flash para o formulário web normal.
     */
    private function storeResponse(Request $request, Transaction $transaction, bool $created, ?string $mensagem = null)
    {
        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'id' => $transaction->id,
                'client_uuid' => $transaction->client_uuid,
                'transfer_group_id' => $transaction->transfer_group_id,
                'created' => $created,
            ], $created ? 201 : 200);
        }

        return redirect()->route('transactions.index')
            ->with('status', $mensagem ?? 'Transação registrada com sucesso.');
    }

    public function edit(Request $request, Transaction $transaction)
    {
        $this->authorize('update', $transaction);

        $userId = $request->user()->ownerId();

        return view('transactions.edit', [
            'transaction' => $transaction,
            'accounts' => Account::paymentOptions($userId),
            'categories' => Category::where('user_id', $userId)
                ->orderBy('type')
                ->orderBy('name')
                ->get(),
            'familyMembers' => $this->familyMembers($userId),
        ]);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction, FundingService $funding)
    {
        $this->authorize('update', $transaction);

        // GUARDAS DE EDIÇÃO — precisam vir ANTES de qualquer ramo de gravação.
        //
        // O ramo de receita (mais abaixo) grava com `$transaction->update()` cru,
        // sem passar pelo FundingService. Se as guardas morassem lá dentro,
        // bastaria transformar a despesa em receita para escapar de todas elas.
        // Aqui, no topo, os dois caminhos ficam cobertos por construção.
        if ($motivo = $this->travaDeEdicao($transaction, $request->input('type'), $request->input('account_id'))) {
            return back()->withErrors(['transaction' => $motivo])->withInput();
        }

        $data = $request->validated();
        $data['made_by_user_id'] = $data['made_by_user_id'] ?? $request->user()->id;

        // PONTA DE TRANSFERÊNCIA: só a edição NEUTRA passa (descrição, data, autor),
        // e ela vale para as DUAS pontas — o par é uma coisa só. Mudar valor, conta
        // ou tipo numa ponta descolaria a outra (R$ 300 saindo de uma conta e R$ 200
        // entrando na outra é dinheiro sumindo), então é recusado no topo, antes de
        // qualquer ramo de gravação: "exclua e lance de novo".
        if ($transaction->isTransferencia()) {
            if ($motivo = $this->travaDeTransferencia($transaction, $data)) {
                return back()->withErrors(['transaction' => $motivo])->withInput();
            }

            DB::transaction(function () use ($transaction, $data) {
                $pontas = Transaction::with('account')
                    ->where('user_id', $transaction->user_id)
                    ->where('transfer_group_id', $transaction->transfer_group_id)
                    ->lockForUpdate()
                    ->get();

                $descricao = trim((string) ($data['description'] ?? ''));

                foreach ($pontas as $ponta) {
                    $outra = $pontas->firstWhere('id', '!=', $ponta->id);
                    $ponta->update([
                        'date' => $data['date'],
                        'made_by_user_id' => $data['made_by_user_id'],
                        // Descrição apagada volta ao padrão de cada ponta, em vez de
                        // deixar as duas linhas mudas no extrato.
                        'description' => $descricao !== '' ? $descricao : $this->descricaoPadraoDaPonta($ponta, $outra),
                    ]);
                }
            });

            return redirect()->route('transactions.index')
                ->with('status', 'Transferência atualizada nas duas contas.');
        }

        // Escolha da fonte é instrução, não coluna. `funding_max_amount` é o teto
        // que o usuário aprovou no modal — vai para o guard, nunca para a tabela.
        // (Antes o `unset` deixava o teto em `$data` e ele não chegava ao `spend`:
        // a edição era um dos caminhos que resgatava além do aprovado — F-3.)
        $fonte = $data['funding_source'] ?? null;
        $investimentoId = $data['funding_investment_id'] ?? null;
        $maxFonte = isset($data['funding_max_amount']) ? (float) $data['funding_max_amount'] : null;
        unset($data['funding_source'], $data['funding_investment_id'], $data['funding_max_amount']);

        // EDIÇÃO NEUTRA: só descrição, categoria, data ou autor mudaram. Nada
        // disso move dinheiro, então nem o guard nem a reconciliação têm o que
        // fazer — grava direto, preservando `funding_*` e o resgate ligado.
        //
        // Antes, TODA edição de despesa financiada recalculava a fonte do zero:
        // corrigir um typo na descrição devolvia R$ 300 ao investimento ("R$ 300
        // voltaram para o investimento"), num resgate que já tinha acontecido no
        // banco de verdade. As guardas do topo continuam valendo — elas rodaram
        // antes de chegar aqui.
        if (! $this->mexeNoDinheiro($transaction, $data)) {
            $transaction->update($data);

            return redirect()->route('transactions.index')
                ->with('status', 'Transação atualizada.');
        }

        // A fonte da linha ANTIGA é desfeita e recalculada do zero (ver
        // `reconciliarFonte`). Guardamos quanto vinha de RESGATE para poder
        // contar ao usuário, no flash, o que voltou (ou saiu a mais) do investimento.
        $tinhaFonte = (bool) $transaction->funding_source;
        $resgateAntigo = $transaction->funding_source === FundingSource::RESGATE_INVESTIMENTO
            ? round((float) $transaction->funding_amount, 2)
            : 0.0;

        $gravar = function () use ($transaction, $funding, $data, $fonte, $investimentoId, $maxFonte, $tinhaFonte) {
            if ($tinhaFonte) {
                $this->reconciliarFonte($transaction, $funding, (int) $data['account_id']);
            }

            // Receita não gasta nada: grava direto.
            if ($data['type'] !== 'expense') {
                $transaction->update($data);

                return;
            }

            // $ignore devolve ao disponível o que ESTA transação já ocupa — senão
            // reeditar uma despesa sem mudar o valor seria recusada por falta de saldo.
            // Só vale quando a conta continua a mesma; trocando de conta, a nova
            // precisa aguentar o valor inteiro.
            //
            // O sinal importa: se a linha era uma RECEITA que vai virar despesa, ela não
            // libera folga — ela DESAPARECE do saldo, então o efeito é negativo. Tratando
            // receita como 0.0 (que era o caso), o disponível consultado ainda continha a
            // receita sendo destruída e a folga era contada duas vezes: uma conta com R$ 100
            // e uma receita de R$ 500 aceitava virar despesa de R$ 600 e ia a −R$ 500 sem
            // cheque especial.
            $mesmaConta = (int) $data['account_id'] === (int) $transaction->account_id;
            $efeitoAtual = $transaction->type === 'expense'
                ? (float) $transaction->amount        // despesa antiga: liberava esse valor
                : -(float) $transaction->amount;      // receita antiga: some, então tira folga
            $ignore = $mesmaConta ? $efeitoAtual : 0.0;

            $conta = Account::whereKey($data['account_id'])->firstOrFail();

            $funding->spend(
                account: $conta,
                amount: (float) $data['amount'],
                source: $fonte,
                investmentId: $investimentoId ? (int) $investimentoId : null,
                write: function (array $auditoria) use ($transaction, $data) {
                    $transaction->update($data + $auditoria);

                    return $transaction;
                },
                ignore: $ignore,
                madeByUserId: $data['made_by_user_id'],
                date: $data['date'],
                maxFonte: $maxFonte,
            );
        };

        // Só há o que reconciliar — e, portanto, o que envolver numa transação de
        // banco aqui — quando a linha antiga tinha fonte. Sem fonte, quem abre a
        // transação (e trava a conta) é o próprio FundingService.
        $tinhaFonte
            ? DB::transaction($gravar, attempts: 3)
            : $gravar();

        return redirect()->route('transactions.index')
            ->with('status', $this->statusDaEdicao($transaction, $resgateAntigo));
    }

    /**
     * Guardas de edição do Histórico: devolve a mensagem PT-BR do motivo, ou
     * null quando a edição pode seguir.
     *
     * O Histórico edita QUALQUER linha de `transactions` — inclusive as que
     * foram escritas por outros fluxos (pagamento de fatura, parcela de compra
     * parcelada, competência de conta fixa). Nessas, o valor não é um número
     * solto: ele tem contrapartida em outro lugar (compras marcadas como pagas,
     * limite do cartão, parcelas irmãs do mesmo grupo). Mexer só num lado cria
     * ou destrói dinheiro — por isso a edição é recusada apontando o caminho certo.
     */
    private function travaDeEdicao(Transaction $transaction, ?string $novoTipo, mixed $novaContaId = null): ?string
    {
        // 1) QUITAÇÃO DE FATURA — a mesma trava que o `destroy` já fazia. Editar o
        //    valor devolve dinheiro ao caixa com as compras seguindo quitadas (e o
        //    limite do cartão seguindo livre): sobra dinheiro dos dois lados.
        if ($transaction->settles_account_id) {
            return 'Esta linha é o pagamento de uma fatura de cartão e não pode ser editada. '
                .'Para corrigir, use "Estornar pagamento" na tela Pagar despesas — as compras voltam a '
                .'ficar em aberto e você paga de novo com o valor certo.';
        }

        // 2) PARCELA ISOLADA de uma compra parcelada. Editar a 2/6 deixaria as
        //    outras cinco com o valor antigo, todas dizendo "de 6", e a soma da
        //    compra deixaria de bater com o que foi comprado.
        if ($transaction->group_id && $transaction->installments) {
            return 'Esta despesa é a parcela '.$transaction->badge.' de uma compra parcelada e não pode '
                .'ser editada sozinha — as outras parcelas continuariam com o valor antigo e a soma da '
                .'compra ficaria errada. Para mexer na compra inteira, use a tela Pagar despesas.';
        }

        // 3) COMPRA DE CARTÃO JÁ PAGA. `committed` (e o limite do cartão) ignoram
        //    linhas com `paid_at`, então editar o valor aqui muda uma dívida que já
        //    foi quitada sem o cartão registrar nada — e a quitação que a pagou
        //    continua com o valor velho.
        if ($transaction->paid_at && $transaction->account?->isCard()) {
            return 'Esta compra já foi paga na fatura do cartão '.$transaction->account->name
                .' e não pode ser editada. Estorne o pagamento da fatura na tela Pagar despesas, '
                .'corrija a compra e pague de novo.';
        }

        // 4) PAGAMENTO DE CONTA FIXA virando RECEITA: o dinheiro voltaria para o
        //    saldo e a competência continuaria marcada como paga — o que marca a
        //    competência é a EXISTÊNCIA da transação, não o tipo dela. Valor e data
        //    seguem editáveis de propósito: conta de luz varia.
        if ($transaction->fixed_bill_id && $novoTipo !== null && $novoTipo !== $transaction->type) {
            return 'Esta linha é o pagamento de uma conta fixa e precisa continuar sendo uma despesa — '
                .'é ela que marca a competência como paga. Você pode corrigir o valor e a data; '
                .'para desfazer o pagamento, exclua a linha.';
        }

        // 5) MUDAR UMA LINHA JÁ PAGA PARA UM CARTÃO. A guarda 3 só pega quem JÁ
        //    estava no cartão; este é o caminho inverso e some com a dívida:
        //    pague uma conta fixa pela corrente (nasce com `paid_at`, e a conta é
        //    caixa, então a guarda 3 não dispara), depois troque só a conta para o
        //    cartão. A linha vai para o cartão carregando o `paid_at`, e `committed`
        //    ignora linha paga — o cartão nunca cobra, a fatura nunca mostra, e o
        //    dinheiro volta para a corrente. Despesa quitada sem sair de lugar nenhum.
        if ($transaction->paid_at && $novaContaId && (int) $novaContaId !== (int) $transaction->account_id) {
            $novaConta = Account::whereKey($novaContaId)->first();

            if ($novaConta?->isCard() && ! $transaction->account?->isCard()) {
                return 'Esta despesa já está paga e não pode ser movida para o cartão '.$novaConta->name
                    .' — ela entraria na fatura já quitada, então o cartão nunca cobraria o valor. '
                    .'Exclua esta linha e lance a compra no cartão.';
            }
        }

        return null;
    }

    /**
     * Guarda das pontas de transferência: recusa o que MOVE dinheiro (valor, conta,
     * tipo) e a categoria (transferência não é gasto de nada). Devolve a mensagem
     * PT-BR, ou null quando só descrição/data/autor mudaram.
     */
    private function travaDeTransferencia(Transaction $ponta, array $data): ?string
    {
        $moveDinheiro = $this->mexeNoDinheiro($ponta, $data);
        $ganhouCategoria = ! empty($data['category_id']);

        if (! $moveDinheiro && ! $ganhouCategoria) {
            return null;
        }

        return 'Esta linha é uma ponta de transferência entre suas contas — '
            .($ganhouCategoria && ! $moveDinheiro
                ? 'ela não recebe categoria, porque mover dinheiro entre as próprias contas não é gasto de nada. '
                : 'valor, conta e tipo não podem ser alterados numa ponta só, senão a outra conta fica com um número diferente e o dinheiro some ou aparece do nada. ')
            .'Você pode corrigir a descrição, a data e quem fez; para mudar o resto, exclua a transferência e lance de novo.';
    }

    /** "Transferência para X" na saída, "Transferência de X" na entrada. */
    private function descricaoPadraoDaPonta(Transaction $ponta, ?Transaction $outra): string
    {
        $nomeDaOutra = $outra?->account?->name ?? 'outra conta';

        return $ponta->type === 'expense'
            ? 'Transferência para '.$nomeDaOutra
            : 'Transferência de '.$nomeDaOutra;
    }

    /**
     * A edição muda algo que MOVE dinheiro? Só três campos movem: o valor, a
     * conta de onde ele sai e o tipo (receita ↔ despesa). Descrição, categoria,
     * data e autor são rótulos — `Account::balance` não olha nenhum deles.
     *
     * É esta pergunta que decide entre gravar direto e reconciliar a fonte.
     */
    private function mexeNoDinheiro(Transaction $transaction, array $data): bool
    {
        if (($data['type'] ?? $transaction->type) !== $transaction->type) {
            return true;
        }

        if ((int) ($data['account_id'] ?? $transaction->account_id) !== (int) $transaction->account_id) {
            return true;
        }

        return abs(round((float) $data['amount'], 2) - round((float) $transaction->amount, 2)) > 0.001;
    }

    /**
     * Desfaz a fonte da linha ANTIGA para que o `spend` a seguir a recalcule do
     * zero (RECONCILIAÇÃO, não recusa — corrigir o valor de uma despesa é
     * operação legítima e frequente).
     *
     * Sem isto, baixar de R$ 800 para R$ 100 uma despesa financiada por resgate
     * deixava o resgate de R$ 800 de pé: o aplicado do investimento encolhia
     * R$ 700 sem contrapartida nenhuma. O `estornarFonte` só rodava no delete.
     *
     * Também zera a auditoria de `cheque_especial`: quando o novo valor cabe no
     * disponível, o `spend` devolve auditoria vazia e as colunas antigas
     * sobreviveriam, marcando uma despesa de R$ 100 como "cheque especial R$ 300".
     *
     * ORDEM DE LOCK: conta → pai, igual ao FundingService e ao HandlesContributions.
     * A conta é travada aqui, ANTES do estorno, e continua travada quando o
     * `spend` a relocka dentro da mesma transação — inverter causaria deadlock ABBA.
     */
    private function reconciliarFonte(Transaction $transaction, FundingService $funding, int $contaDestinoId): void
    {
        Account::whereKey($contaDestinoId)->lockForUpdate()->first();

        // Apaga o resgate ligado a esta despesa. Nada a fazer quando a fonte era
        // cheque especial: lá não nasce linha nenhuma, só auditoria.
        $funding->estornarFonte([$transaction->id]);

        $transaction->forceFill(['funding_source' => null, 'funding_amount' => null])->save();
    }

    /**
     * Flash da edição. Quando o resgate foi recalculado, o usuário precisa saber
     * — dinheiro que sai (ou volta) de um investimento sem aviso é o tipo de
     * movimento que ninguém consegue explicar depois.
     */
    private function statusDaEdicao(Transaction $transaction, float $resgateAntigo): string
    {
        $resgateNovo = $transaction->funding_source === FundingSource::RESGATE_INVESTIMENTO
            ? round((float) $transaction->funding_amount, 2)
            : 0.0;

        $diferenca = round($resgateAntigo - $resgateNovo, 2);

        if ($diferenca > 0.001) {
            return 'Transação atualizada. '.Brl::format($diferenca)
                .' voltaram para o investimento — o resgate foi recalculado para o novo valor.';
        }

        if ($diferenca < -0.001) {
            return 'Transação atualizada. Resgatamos mais '.Brl::format(abs($diferenca))
                .' do investimento para cobrir o novo valor.';
        }

        return 'Transação atualizada.';
    }

    public function destroy(Transaction $transaction, FundingService $funding)
    {
        $this->authorize('delete', $transaction);

        // TRANSFERÊNCIA: as duas pontas morrem juntas. Apagar só a entrada deixaria
        // a saída de pé (dinheiro sumiu da origem e não chegou a lugar nenhum), e
        // vice-versa. O estorno da fonte vale para a saída — ela é a linha que pode
        // ter sido financiada por resgate — e é feito na MESMA transação de banco.
        if ($transaction->isTransferencia()) {
            DB::transaction(function () use ($transaction, $funding) {
                $ids = Transaction::where('user_id', $transaction->user_id)
                    ->where('transfer_group_id', $transaction->transfer_group_id)
                    ->lockForUpdate()
                    ->pluck('id');

                $funding->estornarFonte($ids);
                // Delete em massa não dispara evento Eloquent — e não precisa: nenhum
                // hook de Transaction depende disso. O estorno acima é explícito.
                Transaction::whereIn('id', $ids)->delete();
            });

            return redirect()->route('transactions.index')
                ->with('status', 'Transferência removida das duas contas.');
        }

        // Mesma trava do /faturas: a linha que QUITOU uma fatura não se apaga
        // sozinha — ela é a contrapartida das compras marcadas como pagas.
        // Apagá-la devolveria o dinheiro à conta e ainda deixaria a fatura
        // quitada, criando dinheiro em dobro.
        if ($transaction->settles_account_id) {
            return back()->withErrors([
                'transaction' => 'Esta linha é o pagamento de uma fatura de cartão e não pode ser excluída sozinha. Para desfazer, use "Estornar" na tela Pagar despesas — assim as compras voltam a ficar em aberto.',
            ]);
        }

        // PARCELA ISOLADA (§14 da spec, D-12): apagar a 2/3 deixa "1/3" e "3/3"
        // no histórico e a soma da compra quebrada. Quem apaga a COMPRA inteira
        // — todas as parcelas em aberto de uma vez, mantendo as já pagas — é a
        // tela Pagar despesas (`faturas.compra.destroy`).
        if ($transaction->group_id && $transaction->installments) {
            return back()->withErrors([
                'transaction' => 'Esta despesa é a parcela '.$transaction->badge.' de uma compra parcelada '
                    .'e não pode ser excluída sozinha — as outras continuariam no histórico dizendo "de '
                    .$transaction->installments.'". Para remover a compra inteira, use a tela Pagar despesas.',
            ]);
        }

        // COMPRA DE CARTÃO JÁ QUITADA. A tela Pagar despesas já recusa isto
        // ("histórico financeiro não se reescreve"), mas o Histórico apagava: a
        // compra sumia e a quitação que a pagou ficava com o valor velho, sem a
        // dívida do outro lado. Mesma família das guardas 1 e 3 da edição.
        if ($transaction->paid_at && $transaction->account?->isCard()) {
            return back()->withErrors([
                'transaction' => 'Esta compra já foi paga na fatura do cartão '.$transaction->account->name
                    .' e não pode ser excluída — o pagamento continuaria no extrato sem a compra que o '
                    .'originou. Use "Estornar pagamento" na tela Pagar despesas primeiro.',
            ]);
        }

        DB::transaction(function () use ($transaction, $funding) {
            // Se esta despesa foi financiada por resgate de investimento, o
            // resgate morre junto — senão o aplicado encolhe sem contrapartida.
            $funding->estornarFonte([$transaction->id]);
            $transaction->delete();
        });

        return redirect()->route('transactions.index')
            ->with('status', 'Transação removida.');
    }

    /** Membros da família (titular + dependentes) para o seletor "quem fez a compra". */
    private function familyMembers(int $ownerId)
    {
        return User::where('id', $ownerId)
            ->orWhere('account_owner_id', $ownerId)
            ->orderBy('name')
            ->get();
    }
}
