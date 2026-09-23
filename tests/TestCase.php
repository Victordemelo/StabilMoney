<?php

namespace Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Banco de DESENVOLVIMENTO: o do `.env` de dev e o padrão do docker-compose.yml. Guarda
     * os dados de verdade do Victor, e já foi zerado uma vez (06/09/2026) por um
     * `migrate:fresh` que caiu no banco errado.
     */
    public const BANCO_DE_DEV = 'stabilmoney';

    /**
     * Sobe a aplicação e, ANTES de qualquer trait rodar, recusa a suíte apontada para o
     * banco de desenvolvimento.
     *
     * O `RefreshDatabase` roda `migrate:fresh` (DROP de todas as tabelas) no banco da
     * conexão padrão. Normalmente é o sqlite em memória do phpunit.xml, mas dois
     * descuidos trocam isso pelo MySQL de dev sem aviso nenhum:
     *
     * - `config:cache` esquecido: com bootstrap/cache/config.php presente, o Laravel lê a
     *   config congelada e IGNORA o `<env>` do phpunit.xml;
     * - variável de ambiente errada: o que vem do processo (`docker compose exec -e ...`)
     *   vence o phpunit.xml, que não usa `force="true"`.
     *
     * `createApplication()` roda antes de `setUpTraits()` (ver
     * `InteractsWithTestCaseLifecycle::setUpTheTestEnvironment`), então a exceção chega
     * antes da primeira migration — e antes de uma factory gravar qualquer coisa nos
     * testes que nem usam RefreshDatabase. Nada aqui abre conexão: só lê a config.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $motivo = static::motivoParaRecusarOBanco($app['config'], $app->configurationIsCached());

        if ($motivo !== null) {
            throw new RuntimeException($motivo);
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Os testes não dependem do build do Vite (public/build/manifest.json):
        // sem isso, qualquer página que renderiza @vite falharia no CI/local.
        $this->withoutVite();
    }

    /**
     * Por que a suíte NÃO pode rodar com esta config — ou null quando pode.
     *
     * Olha TODAS as conexões, não só a padrão: um teste que peça `DB::connection('mysql')`
     * pelo nome escreveria no banco dela. E olha a config EFETIVA, com o `DB_URL`
     * aplicado do mesmo jeito que o `DatabaseManager` aplica: a URL vence o `database` (e
     * até o `driver`) declarados na conexão.
     *
     * sqlite fica de fora: é arquivo ou memória, e o banco de dev é MySQL. Comparação sem
     * caixa: dependendo do sistema de arquivos, o MySQL trata `StabilMoney` como o mesmo
     * banco.
     */
    public static function motivoParaRecusarOBanco(Repository $config, bool $configEmCache = false): ?string
    {
        $parser = new ConfigurationUrlParser;
        $conexoes = (array) $config->get('database.connections', []);

        // A padrão primeiro: quando é ela a culpada, a mensagem cita o nome que a pessoa
        // acabou de digitar no `-e DB_CONNECTION=...`.
        $padrao = $config->get('database.default');
        if (is_string($padrao) && isset($conexoes[$padrao])) {
            $conexoes = [$padrao => $conexoes[$padrao]] + $conexoes;
        }

        foreach ($conexoes as $nome => $conexao) {
            if (! is_array($conexao)) {
                continue;
            }

            $efetiva = $parser->parseConfiguration($conexao);

            if (($efetiva['driver'] ?? null) === 'sqlite') {
                continue;
            }

            if (strtolower(trim((string) ($efetiva['database'] ?? ''))) !== self::BANCO_DE_DEV) {
                continue;
            }

            $comoResolver = $configEmCache
                ? 'A config está em CACHE (bootstrap/cache/config.php), e com ela o phpunit.xml é '
                    .'ignorado. Rode `php artisan config:clear` e tente de novo.'
                : 'Para rodar em MySQL, use um banco de testes com nome próprio, por exemplo: '
                    .'docker compose exec -T -e DB_CONNECTION=mysql -e DB_DATABASE=stabilmoney_testes app php artisan test';

            return 'Suíte RECUSADA: a conexão "'.$nome.'" aponta para o banco de desenvolvimento "'
                .self::BANCO_DE_DEV.'". O RefreshDatabase apagaria todas as tabelas dele '
                .'(migrate:fresh), com os dados de verdade. '.$comoResolver;
        }

        return null;
    }
}
