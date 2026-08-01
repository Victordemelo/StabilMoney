<?php

namespace App\Http\Requests;

use App\Models\Account;

/**
 * Mesmas regras da criação — a posse da conta em si
 * é verificada pela AccountPolicy no controller.
 *
 * Com UMA regra a mais: numa conta que já tem dinheiro, o TIPO não pode mudar
 * de classe (caixa ↔ cartão de crédito ↔ cartão de débito). Cada classe calcula
 * o dinheiro de um jeito, então a troca fazia saldo desaparecer (corrente com
 * histórico virando cartão de débito zerava o `initial_balance` e deixava as
 * transações órfãs) ou contar em dobro (cartão de crédito virando corrente fazia
 * as despesas dele descontarem do patrimônio, incluindo fatura já paga).
 */
class UpdateAccountRequest extends StoreAccountRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['type'][] = function (string $attribute, mixed $value, \Closure $fail): void {
            $account = $this->route('account');

            if ($account instanceof Account && ($erro = $account->travaDeClasse(is_string($value) ? $value : null))) {
                $fail($erro);
            }
        };

        return $rules;
    }
}
