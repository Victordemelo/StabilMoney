<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Support\Brl;

/**
 * Mesmas regras da criação — a posse da conta em si
 * é verificada pela AccountPolicy no controller.
 *
 * Com DUAS regras a mais, ambas nascidas do mesmo princípio: numa conta que já
 * tem dinheiro, editar o cadastro não pode destruir (nem "desmentir") o dinheiro
 * que já está lá.
 *
 * 1. O TIPO não pode mudar de classe (caixa ↔ cartão de crédito ↔ cartão de
 *    débito). Cada classe calcula o dinheiro de um jeito, então a troca fazia
 *    saldo desaparecer (corrente com histórico virando cartão de débito zerava o
 *    `initial_balance` e deixava as transações órfãs) ou contar em dobro (cartão
 *    de crédito virando corrente fazia as despesas dele descontarem do
 *    patrimônio, incluindo fatura já paga).
 *
 * 2. O LIMITE DO CHEQUE ESPECIAL não pode ser reduzido abaixo do que já está em
 *    uso — ver `regraDoChequeEspecialEmUso()`.
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

        $rules['overdraft_limit'][] = $this->regraDoChequeEspecialEmUso();

        return $rules;
    }

    /**
     * Reduzir o cheque especial abaixo do que a conta JÁ ESTÁ USANDO é proibido.
     *
     * O modelo de dinheiro v3 promete um piso: o disponível de uma conta corrente
     * nunca fica abaixo de `−overdraft_limit`. Sem esta regra a promessa era
     * furada pelo caminho mais bobo — uma conta em −R$ 800,00 com limite de
     * R$ 1.000,00 aceitava ser editada para limite 0 e ficava em −800 com piso 0,
     * um estado que nenhum lançamento conseguiria produzir. A partir daí o
     * `spendable` some, o card mostra uso de cheque especial acima de 100% e o
     * usuário não tem nem como saber quanto precisa depositar.
     *
     * Só olha conta CORRENTE (a de antes da edição): é a única que tem cheque
     * especial. Num cartão de crédito o `available` é naturalmente negativo (a
     * fatura), e medir "uso de cheque especial" ali barraria qualquer edição de
     * cartão.
     *
     * Só barra REDUÇÃO: uma conta que por algum motivo já esteja abaixo do
     * próprio limite continua editável (para manter ou aumentar), senão o
     * cadastro dela ficaria travado para sempre.
     *
     * Trocar "Conta Corrente" por "Conta Poupança" com o cheque especial em uso
     * cai aqui também — e deve mesmo: o `prepareForValidation` zera o limite
     * nesse caso, e poupança não tem cheque especial no Brasil.
     */
    private function regraDoChequeEspecialEmUso(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $conta = $this->route('account');

            if (! $conta instanceof Account || $conta->type !== 'checking') {
                return;
            }

            // `overdraftUsed` = max(0, −disponível): quanto o saldo já furou o zero.
            $usado = $conta->overdraftUsed;
            $atual = round((float) $conta->overdraft_limit, 2);
            $novo = round((float) $value, 2);

            // Conta positiva, ou o usuário não está reduzindo: nada a barrar.
            if ($usado <= 0.001 || $novo >= $atual) {
                return;
            }

            if ($novo + 0.001 < $usado) {
                $fail(
                    'Esta conta está usando ' . Brl::format($usado) . ' do cheque especial agora, '
                    . 'então o limite não pode cair para ' . Brl::format($novo) . '. '
                    . 'Deixe pelo menos ' . Brl::format($usado)
                    . ' ou lance um recebimento para cobrir o saldo negativo antes de reduzir o limite.'
                );
            }
        };
    }
}
