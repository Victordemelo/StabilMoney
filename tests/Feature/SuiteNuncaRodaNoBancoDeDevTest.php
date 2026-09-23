<?php

namespace Tests\Feature;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * A suíte se recusa a subir apontada para o banco de DESENVOLVIMENTO (`stabilmoney`).
 *
 * O `RefreshDatabase` roda `migrate:fresh` — DROP de todas as tabelas — no banco da
 * conexão padrão. O banco de dev guarda os dados de verdade do Victor e já foi zerado
 * uma vez (06/09/2026) por um comando que caiu no banco errado. Dois descuidos levam a
 * suíte até ele sem aviso: `config:cache` esquecido (o phpunit.xml passa a ser ignorado)
 * e variável de ambiente errada (o `-e` do `docker compose exec` vence o phpunit.xml).
 * A trava vive em `Tests\TestCase::createApplication()`, que roda antes dos traits.
 *
 * NENHUM teste daqui abre conexão: todos sobem a aplicação com um host que não resolve
 * (`.invalid` é reservado e nunca existe). Se um dia a trava sumir, o pior que acontece
 * é o teste falhar — nunca alcançar um banco de verdade.
 */
class SuiteNuncaRodaNoBancoDeDevTest extends TestCase
{
    /**
     * Rede de segurança dos testes que sobem a aplicação. Vai por `DB_URL`, e não por
     * `DB_HOST`, porque o Dotenv REGRAVA, a cada boot, as variáveis que ele mesmo carregou
     * do .env: um `DB_HOST` trocado aqui voltaria a ser o `db` de verdade no boot seguinte.
     * `DB_URL` não existe no .env, então o valor daqui prevalece — e a URL vence o host da
     * conexão. Sem caminho na URL, o nome do banco continua vindo do `DB_DATABASE`.
     */
    private const URL_SEM_SAIDA = 'mysql://ninguem:nada@banco-que-nao-existe.invalid:1';

    /**
     * @param  array<string, array<string, mixed>>  $conexoes
     */
    #[DataProvider('configuracoes')]
    public function test_a_regra_recusa_exatamente_o_banco_de_dev(string $padrao, array $conexoes, bool $recusa): void
    {
        $config = new Repository(['database' => ['default' => $padrao, 'connections' => $conexoes]]);

        $motivo = TestCase::motivoParaRecusarOBanco($config);

        if ($recusa) {
            $this->assertNotNull($motivo, 'A suíte subiria no banco de dev.');
            $this->assertStringContainsString('"'.TestCase::BANCO_DE_DEV.'"', $motivo);
        } else {
            $this->assertNull($motivo, 'Recusou um banco que não é o de dev.');
        }
    }

    public static function configuracoes(): array
    {
        $mysql = fn (string $banco, array $extra = []) => ['driver' => 'mysql', 'host' => 'db', 'database' => $banco] + $extra;
        $sqlite = fn (string $banco, array $extra = []) => ['driver' => 'sqlite', 'database' => $banco] + $extra;

        return [
            'o normal: sqlite em memória' => ['sqlite', ['sqlite' => $sqlite(':memory:'), 'mysql' => $mysql(':memory:')], false],
            'MySQL no banco de dev' => ['mysql', ['mysql' => $mysql('stabilmoney')], true],
            'MariaDB, com outra caixa' => ['mariadb', ['mariadb' => ['driver' => 'mariadb', 'database' => 'StabilMoney']], true],
            'com espaço em volta' => ['mysql', ['mysql' => $mysql(' stabilmoney ')], true],
            'MySQL num banco de testes' => ['mysql', ['mysql' => $mysql('stabilmoney_testes')], false],
            'nome que só começa igual' => ['mysql', ['mysql' => $mysql('stabil_testes_agente_k')], false],
            // sqlite é arquivo ou memória: o banco de dev é MySQL.
            'sqlite com o mesmo nome' => ['sqlite', ['sqlite' => $sqlite('stabilmoney')], false],
            // A URL vence o `database` (e até o `driver`) da conexão, como no DatabaseManager.
            'DB_URL apontando para o dev' => ['sqlite', [
                'sqlite' => $sqlite(':memory:', ['url' => 'mysql://user:senha@db:3306/stabilmoney']),
            ], true],
            'database inocente, URL no dev' => ['mysql', [
                'mysql' => $mysql('laravel', ['url' => 'mysql://user:senha@db:3306/stabilmoney']),
            ], true],
            // Um teste pode pedir `DB::connection('pgsql')` pelo nome e escrever lá.
            'outra conexão, não a padrão' => ['sqlite', [
                'sqlite' => $sqlite(':memory:'),
                'pgsql' => ['driver' => 'pgsql', 'database' => 'stabilmoney'],
            ], true],
        ];
    }

    /** O caso da variável errada: `docker compose exec -e DB_CONNECTION=mysql -e DB_DATABASE=stabilmoney`. */
    public function test_variavel_de_ambiente_apontando_para_o_dev_derruba_a_subida_da_aplicacao(): void
    {
        $erro = $this->erroAoSubirAAplicacao([
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'stabilmoney',
            'DB_URL' => self::URL_SEM_SAIDA,
        ]);

        $this->assertNotNull($erro, 'A aplicação subiu apontada para o banco de dev.');
        $this->assertStringContainsString('conexão "mysql"', $erro->getMessage());
        $this->assertStringContainsString('DB_DATABASE=stabilmoney_testes', $erro->getMessage(), 'A mensagem precisa dizer como sair.');
    }

    /**
     * O caso do `config:cache` esquecido: a config congelada ignora o phpunit.xml.
     *
     * Simulado com `LoadConfiguration::alwaysUse()` — o mesmo mecanismo que o Laravel usa
     * para config em cache nos testes —, sem escrever em bootstrap/cache nem copiar para o
     * disco uma config que traz a APP_KEY e as senhas do .env.
     */
    public function test_config_em_cache_apontando_para_o_dev_derruba_a_subida_e_manda_limpar_o_cache(): void
    {
        $congelada = $this->app['config']->all();
        $congelada['database']['default'] = 'mysql';
        $congelada['database']['connections']['mysql']['database'] = 'stabilmoney';
        $congelada['database']['connections']['mysql']['url'] = self::URL_SEM_SAIDA;

        $erro = $this->erroAoSubirAAplicacao(configCongelada: $congelada);

        $this->assertNotNull($erro, 'Com a config em cache a aplicação subiu apontada para o banco de dev.');
        $this->assertStringContainsString('php artisan config:clear', $erro->getMessage());
    }

    /** E a config com que esta suíte roda agora não é recusada (senão nada rodaria). */
    public function test_a_config_de_teste_de_verdade_passa(): void
    {
        $this->assertNull(TestCase::motivoParaRecusarOBanco($this->app['config'], $this->app->configurationIsCached()));
    }

    /**
     * Sobe uma aplicação nova com estas variáveis no ambiente (e, se vier, com esta config
     * "em cache") e devolve o erro da trava — ou null, se subiu. Depois devolve o ambiente
     * e a aplicação do teste como estavam.
     *
     * @param  array<string, string>  $ambiente
     * @param  array<string, mixed>|null  $configCongelada
     */
    private function erroAoSubirAAplicacao(array $ambiente = [], ?array $configCongelada = null): ?RuntimeException
    {
        $antes = [];
        foreach ($ambiente as $nome => $valor) {
            $antes[$nome] = [$_SERVER[$nome] ?? null, $_ENV[$nome] ?? null, getenv($nome)];
            $_SERVER[$nome] = $_ENV[$nome] = $valor;
            putenv($nome.'='.$valor);
        }

        if ($configCongelada !== null) {
            LoadConfiguration::alwaysUse(fn () => $configCongelada);
        }

        try {
            $this->createApplication();

            return null;
        } catch (RuntimeException $erro) {
            return $erro;
        } finally {
            if ($configCongelada !== null) {
                LoadConfiguration::alwaysUse(null);
            }

            foreach ($antes as $nome => [$server, $env, $putenv]) {
                if ($server === null) {
                    unset($_SERVER[$nome]);
                } else {
                    $_SERVER[$nome] = $server;
                }

                if ($env === null) {
                    unset($_ENV[$nome]);
                } else {
                    $_ENV[$nome] = $env;
                }

                putenv($putenv === false ? $nome : $nome.'='.$putenv);
            }

            // A aplicação que acabou de subir (ou tentou) virou a instância global do
            // container, dos Facades e do Eloquent. Subir de novo, já com o ambiente de
            // volta, devolve tudo para a config de teste.
            $this->refreshApplication();
        }
    }
}
