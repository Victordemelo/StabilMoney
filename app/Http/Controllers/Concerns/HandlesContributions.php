<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Account;
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
 *
 * Por que rechecar sob lock se o Form Request já validou? O Form Request é o
 * time-of-check; a gravação é o time-of-use. Entre os dois há uma janela em que
 * duas requisições concorrentes poderiam, cada uma, passar na validação e
 * estourar o saldo disponível / o reservado. O relock + recheque dentro da
 * `DB::transaction` é a rede de segurança de integridade (em sqlite, usado nos
 * testes, `lockForUpdate` é no-op e a transação funciona normalmente).
 */
trait HandlesContributions
{
    /**
     * Cria a movimentação (aporte/resgate) do cofrinho de forma atômica.
     *
     * Para 'aporte': relock da conta de origem e recheque de que o valor não
     * passa do disponível dela.
     * Para 'resgate': relock do pai (Goal/Investment) e recheque de que o valor
     * não passa do reservado/aplicado nele.
     *
     * Ordem de lock SEMPRE pai-primeiro, depois conta (consistente entre aporte
     * e resgate) para evitar deadlock.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $parent  Goal ou Investment.
     * @param  string  $type  'aporte' | 'resgate'.
     */
    protected function record(Request $request, Model $parent, string $type): void
    {
        $data = $request->validated();
        $ownerId = $request->user()->ownerId();
        $accountId = $data['account_id'];
        $amount = (float) $data['amount'];

        DB::transaction(function () use ($parent, $type, $data, $ownerId, $accountId, $amount) {
            // Lock SEMPRE o pai primeiro (ordem consistente p/ evitar deadlock).
            $lockedParent = $parent->newQuery()
                ->whereKey($parent->getKey())
                ->lockForUpdate()
                ->first();

            if ($type === 'resgate') {
                // Não pode resgatar mais do que está reservado/aplicado no pai.
                $disponivelNoPai = $this->parentBalance($lockedParent);

                if ($amount > $disponivelNoPai + 0.001) {
                    throw ValidationException::withMessages([
                        'amount' => $this->withdrawOverflowMessage($disponivelNoPai),
                    ]);
                }
            } else {
                // Aporte: relock da conta de origem e recheque do disponível.
                $account = Account::where('id', $accountId)
                    ->where('user_id', $ownerId)
                    ->lockForUpdate()
                    ->first();

                if (! $account || $amount > $account->available + 0.001) {
                    throw ValidationException::withMessages([
                        'amount' => 'O valor do aporte é maior que o saldo disponível na conta de origem.',
                    ]);
                }
            }

            $lockedParent->contributions()->create([
                'account_id' => $accountId,
                // Autor: o informado no form, ou o usuário atual por padrão.
                'made_by_user_id' => $data['made_by_user_id'] ?? request()->user()->id,
                'type' => $type,
                'amount' => $data['amount'],
                'date' => $data['date'] ?? now()->toDateString(),
            ]);
        });
    }

    /**
     * Valor que pode ser resgatado do pai (Goal->saved / Investment->aplicado).
     * Cada controller concreto informa qual accessor usar.
     */
    abstract protected function parentBalance(Model $parent): float;

    /** Mensagem de erro coerente com a do WithdrawRequest, dado o saldo do pai. */
    abstract protected function withdrawOverflowMessage(float $available): string;
}
