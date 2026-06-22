<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Fonte única de verdade para detectar se o cliente espera resposta JSON.
 *
 * Centraliza a checagem usada pelas telas AJAX (fetch/XHR) e pela fila offline
 * do PWA, que antes divergiam entre `wantsJson()` e `expectsJson()`. O OR cobre
 * ambos os consumidores sem regredir: para um request web normal os dois são
 * false (mantém o fluxo de redirect intacto).
 */
trait RespondsToAjax
{
    /** Detecta se o cliente espera resposta JSON (fetch/XHR das telas AJAX e da fila offline). */
    protected function wantsJsonResponse(Request $request): bool
    {
        return $request->wantsJson() || $request->expectsJson();
    }
}
