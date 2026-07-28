<?php

namespace App\Http\Requests;

/**
 * Mesmas regras da criação — a posse da conta fixa é verificada pela
 * FixedBillPolicy no controller.
 */
class UpdateFixedBillRequest extends StoreFixedBillRequest
{
}
