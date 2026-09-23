<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\RotatingFileHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Concerns\LeConfigComAmbiente;
use Tests\TestCase;

/**
 * O log gira por dia e guarda 180 dias — não cresce para sempre (item 26 da rodada de
 * pré-publicação).
 *
 * O defeito: `LOG_STACK=single` (o padrão do `config/logging.php` e do `.env.example`)
 * gravava tudo num `laravel.log` que nunca é rotacionado. Na VPS ele cresce até encher o
 * disco — e guarda para sempre o que a Política de Privacidade (seção 11) promete manter
 * por "até 6 meses".
 *
 * O `.env` de desenvolvimento pode seguir no `single`; o que se trava aqui é o que a
 * PRODUÇÃO recebe: o padrão do arquivo de config (quando a variável falta) e o
 * `.env.example` (de onde o `.env` do servidor nasce).
 */
class LogDiarioGuardaSeisMesesTest extends TestCase
{
    use LeConfigComAmbiente;

    private const DIAS = 180;

    /** Nenhuma variável de log definida — a máquina que só copiou o código. */
    private const SEM_NADA_NO_ENV = ['LOG_CHANNEL' => null, 'LOG_STACK' => null, 'LOG_DAILY_DAYS' => null];

    private ?string $pastaTemporaria = null;

    protected function tearDown(): void
    {
        if ($this->pastaTemporaria !== null) {
            File::deleteDirectory($this->pastaTemporaria);
        }

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $config */
    private function assertLogDiarioDe180Dias(array $config): void
    {
        $this->assertSame('stack', $config['default']);
        $this->assertSame(['daily'], $config['channels']['stack']['channels'], 'O log de produção não gira por dia.');
        $this->assertSame(self::DIAS, $config['channels']['daily']['days']);
    }

    public function test_sem_nada_no_env_o_log_gira_por_dia_e_guarda_180_dias(): void
    {
        $daMaquina = env('LOG_STACK');

        $this->assertLogDiarioDe180Dias($this->configComAmbiente('logging.php', self::SEM_NADA_NO_ENV));

        // O ambiente da máquina volta intacto: nenhum outro teste herda a simulação.
        $this->assertSame($daMaquina, env('LOG_STACK'));
    }

    public function test_o_env_example_leva_a_producao_para_o_log_diario_de_180_dias(): void
    {
        $exemplo = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

        $this->assertSame('daily', $exemplo['LOG_STACK'] ?? null);
        $this->assertSame((string) self::DIAS, $exemplo['LOG_DAILY_DAYS'] ?? null);

        $this->assertLogDiarioDe180Dias($this->configComAmbiente('logging.php', [
            'LOG_CHANNEL' => $exemplo['LOG_CHANNEL'] ?? null,
            'LOG_STACK' => $exemplo['LOG_STACK'],
            'LOG_DAILY_DAYS' => $exemplo['LOG_DAILY_DAYS'],
        ]));
    }

    /**
     * Para o Monolog, zero é "nunca apagar" — o defeito de volta por outra porta. E vazio
     * chegaria como string vazia num parâmetro `int`: erro ao abrir o log.
     */
    #[DataProvider('retencoesInvalidas')]
    public function test_retencao_invalida_cai_nos_180_dias_e_nunca_em_guardar_para_sempre(string $valor): void
    {
        $config = $this->configComAmbiente('logging.php', ['LOG_DAILY_DAYS' => $valor]);

        $this->assertSame(self::DIAS, $config['channels']['daily']['days']);
    }

    public static function retencoesInvalidas(): array
    {
        return [
            'vazio' => [''],
            'zero' => ['0'],
            'negativo' => ['-30'],
            'texto' => ['seis meses'],
        ];
    }

    public function test_prazo_diferente_no_env_e_respeitado(): void
    {
        $config = $this->configComAmbiente('logging.php', ['LOG_DAILY_DAYS' => '90']);

        $this->assertSame(90, $config['channels']['daily']['days']);
    }

    /**
     * O Monolog de verdade, montado com o canal do arquivo de config: ao escrever no dia,
     * o que passou dos 180 dias é APAGADO do disco. É isso que a Política promete.
     */
    public function test_o_log_montado_apaga_do_disco_o_que_passou_de_180_dias(): void
    {
        $this->pastaTemporaria = sys_get_temp_dir().'/log-diario-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->pastaTemporaria);

        // Duzentos dias de arquivos antigos, no formato que o canal diário gera.
        for ($dias = 1; $dias <= 200; $dias++) {
            touch($this->pastaTemporaria.'/laravel-'.now()->subDays($dias)->format('Y-m-d').'.log');
        }

        $canal = $this->configComAmbiente('logging.php', self::SEM_NADA_NO_ENV)['channels']['daily'];
        $canal['path'] = $this->pastaTemporaria.'/laravel.log';

        $log = Log::build($canal);
        $handler = $log->getLogger()->getHandlers()[0];
        $this->assertInstanceOf(RotatingFileHandler::class, $handler);
        $this->assertSame(self::DIAS, (new ReflectionProperty($handler, 'maxFiles'))->getValue($handler));

        $log->info('primeira linha do dia');

        $restantes = glob($this->pastaTemporaria.'/laravel-*.log');
        sort($restantes);

        $this->assertCount(self::DIAS, $restantes, 'O log diário não apagou os arquivos antigos.');
        $this->assertStringEndsWith('laravel-'.now()->format('Y-m-d').'.log', end($restantes));
        $this->assertStringEndsWith(
            'laravel-'.now()->subDays(self::DIAS - 1)->format('Y-m-d').'.log',
            $restantes[0],
            'Sobrou arquivo com mais de 180 dias.',
        );
    }
}
