<?php

namespace Tests\Feature;

use App\Http\Controllers\PwaController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * O service worker muda a cada build (P-6 da auditoria de PWA de 06/09/2026).
 *
 * O navegador só instala um SW novo quando os BYTES do /sw.js mudam. Com o nome de cache
 * fixo (`sm-cache-v2`), um deploy não mudava byte nenhum: a aba aberta seguia com o CSS/JS
 * velhos — num app instalado, por dias — e o cache do /build guardava todas as versões.
 *
 * Agora a versão do build (hash do manifest do Vite) entra na primeira linha do script, e a
 * mesma versão vai numa meta do layout, para a página saber se ficou para trás. O
 * COMPORTAMENTO do SW com a versão (cache por build, `activate` apagando os velhos, a
 * resposta à pergunta da página) está provado em tests/js/service-worker.test.js, que
 * executa o código servido; aqui fica o que só o lado PHP prova.
 */
class ServiceWorkerAcompanhaOBuildTest extends TestCase
{
    use RefreshDatabase;

    private string $publico;

    protected function setUp(): void
    {
        parent::setUp();

        // Uma pasta `public` só do teste: o build de verdade (public/build) não é tocado.
        $this->publico = sys_get_temp_dir().'/sm-publico-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->publico.'/build');
        $this->app->usePublicPath($this->publico);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publico);

        parent::tearDown();
    }

    private function publicarBuild(string $manifest): void
    {
        file_put_contents($this->publico.'/build/manifest.json', $manifest);
    }

    private function sw(): string
    {
        return $this->get('/sw.js')->assertOk()->getContent();
    }

    /** A versão que o /sw.js servido declara na primeira linha. */
    private function versaoDoSw(): string
    {
        $achou = preg_match('/\Aconst VERSAO = "([^"]+)";\n/', $this->sw(), $m);

        $this->assertSame(1, $achou, 'A primeira linha do /sw.js tem de ser a versão do build.');

        return $m[1];
    }

    public function test_a_versao_e_o_hash_do_manifest_do_build(): void
    {
        $manifest = '{"resources/css/app.css":{"file":"assets/app-AAAA.css"}}';
        $this->publicarBuild($manifest);

        $this->assertSame(substr(md5($manifest), 0, 16), $this->versaoDoSw());
    }

    public function test_build_novo_muda_os_bytes_do_service_worker(): void
    {
        // É o que faz o navegador instalar o SW novo — e o SW novo, apagar o cache velho.
        $this->publicarBuild('{"resources/css/app.css":{"file":"assets/app-AAAA.css"}}');
        $antes = $this->sw();

        $this->publicarBuild('{"resources/css/app.css":{"file":"assets/app-BBBB.css"}}');
        $depois = $this->sw();

        $this->assertNotSame($antes, $depois);
    }

    public function test_o_mesmo_build_serve_os_mesmos_bytes(): void
    {
        // Senão o navegador reinstalaria o SW (e apagaria o cache) a cada checagem.
        $this->publicarBuild('{"resources/js/app.js":{"file":"assets/app-CCCC.js"}}');

        $this->assertSame($this->sw(), $this->sw());
    }

    public function test_sem_build_a_versao_e_estavel(): void
    {
        $this->assertSame(PwaController::VERSAO_SEM_BUILD, $this->versaoDoSw());
    }

    public function test_com_npm_run_dev_a_versao_e_estavel_mesmo_com_um_build_antigo_na_pasta(): void
    {
        // O arquivo `hot` diz que os assets vêm do servidor do Vite: o manifest que sobrou
        // de um build antigo não descreve nada do que a página carrega.
        $this->publicarBuild('{"resources/js/app.js":{"file":"assets/app-DDDD.js"}}');
        file_put_contents($this->publico.'/hot', 'http://127.0.0.1:5173');

        $this->assertSame(PwaController::VERSAO_SEM_BUILD, $this->versaoDoSw());
    }

    public function test_a_pagina_leva_a_mesma_versao_que_o_service_worker(): void
    {
        // As duas pontas que o `sm/pwa.js` e o pjax comparam: se divergirem, toda troca de
        // SW pareceria "versão nova" (ou nenhuma pareceria).
        $this->publicarBuild('{"resources/css/app.css":{"file":"assets/app-EEEE.css"}}');

        $html = $this->actingAs(User::factory()->create())->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<meta name="sm-versao" content="'.preg_quote($this->versaoDoSw(), '/').'"/',
            $html,
        );
    }

    public function test_fora_a_linha_da_versao_o_script_e_o_verbatim_da_view(): void
    {
        // O teste de JS executa o conteúdo do @verbatim com a linha da versão trocada. Isto
        // garante o outro lado: que o Laravel serve exatamente isso — nada interpolado a mais.
        $view = file_get_contents(resource_path('views/pwa/service-worker.blade.php'));
        $this->assertSame(1, preg_match('/@verbatim\n(.*)@endverbatim/s', $view, $m));

        $servido = preg_replace('/\Aconst VERSAO = "[^"]+";\n/', '', $this->sw());

        $this->assertSame(trim($m[1]), trim($servido));
    }

    public function test_o_nome_do_cache_depende_da_versao(): void
    {
        $this->assertStringContainsString("const CACHE = 'sm-cache-v3-' + VERSAO;", $this->sw());
    }
}
