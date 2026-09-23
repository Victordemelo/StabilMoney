<?php

namespace Tests\Concerns;

/**
 * Avalia um arquivo de `config/` com o AMBIENTE que se quiser — do jeito que ele sairia
 * numa máquina cujo `.env` tenha (ou não tenha) aquelas variáveis.
 *
 * Por que existe: a suíte roda com o `.env` de desenvolvimento carregado, e ele pode
 * definir justamente a variável cujo PADRÃO se quer travar (o de dev tem `LOG_STACK`
 * próprio, por exemplo). `config()` devolveria o valor da máquina, não o que a produção
 * recebe quando a variável falta — e um teste assim passaria ou falharia conforme o
 * `.env` de quem roda.
 *
 * A variável é tirada (ou trocada) nos três lugares em que o `env()` do Laravel procura
 * — `$_SERVER`, `$_ENV` e `getenv()` — só enquanto o arquivo é avaliado, e volta ao que
 * era depois, aconteça o que acontecer. O `config()` da aplicação não é tocado.
 */
trait LeConfigComAmbiente
{
    /**
     * @param  string  $arquivo  nome do arquivo em `config/` (ex.: 'logging.php')
     * @param  array<string, string|null>  $ambiente  nome => valor; `null` = variável AUSENTE
     * @return array<string, mixed>
     */
    protected function configComAmbiente(string $arquivo, array $ambiente): array
    {
        $antes = [];

        foreach ($ambiente as $nome => $valor) {
            $antes[$nome] = [
                'server' => [array_key_exists($nome, $_SERVER), $_SERVER[$nome] ?? null],
                'env' => [array_key_exists($nome, $_ENV), $_ENV[$nome] ?? null],
                'getenv' => getenv($nome),
            ];

            $this->definirVariavel($nome, $valor);
        }

        try {
            return require config_path($arquivo);
        } finally {
            foreach ($antes as $nome => $estado) {
                [$tinha, $valor] = $estado['server'];
                if ($tinha) {
                    $_SERVER[$nome] = $valor;
                } else {
                    unset($_SERVER[$nome]);
                }

                [$tinha, $valor] = $estado['env'];
                if ($tinha) {
                    $_ENV[$nome] = $valor;
                } else {
                    unset($_ENV[$nome]);
                }

                putenv($estado['getenv'] === false ? $nome : $nome.'='.$estado['getenv']);
            }
        }
    }

    private function definirVariavel(string $nome, ?string $valor): void
    {
        if ($valor === null) {
            unset($_SERVER[$nome], $_ENV[$nome]);
            putenv($nome);

            return;
        }

        $_SERVER[$nome] = $_ENV[$nome] = $valor;
        putenv($nome.'='.$valor);
    }
}
