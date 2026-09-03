<?php

namespace App\Http\Requests;

/**
 * Transferência entre contas de caixa pela rota própria (`transactions.transfer`).
 *
 * As regras moram em `StoreTransactionRequest::regrasDeTransferencia()`, porque
 * `POST /transactions` também precisa aceitá-las (é para lá que a fila offline e
 * o service worker reenviam). Aqui só se fixa o tipo: quem bate nesta rota está
 * transferindo, não precisa dizer.
 */
class StoreTransferRequest extends StoreTransactionRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['type' => 'transfer']);

        parent::prepareForValidation();
    }

    public function rules(): array
    {
        return $this->regrasDeTransferencia();
    }
}
