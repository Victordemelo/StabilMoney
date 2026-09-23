<?php

namespace Tests\Feature;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Tests\TestCase;

/**
 * A suíte não escreve no log de desenvolvimento.
 *
 * O phpunit.xml não definia `LOG_CHANNEL`, então o log dos testes era o do `.env` de dev:
 * `storage/logs/laravel.log`. Todo erro esperado (SMTP que recusa, senha vazada sem
 * checagem, 500 provocado de propósito) ia para lá a cada rodada, e o arquivo passou de
 * 40 MB — enterrando o que o log de dev existe para mostrar.
 *
 * Quem precisa conferir o que foi logado escuta o evento (`Log::listen`), que dispara
 * com o canal `null` também — é o que prova o segundo teste.
 */
class SuiteNaoEscreveNoLogDeDevTest extends TestCase
{
    public function test_o_log_padrao_da_suite_nao_grava_em_storage_logs(): void
    {
        $arquivos = collect(Log::driver()->getLogger()->getHandlers())
            // RotatingFileHandler (canal diário) também é um StreamHandler.
            ->filter(fn ($handler) => $handler instanceof StreamHandler)
            ->map(fn (StreamHandler $handler) => (string) $handler->getUrl())
            ->filter(fn (string $caminho) => str_starts_with($caminho, storage_path('logs')))
            ->values()
            ->all();

        $this->assertSame([], $arquivos, 'A suíte está escrevendo no log de desenvolvimento.');
    }

    public function test_quem_escuta_o_evento_continua_vendo_o_que_foi_logado(): void
    {
        $vistos = [];
        Log::listen(function (MessageLogged $registro) use (&$vistos) {
            $vistos[] = $registro->message;
        });

        Log::warning('aviso que um teste quer conferir');

        $this->assertSame(['aviso que um teste quer conferir'], $vistos);
    }
}
