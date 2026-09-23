<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GET /up — a checagem de saúde que o monitor da VPS consulta.
 *
 * Substitui a rota `health: '/up'` do framework, que falhava justamente no papel de
 * alvo do monitor (item 6 da rodada de pré-publicação):
 *  - devolvia uma página HTML que carregava um script de CDN de TERCEIRO
 *    (cdn.jsdelivr.net) na origem do app — código de fora rodando no domínio do dinheiro
 *    das pessoas, numa resposta sem CSP nenhuma para contê-lo;
 *  - era CEGA: respondia 200 com o banco fora do ar (o app nunca registrou listener de
 *    `DiagnosingHealth`) e 200 em modo de manutenção (o framework a tira da manutenção
 *    de propósito). O monitor ficava verde com o app inteiro em erro;
 *  - saía sem cabeçalho de segurança nenhum.
 *
 * Aqui a resposta é texto curto — `ok`, `manutencao` ou `indisponivel` — e o status é
 * o que o monitor lê: 200 ou 503. A falha NUNCA diz o motivo técnico no corpo (quem
 * precisa dele lê o log do servidor); a URL é pública.
 *
 * FORA do grupo `web`, de propósito (rota registrada no `bootstrap/app.php`): sem sessão
 * e sem cookie. Com o monitor batendo a cada minuto, cada checagem dentro do grupo seria
 * uma linha nova na tabela `sessions` — 1.440 por dia, de um visitante que nunca volta.
 */
class SaudeController
{
    public const OK = 'ok';

    public const EM_MANUTENCAO = 'manutencao';

    public const INDISPONIVEL = 'indisponivel';

    public function __invoke(Request $request): Response
    {
        [$status, $corpo] = match (true) {
            $this->emManutencao() => [503, self::EM_MANUTENCAO],
            ! $this->bancoResponde(), ! $this->logAceitaEscrita() => [503, self::INDISPONIVEL],
            default => [200, self::OK],
        };

        return SecurityHeaders::completarRespostaSemScript(response($corpo, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            // O monitor precisa da resposta DESTE instante, nunca de um cache no caminho.
            'Cache-Control' => 'no-store',
        ]), $request);
    }

    /**
     * Em manutenção o app não atende ninguém, então a checagem também não passa. O /up
     * fica de fora do middleware de manutenção (ver `bootstrap/app.php`) só para responder
     * ele mesmo, em texto, em vez da página HTML de erro.
     */
    private function emManutencao(): bool
    {
        try {
            return app()->isDownForMaintenance();
        } catch (Throwable) {
            // Driver de manutenção em cache e o cache fora do ar: não dá para afirmar que
            // o app está atendendo.
            return true;
        }
    }

    /** Uma consulta de verdade, na conexão que o app usa. */
    private function bancoResponde(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable $e) {
            // O motivo vai para o log do servidor, nunca para o corpo da resposta pública.
            // Protegido: se o próprio log estiver quebrado, a checagem continua respondendo.
            try {
                Log::error('Checagem de saúde (/up): o banco não respondeu.', [
                    'erro' => $e::class.': '.Str::limit($e->getMessage(), 300),
                ]);
            } catch (Throwable) {
                // nada a fazer: o 503 já conta a história
            }

            return false;
        }
    }

    /**
     * As pastas em que o log de verdade escreve aceitam escrita?
     *
     * Barato (um `stat`) e decisivo: log que não grava não é só log perdido. O Monolog
     * LANÇA exceção quando não consegue abrir o arquivo, e qualquer requisição que tente
     * registrar alguma coisa — um aviso de senha aceita sem checagem, um e-mail que não
     * saiu, o próprio relatório de um erro — vira erro 500. Permissão errada em
     * `storage/` depois de um deploy é o caso clássico. E com o canal DIÁRIO (padrão de
     * produção) nasce um arquivo novo a cada dia: não basta o de hoje ser gravável, a
     * PASTA precisa ser.
     *
     * Só os canais de arquivo contam: com log em `stderr`, não há pasta a conferir.
     */
    private function logAceitaEscrita(): bool
    {
        $padrao = (string) config('logging.default');
        $canais = config("logging.channels.{$padrao}.driver") === 'stack'
            ? (array) config("logging.channels.{$padrao}.channels", [])
            : [$padrao];

        foreach ($canais as $canal) {
            $arquivo = config('logging.channels.'.trim((string) $canal).'.path');

            if (is_string($arquivo) && $arquivo !== '' && ! is_writable(dirname($arquivo))) {
                return false;
            }
        }

        return true;
    }
}
