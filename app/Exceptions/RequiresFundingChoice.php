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

        // Sem JS (ou com o JS falhando) a escolha vira um formulário de verdade,
        // renderizado por `partials/funding-modal`. Para reenviar a MESMA
        // requisição acrescida da fonte, o payload precisa levar para onde ela
        // ia e o que ela carregava — o Blade não tem como adivinhar isso.
        return back()
            ->withInput()
            ->with('fonteNecessaria', $this->payload + [
                'acao' => $request->fullUrl(),
                'metodo' => $request->method(),
                'campos' => $this->camposParaReenvio($request),
            ]);
    }

    /**
     * Campos escalares da requisição original, prontos para virar `<input hidden>`.
     *
     * Fora: o token da sessão (o form novo emite o seu com `@csrf`), o
     * `_method` (o replay é sempre POST direto na rota) e a escolha de fonte
     * anterior — que é justamente o que o usuário vai informar agora.
     * Arquivos e arrays não entram: nenhum caminho de gasto usa upload, e um
     * replay parcial seria pior que pedir de novo.
     */
    private function camposParaReenvio(Request $request): array
    {
        $campos = $request->except(['_token', '_method', 'funding_source', 'funding_investment_id']);

        return collect($campos)
            ->filter(fn ($valor) => is_scalar($valor))
            ->map(fn ($valor) => (string) $valor)
            ->all();
    }
}
