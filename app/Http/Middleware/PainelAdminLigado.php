<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interruptor geral do painel: desligado, ele não existe.
 *
 * **404, nunca 403.** A diferença importa: 403 confirma que há um painel ali e convida
 * o atacante a insistir; 404 é indistinguível de uma URL que nunca existiu. Pelo mesmo
 * motivo isto roda ANTES de qualquer autenticação — não deve nem existir formulário de
 * login para descobrir.
 *
 * ## Roda ANTES do roteador, na pilha global (auditoria de 06/09/2026, A-1)
 *
 * Só como middleware de rota ele chegava tarde, e um 404 só esconde alguma coisa se a
 * resposta INTEIRA for igual à de `/nao_existe`. Antes de a rota chegar nele:
 *
 *   - o roteador respondia **405** ao verbo errado e **200 com `Allow`** ao OPTIONS — ele
 *     faz isso sozinho quando o caminho existe em outro verbo;
 *   - o grupo `web` respondia **419** ao POST sem token; com token, o `throttle` do painel
 *     carimbava `X-RateLimit-*`;
 *   - e até o 404 saía diferente: atravessava o grupo `web` e vinha com cookie de sessão
 *     e CSP, que o 404 de uma URL inexistente não tem (esse nasce no roteador).
 *
 * No FIM da pilha global (`bootstrap/app.php`) ele roda exatamente onde o roteador rodaria:
 * o que vem antes (manutenção, tamanho do POST, CORS) trata o prefixo como qualquer URL, e
 * o que vem depois (roteador, grupo `web`, CSRF, sessão, throttle) nem chega a rodar.
 * De quebra, scanner batendo no prefixo não abre linha em `sessions`.
 *
 * ## E continua na rota, como rede de segurança
 *
 * Na pilha global ele decide pelo CAMINHO (`admin.path`); na rota, a rota já é do painel e
 * ele não olha caminho nenhum. É isso que fecha a porta quando os dois divergem — cache de
 * rotas velho depois de trocar `ADMIN_PANEL_PATH`: aí o 404 volta a se distinguir pelos
 * headers, mas o painel continua fechado.
 *
 * Registrar as rotas só com o painel ligado também sumiria com o 405/419, e foi descartado:
 * congelaria a decisão no `route:cache` (ligar no `.env` deixaria de bastar) e todo
 * `route('painel.*')` quebraria com o painel desligado.
 */
class PainelAdminLigado
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('admin.enabled')) {
            return $next($request);
        }

        // Na pilha global ainda não existe rota casada: decide pelo caminho. Numa rota,
        // este middleware só está lá se ela for do painel.
        if ($request->route() !== null || $this->ehCaminhoDoPainel($request)) {
            $this->responderComoUrlInexistente($request);
        }

        return $next($request);
    }

    /**
     * O prefixo como SEGMENTO: `painel_admin` e o que vem abaixo dele, nunca
     * `painel_administrativo`. O `is()` compara com o caminho decodificado, como o roteador
     * faz — `painel%5Fadmin` também é o painel.
     */
    private function ehCaminhoDoPainel(Request $request): bool
    {
        $prefixo = trim((string) config('admin.path'), '/');

        // Prefixo vazio poria o painel na raiz, e esconder "tudo abaixo de /" derrubaria o
        // app inteiro. Não é configuração válida, e não é aqui que ela se corrige.
        if ($prefixo === '') {
            return false;
        }

        return $request->is($prefixo, $prefixo.'/*');
    }

    /**
     * O 404 que o roteador daria se não houvesse rota nenhuma — porque é ele que responde.
     *
     * Uma coleção de rotas VAZIA é o roteador sem rotas: `match()` lança a mesma exceção, com
     * a mesma mensagem (`The route X could not be found.`). A mensagem não é detalhe: ela vai
     * no JSON de erro mesmo sem debug (`Handler::convertExceptionToArray`), e um `abort(404)`
     * — mensagem vazia — seria reconhecível por ela. Copiar a string também funcionaria, até
     * uma atualização do framework mudar o texto.
     */
    private function responderComoUrlInexistente(Request $request): never
    {
        (new RouteCollection)->match($request);

        // Inalcançável: `match()` numa coleção vazia sempre lança. Fica para a porta
        // continuar fechada se um dia deixar de lançar.
        abort(404);
    }
}
