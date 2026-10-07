<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Corridas entre duas requisições, num processo só (out/2026 — auditoria de concorrência).
 *
 * A suíte não roda duas requisições ao mesmo tempo. O que dá para montar é a JANELA: a outra
 * requisição "termina" num ponto exato desta — depois da validação do Form Request (é ali que
 * o time-of-check acaba) — e o teste confere se a gravação desta enxergou o mundo novo.
 */
trait SimulaRequisicaoConcorrente
{
    /**
     * Roda `$outraRequisicao` UMA vez, logo depois de o Form Request `$classe` ter sido
     * validado. O container chama os `afterResolving` na ordem em que foram registrados, e o
     * do framework (que valida) vem do boot — antes deste.
     *
     * @param  class-string  $classe
     */
    protected function depoisDaValidacaoDe(string $classe, Closure $outraRequisicao): void
    {
        $rodou = false;
        $this->app->afterResolving($classe, function () use (&$rodou, $outraRequisicao) {
            if ($rodou) {
                return;
            }
            $rodou = true;
            $outraRequisicao();
        });
    }

    /**
     * As consultas SQL de `$acao`, cada uma com o nível de transação em que rodou — para
     * conferir que a decisão e a escrita acontecem DENTRO da mesma transação, depois da trava.
     *
     * @return list<array{sql: string, nivel: int}>
     */
    protected function consultasDe(Closure $acao): array
    {
        $consultas = [];
        DB::listen(function ($q) use (&$consultas) {
            $consultas[] = ['sql' => $q->sql, 'nivel' => $q->connection->transactionLevel()];
        });
        $acao();

        return $consultas;
    }
}
