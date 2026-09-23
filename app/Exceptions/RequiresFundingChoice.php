<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
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
 *  - fila offline (409 → segura o item e pergunta na página; ela NUNCA escolhe
 *    a fonte sozinha — ver CLAUDE.md, "A fila offline NUNCA escolhe a fonte").
 *
 * ## `ShouldntReport`: a pergunta não vai para o log
 *
 * Sem isto o handler registrava cada pergunta como ERROR, com stack trace inteiro
 * — é o destino padrão de toda exceção que ninguém marcou. Só que ela é fluxo
 * normal: acontece toda vez que alguém gasta mais do que o disponível e tem
 * cheque especial ou investimento para cobrir. Em produção isso enchia o log de
 * "erros" que não eram erro nenhum, e é justamente no meio desse ruído que o erro
 * de verdade passa despercebido. A resposta (409 ou redirect) não muda: não
 * reportar e renderizar são etapas separadas do handler.
 */
class RequiresFundingChoice extends Exception implements ShouldntReport
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
