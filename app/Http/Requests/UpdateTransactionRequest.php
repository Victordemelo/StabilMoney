<?php

namespace App\Http\Requests;

/**
 * Mesmas regras da criação — a posse da transação em si
 * é verificada pela TransactionPolicy no controller.
 */
class UpdateTransactionRequest extends StoreTransactionRequest
{
}
