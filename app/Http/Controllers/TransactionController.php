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
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

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
        $fonte = $data['funding_source'] ?? null;
        $investimentoId = $data['funding_investment_id'] ?? null;
        unset($data['funding_source'], $data['funding_investment_id']);

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

        $data = $request->validated();
        $data['made_by_user_id'] = $data['made_by_user_id'] ?? $request->user()->id;

        $fonte = $data['funding_source'] ?? null;
        $investimentoId = $data['funding_investment_id'] ?? null;
        unset($data['funding_source'], $data['funding_investment_id']);

        if ($data['type'] !== 'expense') {
            $transaction->update($data);

            return redirect()->route('transactions.index')->with('status', 'Transação atualizada.');
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

        return redirect()->route('transactions.index')
            ->with('status', 'Transação atualizada.');
    }

    public function destroy(Transaction $transaction)
    {
        $this->authorize('delete', $transaction);

        $transaction->delete();

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
