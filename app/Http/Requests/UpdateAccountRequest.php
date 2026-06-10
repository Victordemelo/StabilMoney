<?php

namespace App\Http\Requests;

/**
 * Mesmas regras da criação — a posse da conta em si
 * é verificada pela AccountPolicy no controller.
 */
class UpdateAccountRequest extends StoreAccountRequest
{
}
