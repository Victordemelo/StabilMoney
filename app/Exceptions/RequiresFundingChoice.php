<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;

/**
 * O saldo disponível não cobre a despesa, mas existe fonte (cheque especial
 * e/ou resgate de investimento). Isto NÃO é erro de validação — é uma pergunta:
 * "de onde sai esse dinheiro?".
 *
 * Por isso responde 409 (conflito de estado) e não 422: o front distingue
 * "corrija o formulário" de "escolha a fonte" pelo status, sem parsear mensagem.
 *
 * Três front-ends consomem a mesma exceção:
 *  - modal "Lançar" e formulário de transação (fetch → 409 + payload);
 *  - telas sem JS e a de faturas (redirect com `fonteNecessaria` na sessão);
 *  - fila offline (409 → reenvia uma vez com cheque especial).
 */
class RequiresFundingChoice extends Exception
{
    public function __construct(public readonly array $payload)
    {
        parent::__construct('Escolha de onde sai o dinheiro desta despesa.');
    }

    public function render(Request $request)
    {
        if ($request->wantsJson() || $request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'precisa_fonte' => true,
                'fonte' => $this->payload,
            ], 409);
        }

        return back()
            ->withInput()
            ->with('fonteNecessaria', $this->payload);
    }
}
