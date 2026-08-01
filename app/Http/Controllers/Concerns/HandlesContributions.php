<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Account;
use App\Services\SpendingGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lógica compartilhada de aporte/resgate dos "cofrinhos" (Goal e Investment).
 *
 * Os dois controllers de contribuição são gêmeos: só mudam o type-hint do
 * model-pai na rota e a rota de redirect. Toda a gravação (com transação +
 * lock + recheque da invariante sob lock) vive aqui, numa fonte única.
 * O `InvestmentController::store` reusa os mesmos helpers para o aporte
 * inicial, para não existir um segundo caminho de escrita sem rede.
 *
 * Por que rechecar sob lock se o Form Request já validou? O Form Request é o
 * time-of-check; a gravação é o time-of-use. Entre os dois há uma janela em que
 * duas requisições concorrentes poderiam, cada uma, passar na validação e
 * estourar o saldo disponível / o reservado. O relock + recheque dentro da
 * `DB::transaction` é a rede de segurança de integridade (em sqlite, usado nos
 * testes, `lockForUpdate` é no-op e a transação funciona normalmente).
 *
 * ⚠️ ORDEM DE LOCK: **conta → pai (Goal/Investment), SEMPRE** — a mesma do
 * `App\Services\FundingService`. Antes este trait travava pai → conta e o
 * `FundingService` conta → pai: duas requisições cruzadas se travavam
 * mutuamente (deadlock ABBA, erro 1213 no MySQL) e o usuário levava um 500.
 * Por isso, também, toda transação daqui usa `attempts: 3` — deadlock/lock
 * timeout vira retry, não erro.
 */
trait HandlesContributions
{
    /** Nº de tentativas: um deadlock/lock timeout é reexecutado, não vira 500. */
    private const TENTATIVAS = 3;

    /**
     * Cria a movimentação (aporte/resgate) do cofrinho de forma atômica.
     *
     * Para 'aporte': o valor não pode passar do disponível da conta de origem.
     * Para 'resgate': o valor não pode passar do que AQUELA conta tem guardado
     * neste pai (`reservedFromAccount`) — não do total do pai. Só volta para a
     * conta o dinheiro que saiu dela.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $parent  Goal ou Investment.
     * @param  string  $type  'aporte' | 'resgate'.
     */
    protected function record(Request $request, Model $parent, string $type): void
    {
        $data = $request->validated();
        $ownerId = $request->user()->ownerId();
        $accountId = (int) $data['account_id'];
        $amount = (float) $data['amount'];

        DB::transaction(function () use ($parent, $type, $data, $ownerId, $accountId, $amount) {
            // 1) Conta primeiro (ordem fixa conta → pai, igual ao FundingService).
            $account = $this->lockAccount($ownerId, $accountId);

            // 2) Depois o pai.
            $lockedParent = $this->lockParent($parent);

            if ($type === 'resgate') {
                $this->assertCabeNoReservadoDaConta($lockedParent, $account, $amount);
            } else {
                $this->assertCabeNoDisponivel($account, $amount);
            }

            $lockedParent->contributions()->create([
                'account_id' => $account->id,
                // Autor: o informado no form, ou o usuário atual por padrão.
                'made_by_user_id' => $data['made_by_user_id'] ?? request()->user()->id,
                'type' => $type,
                'amount' => $data['amount'],
                'date' => $data['date'] ?? now()->toDateString(),
            ]);
        }, attempts: self::TENTATIVAS);
    }

    /**
     * Trava a linha da conta (1º elo da ordem de lock) e garante que ela é da
     * família. Chame SEMPRE antes de travar o pai.
     *
     * @throws ValidationException
     */
    protected function lockAccount(int $ownerId, int $accountId): Account
    {
        $account = Account::where('id', $accountId)
            ->where('user_id', $ownerId)
            ->lockForUpdate()
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                'account_id' => 'A conta escolhida não existe ou não é da sua família.',
            ]);
        }

        return $account;
    }

    /** Trava a linha do pai (Goal/Investment) — 2º elo da ordem de lock. */
    protected function lockParent(Model $parent): Model
    {
        return $parent->newQuery()
            ->whereKey($parent->getKey())
            ->lockForUpdate()
            ->first() ?? $parent;
    }

    /**
     * Aporte: não pode passar do disponível da conta de origem.
     *
     * @throws ValidationException
     */
    protected function assertCabeNoDisponivel(Account $account, float $amount): void
    {
        if ($amount > $account->available + SpendingGuard::EPSILON) {
            throw ValidationException::withMessages([
                'amount' => 'O valor do aporte é maior que o saldo disponível na conta de origem (R$ '
                    . number_format($account->available, 2, ',', '.') . ').',
            ]);
        }
    }

    /**
     * Resgate: não pode passar do que ESTA conta tem guardado neste pai.
     *
     * Validar contra o total do pai (o que se fazia antes) permitia resgatar
     * para uma conta que nunca aportou: o `reserved` dela ia a negativo e o
     * disponível oferecia dinheiro inexistente.
     *
     * @throws ValidationException
     */
    protected function assertCabeNoReservadoDaConta(Model $parent, Account $account, float $amount): void
    {
        /** @var \App\Models\Goal|\App\Models\Investment $parent */
        $reservado = $parent->reservedFromAccount($account->id);

        if ($amount > $reservado + SpendingGuard::EPSILON) {
            throw ValidationException::withMessages([
                // A mensagem vive no model (Goal/Investment) para ser a MESMA
                // no time-of-check (Form Request) e aqui, no time-of-use.
                'amount' => $parent->mensagemResgateAcimaDoReservado($account, $reservado),
            ]);
        }
    }
}
