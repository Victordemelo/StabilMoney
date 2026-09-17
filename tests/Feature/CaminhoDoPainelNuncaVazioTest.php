<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * O caminho do painel admin nunca pode ficar vazio.
 *
 * As rotas do painel usam `config('admin.path')` como prefixo (routes/admin.php). Com
 * `ADMIN_PANEL_PATH=` sem valor no `.env`, o `env()` devolvia `''` — ele só aplica o
 * padrão quando a chave NÃO existe — e o prefixo vazio registrava o painel na RAIZ do
 * site: `GET /` caía na tela de login do painel e, com o painel desligado, a página
 * inicial do app inteiro virava 404.
 *
 * Duas camadas de prova: o valor que o config produz para cada entrada problemática, e
 * a aplicação REINICIADA com a chave vazia, que é onde o sintoma aparecia.
 */
class CaminhoDoPainelNuncaVazioTest extends TestCase
{
    private const CHAVE = 'ADMIN_PANEL_PATH';

    /** Valores originais da chave, para devolver o ambiente como estava. */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = [
            'env' => $_ENV[self::CHAVE] ?? null,
            'server' => $_SERVER[self::CHAVE] ?? null,
            'putenv' => getenv(self::CHAVE),
        ];
    }

    protected function tearDown(): void
    {
        $this->definirVariavel($this->original['server']);

        if ($this->original['env'] === null) {
            unset($_ENV[self::CHAVE]);
        } else {
            $_ENV[self::CHAVE] = $this->original['env'];
        }

        if ($this->original['putenv'] === false) {
            putenv(self::CHAVE);
        } else {
            putenv(self::CHAVE.'='.$this->original['putenv']);
        }

        parent::tearDown();
    }

    /** `null` = a chave não existe no ambiente (o caso em que o `env()` usa o padrão). */
    private function definirVariavel(?string $valor): void
    {
        if ($valor === null) {
            unset($_ENV[self::CHAVE], $_SERVER[self::CHAVE]);
            putenv(self::CHAVE);

            return;
        }

        $_ENV[self::CHAVE] = $valor;
        $_SERVER[self::CHAVE] = $valor;
        putenv(self::CHAVE.'='.$valor);
    }

    /** O `path` que o config/admin.php produz para este valor no ambiente. */
    private function caminhoPara(?string $valor): string
    {
        $this->definirVariavel($valor);

        return (require config_path('admin.php'))['path'];
    }

    public function test_vazio_ou_so_barras_ou_espacos_cai_no_padrao(): void
    {
        foreach (['', '/', '//', '   ', ' / ', "\t"] as $valor) {
            $this->assertSame(
                'painel_admin',
                $this->caminhoPara($valor),
                'ADMIN_PANEL_PATH='.json_encode($valor).' não pode virar prefixo vazio.'
            );
        }
    }

    public function test_sem_a_chave_continua_o_padrao(): void
    {
        $this->assertSame('painel_admin', $this->caminhoPara(null));
    }

    public function test_caminho_proprio_e_respeitado_sem_as_barras_das_pontas(): void
    {
        $this->assertSame('segredo', $this->caminhoPara('segredo'));
        $this->assertSame('segredo', $this->caminhoPara('/segredo/'));
        $this->assertSame('area/restrita', $this->caminhoPara(' /area/restrita/ '));
    }

    public function test_o_caminho_zero_nao_e_tratado_como_vazio(): void
    {
        // Com um `?:` no lugar da comparação com '', "0" seria falso para o PHP e
        // trocado pelo padrão sem aviso.
        $this->assertSame('0', $this->caminhoPara('0'));
    }

    public function test_com_a_chave_vazia_a_raiz_do_site_continua_sendo_o_app(): void
    {
        // O sintoma de verdade: as rotas são registradas no BOOT, então é preciso
        // subir a aplicação de novo com a chave vazia no ambiente.
        $this->definirVariavel('');
        $this->refreshApplication();

        $this->assertSame('painel_admin', config('admin.path'));

        $rotaDaRaiz = app('router')->getRoutes()->match(Request::create('/', 'GET'));
        $this->assertSame(
            'dashboard',
            $rotaDaRaiz->getName(),
            'Com ADMIN_PANEL_PATH vazio, a raiz do site foi tomada pelo painel.'
        );

        $this->assertStringStartsWith('/painel_admin', route('painel.login', absolute: false));
    }
}
