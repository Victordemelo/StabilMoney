<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Os limitadores "por minuto + por hora" valem nas DUAS janelas (24/09/2026).
 *
 * A conta de segurança do AppServiceProvider (o código de 6 dígitos, a senha do painel)
 * depende do teto por HORA, e nenhum teste olhava além do primeiro minuto. Estes seguem as
 * requisições minuto a minuto, pela rota, com o middleware de verdade.
 *
 * ⚠️ Os dois limites de cada limitador usam o MESMO `by()` — e isso está certo: no Laravel 12
 * o `RateLimiter::limiter()` detecta chaves repetidas e troca cada uma pela `fallbackKey()`
 * (chave + tentativas + janela), então cada janela ganha contador próprio. Uma revisão de
 * 24/09 achou que dividiam um contador; foi este teste que mostrou que não. Se o framework
 * mudar esse comportamento, é aqui que vai aparecer.
 */
class LimitesPorMinutoEPorHoraValemOsDoisTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_login_do_painel_aceita_3_por_minuto_e_para_em_10_por_hora(): void
    {
        Route::middleware('throttle:painel-login')->get('/_teste/painel-login', fn () => 'ok');

        $this->assertJanelas('/_teste/painel-login', porMinuto: 3, porHora: 10);
    }

    public function test_o_codigo_do_dois_fatores_aceita_5_por_minuto_e_para_em_20_por_hora(): void
    {
        Route::middleware(['web', 'throttle:dois-fatores'])->get('/_teste/dois-fatores', fn () => 'ok');

        $this->actingAs(User::factory()->create());

        $this->assertJanelas('/_teste/dois-fatores', porMinuto: 5, porHora: 20);
    }

    /**
     * Minuto a minuto: passam exatamente `porMinuto` e a seguinte é barrada; somadas as
     * janelas, a de número `porHora + 1` é barrada mesmo num minuto novo — e passa de novo
     * quando a hora vira.
     */
    private function assertJanelas(string $url, int $porMinuto, int $porHora): void
    {
        $passaram = 0;

        while ($passaram < $porHora) {
            if ($passaram > 0) {
                $this->travel(61)->seconds();
            }

            $lote = min($porMinuto, $porHora - $passaram);
            for ($i = 0; $i < $lote; $i++) {
                $this->get($url)->assertOk();
                $passaram++;
            }

            if ($lote === $porMinuto) {
                $this->get($url)->assertStatus(429);
            }
        }

        $this->travel(61)->seconds();
        $this->get($url)->assertStatus(429);

        $this->travel(1)->hours();
        $this->get($url)->assertOk();
    }
}
