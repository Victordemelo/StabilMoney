<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToAjax;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FundingService;
use App\Support\Brl;
use App\Support\FundingSource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    use AuthorizesRequests;
    use RespondsToAjax;

    public function index(Request $request)
    {
        $userId = $request->user()->ownerId();

        // Contas do usuário (usadas no select de filtro)
        $accounts = Account::where('user_id', $userId)->orderBy('name')->get();

        $query = Transaction::with(['account', 'category', 'madeBy'])
            ->where('user_id', $userId);

        // Filtros opcionais via GET — sempre restritos aos dados do próprio usuário
        $type = $request->query('type');
        if (in_array($type, ['income', 'expense'], true)) {
            $query->where('type', $type);
        }

        $accountId = (int) $request->query('account');
        if ($accountId && $accounts->contains('id', $accountId)) {
            $query->where('account_id', $accountId);
        }

        $transactions = $query
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        // Exibe "quem fez a compra" só quando a família tem dependentes.
        $showAuthor = User::where('account_owner_id', $userId)->exists();

        return view('transactions.index', compact('transactions', 'accounts', 'showAuthor'));
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

    /**
     * Resposta do store conforme o cliente: JSON para o replay da fila offline
     * (Accept: application/json) — 201 criado, 200 se já existia (dedupe) —, e
     * redirect com flash para o formulário web normal.
     */
    private function storeResponse(Request $request, Transaction $transaction, bool $created)
    {
        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'id' => $transaction->id,
                'client_uuid' => $transaction->client_uuid,
                'created' => $created,
            ], $created ? 201 : 200);
        }

        return redirect()->route('transactions.index')
            ->with('status', 'Transação registrada com sucesso.');
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

        $fonte = $data['funding_source'] ?? null;
        $investimentoId = $data['funding_investment_id'] ?? null;
        unset($data['funding_source'], $data['funding_investment_id']);

        // A fonte da linha ANTIGA é sempre desfeita e recalculada do zero (ver
        // `reconciliarFonte`). Guardamos quanto vinha de RESGATE para poder
        // contar ao usuário, no flash, o que voltou (ou saiu a mais) do investimento.
        $tinhaFonte = (bool) $transaction->funding_source;
        $resgateAntigo = $transaction->funding_source === FundingSource::RESGATE_INVESTIMENTO
            ? round((float) $transaction->funding_amount, 2)
            : 0.0;

        $gravar = function () use ($transaction, $funding, $data, $fonte, $investimentoId, $tinhaFonte) {
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
                . 'Para corrigir, use "Estornar pagamento" na tela Pagar despesas — as compras voltam a '
                . 'ficar em aberto e você paga de novo com o valor certo.';
        }

        // 2) PARCELA ISOLADA de uma compra parcelada. Editar a 2/6 deixaria as
        //    outras cinco com o valor antigo, todas dizendo "de 6", e a soma da
        //    compra deixaria de bater com o que foi comprado.
        if ($transaction->group_id && $transaction->installments) {
            return 'Esta despesa é a parcela ' . $transaction->badge . ' de uma compra parcelada e não pode '
                . 'ser editada sozinha — as outras parcelas continuariam com o valor antigo e a soma da '
                . 'compra ficaria errada. Para mexer na compra inteira, use a tela Pagar despesas.';
        }

        // 3) COMPRA DE CARTÃO JÁ PAGA. `committed` (e o limite do cartão) ignoram
        //    linhas com `paid_at`, então editar o valor aqui muda uma dívida que já
        //    foi quitada sem o cartão registrar nada — e a quitação que a pagou
        //    continua com o valor velho.
        if ($transaction->paid_at && $transaction->account?->isCard()) {
            return 'Esta compra já foi paga na fatura do cartão ' . $transaction->account->name
                . ' e não pode ser editada. Estorne o pagamento da fatura na tela Pagar despesas, '
                . 'corrija a compra e pague de novo.';
        }

        // 4) PAGAMENTO DE CONTA FIXA virando RECEITA: o dinheiro voltaria para o
        //    saldo e a competência continuaria marcada como paga — o que marca a
        //    competência é a EXISTÊNCIA da transação, não o tipo dela. Valor e data
        //    seguem editáveis de propósito: conta de luz varia.
        if ($transaction->fixed_bill_id && $novoTipo !== null && $novoTipo !== $transaction->type) {
            return 'Esta linha é o pagamento de uma conta fixa e precisa continuar sendo uma despesa — '
                . 'é ela que marca a competência como paga. Você pode corrigir o valor e a data; '
                . 'para desfazer o pagamento, exclua a linha.';
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
                return 'Esta despesa já está paga e não pode ser movida para o cartão ' . $novaConta->name
                    . ' — ela entraria na fatura já quitada, então o cartão nunca cobraria o valor. '
                    . 'Exclua esta linha e lance a compra no cartão.';
            }
        }

        return null;
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
            return 'Transação atualizada. ' . Brl::format($diferenca)
                . ' voltaram para o investimento — o resgate foi recalculado para o novo valor.';
        }

        if ($diferenca < -0.001) {
            return 'Transação atualizada. Resgatamos mais ' . Brl::format(abs($diferenca))
                . ' do investimento para cobrir o novo valor.';
        }

        return 'Transação atualizada.';
    }

    public function destroy(Transaction $transaction, FundingService $funding)
    {
        $this->authorize('delete', $transaction);

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
                'transaction' => 'Esta despesa é a parcela ' . $transaction->badge . ' de uma compra parcelada '
                    . 'e não pode ser excluída sozinha — as outras continuariam no histórico dizendo "de '
                    . $transaction->installments . '". Para remover a compra inteira, use a tela Pagar despesas.',
            ]);
        }

        // COMPRA DE CARTÃO JÁ QUITADA. A tela Pagar despesas já recusa isto
        // ("histórico financeiro não se reescreve"), mas o Histórico apagava: a
        // compra sumia e a quitação que a pagou ficava com o valor velho, sem a
        // dívida do outro lado. Mesma família das guardas 1 e 3 da edição.
        if ($transaction->paid_at && $transaction->account?->isCard()) {
            return back()->withErrors([
                'transaction' => 'Esta compra já foi paga na fatura do cartão ' . $transaction->account->name
                    . ' e não pode ser excluída — o pagamento continuaria no extrato sem a compra que o '
                    . 'originou. Use "Estornar pagamento" na tela Pagar despesas primeiro.',
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
