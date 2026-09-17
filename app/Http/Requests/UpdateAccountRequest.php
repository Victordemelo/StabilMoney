<?php

namespace App\Http\Requests;

use App\Models\Account;
use App\Support\Brl;

/**
 * Mesmas regras da criação — a posse da conta em si é verificada pela
 * AccountPolicy, já no `authorize()` deste request (antes de qualquer regra) e
 * de novo no controller.
 *
 * Com TRÊS regras a mais, todas nascidas do mesmo princípio: numa conta que já
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
 *
 * 3. O SALDO INICIAL não pode ser reduzido a ponto de furar esse mesmo piso —
 *    ver `regraDoPisoDoSaldoInicial()`. É a regra irmã da anterior: as duas
 *    protegem o mesmo invariante (`available >= −overdraft_limit`), cada uma
 *    por um dos dois campos que o compõem.
 */
class UpdateAccountRequest extends StoreAccountRequest
{
    /**
     * Posse PRIMEIRO, antes de qualquer regra (A-3 da auditoria de 05/09/2026).
     *
     * O Form Request resolve a autorização antes de rodar o validador, enquanto a
     * policy do controller só roda DEPOIS da validação. As três regras deste
     * arquivo leem a conta da rota para montar a mensagem de erro (nome, tipo,
     * saldo, uso do cheque especial), e cada uma dependia de lembrar da própria
     * guarda de posse. A da trava de classe esqueceu: um PATCH com o id de uma
     * conta alheia devolvia o nome e o tipo dela, e a confirmação de que ela tinha
     * dinheiro, antes do 403.
     *
     * Com a policy aqui, conta de outra família recebe o mesmo 403 sem que regra
     * nenhuma chegue a olhar para ela — inclusive as que ainda vão ser escritas.
     * As guardas dentro de cada regra continuam como segunda linha.
     */
    public function authorize(): bool
    {
        $conta = $this->route('account');

        return $conta instanceof Account && $this->user()->can('update', $conta);
    }

    public function rules(): array
    {
        $rules = parent::rules();

        $rules['type'][] = function (string $attribute, mixed $value, \Closure $fail): void {
            $account = $this->route('account');

            // Mesma guarda de posse das outras duas regras — e a que faltava. A
            // mensagem da trava cita o NOME e o TIPO da conta e confirma que ela
            // "já tem saldo, lançamentos ou dinheiro guardado". Conta de outra
            // família: silêncio aqui, e o 403 vem da policy. Da família é
            // `ownerId()`, não o id de quem está logado: o dependente edita as
            // contas do titular e continua recebendo a trava.
            if (! $account instanceof Account
                || (int) $account->user_id !== (int) $this->user()->ownerId()) {
                return;
            }

            if ($erro = $account->travaDeClasse(is_string($value) ? $value : null)) {
                $fail($erro);
            }
        };

        $rules['overdraft_limit'][] = $this->regraDoChequeEspecialEmUso();
        $rules['initial_balance'][] = $this->regraDoPisoDoSaldoInicial();

        return $rules;
    }

    /**
     * Reduzir o saldo inicial não pode deixar a conta abaixo do piso do cheque
     * especial (F-4 da auditoria de 02/09/2026).
     *
     * O `available` é `initial_balance + receitas − despesas − reservado`. A regra
     * do limite protegia só um dos dois lados da promessa: uma conta de R$ 1.000
     * com R$ 800 aplicados e cheque especial zero aceitava `initial_balance = 100`
     * e ficava em −R$ 700 SEM cheque especial — um estado que nenhum lançamento
     * consegue produzir, porque o `SpendingGuard` recusaria a despesa. E uma conta
     * em −300 com limite 500 aceitava `initial_balance = 0` e ia para −1.300 com
     * piso prometido de −500 (uso de 260% na barra do card).
     *
     * A projeção usa os DOIS valores novos (saldo inicial e limite): quem reduz o
     * saldo inicial e aumenta o limite na mesma edição está mantendo o invariante,
     * e não deve ser barrado. A comparação é sempre com `−novo_limite`, que vira
     * zero se o tipo estiver deixando de ser corrente (o `prepareForValidation`
     * zera o limite fora de `checking`).
     *
     * Só barra quando a edição PIORA a conta: uma conta que já esteja abaixo do
     * próprio piso continua editável (manter ou subir o saldo inicial), senão o
     * cadastro dela ficaria travado para sempre — mesma escolha da regra do limite.
     *
     * Só conta corrente/poupança tem saldo inicial; nos cartões o campo chega nulo
     * (zerado pelo `prepareForValidation`) e a regra não se aplica.
     */
    private function regraDoPisoDoSaldoInicial(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $conta = $this->route('account');

            // A policy só roda no controller, DEPOIS da validação. Sem esta guarda
            // a mensagem de erro revelava o saldo de conta alheia ("ficaria em
            // −R$ 123,45") antes do 403 — uma sonda. Conta de outra família:
            // silêncio aqui, e o 403 vem em seguida.
            if (! $conta instanceof Account
                || (int) $conta->user_id !== (int) $this->user()->ownerId()
                || ! in_array($conta->type, ['checking', 'savings'], true)
                || ! is_numeric($value)) {
                return;
            }

            $atual = round((float) $conta->initial_balance, 2);
            $novo = round((float) $value, 2);

            // Manter ou aumentar o saldo inicial nunca piora o disponível.
            if ($novo + 0.001 >= $atual) {
                return;
            }

            // `available` = initial + receitas − despesas − reservado. Trocar o
            // saldo inicial desloca o disponível exatamente pela diferença.
            $disponivelProjetado = round($conta->available - $atual + $novo, 2);

            // O limite que VALERÁ depois da edição — não o de hoje.
            $limiteNovo = $this->input('type') === 'checking' && is_numeric($this->input('overdraft_limit'))
                ? round((float) $this->input('overdraft_limit'), 2)
                : 0.0;

            if ($disponivelProjetado + 0.001 < -$limiteNovo) {
                $fail(
                    'Com esse saldo inicial a conta ficaria em '.Brl::format($disponivelProjetado).', '
                    .($limiteNovo > 0
                        ? 'abaixo do limite do cheque especial ('.Brl::format($limiteNovo).'). '
                        : 'e ela não tem cheque especial para cobrir saldo negativo. ')
                    .'O saldo em conta não pode ficar abaixo de '.Brl::format(-$limiteNovo).'. '
                    .'Deixe o saldo inicial em pelo menos '.Brl::format($novo + (-$limiteNovo - $disponivelProjetado))
                    .' ou faça um resgate do que está guardado em metas/investimentos antes de reduzir.'
                );
            }
        };
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

            // Mesma guarda de posse da regra do saldo inicial: nada de revelar o
            // uso do cheque especial de conta alheia antes do 403 da policy.
            if (! $conta instanceof Account
                || (int) $conta->user_id !== (int) $this->user()->ownerId()
                || $conta->type !== 'checking') {
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
                    'Esta conta está usando '.Brl::format($usado).' do cheque especial agora, '
                    .'então o limite não pode cair para '.Brl::format($novo).'. '
                    .'Deixe pelo menos '.Brl::format($usado)
                    .' ou lance um recebimento para cobrir o saldo negativo antes de reduzir o limite.'
                );
            }
        };
    }
}
