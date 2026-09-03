<?php

namespace App\Http\Requests;

/**
 * Mesmas regras da criação — a posse da transação em si
 * é verificada pela TransactionPolicy no controller.
 *
 * ⚠️ As colunas que amarram uma linha a OUTRO fluxo — `settles_account_id`
 * (quitação de fatura), `group_id`/`installments` (parcela), `fixed_bill_id` +
 * `competence` (competência de conta fixa), `paid_at` e `settled_by_id` — de
 * propósito NÃO têm regra aqui: sem regra, `validated()` não as devolve e o
 * `update()` do controller não consegue alterá-las. Não basta, porém: mexer só
 * no VALOR dessas linhas já quebra a contrapartida do outro lado (compras
 * quitadas, limite do cartão, parcelas irmãs). Quem recusa esses casos é
 * `TransactionController::travaDeEdicao()`, que roda ANTES dos dois ramos de
 * gravação — inclusive antes do ramo de receita, que grava sem o FundingService.
 */
class UpdateTransactionRequest extends StoreTransactionRequest
{
    /**
     * Edição NUNCA valida como transferência: `type` fica em `income|expense`.
     * Uma ponta de transferência é editada com as regras comuns — e as guardas de
     * `travaDeEdicao()` recusam qualquer mudança que mova dinheiro nela.
     */
    protected function aceitaTransferencia(): bool
    {
        return false;
    }
}
