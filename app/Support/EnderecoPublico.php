<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\URL;

/**
 * O endereço PÚBLICO do app — o do APP_URL — como a única origem dos links e do Host
 * aceito, em produção.
 *
 * O ataque que isto fecha é o "password reset poisoning". O link do "Esqueci a senha" é
 * montado com o Host da REQUISIÇÃO (`ResetPassword::resetUrl()` chama `url(route(...))`):
 * quem pedisse a redefinição para o e-mail de outra pessoa mandando `Host: atacante.com`
 * fazia a VÍTIMA receber, do nosso servidor e com a nossa cara, um link para
 * atacante.com/reset-password/<token>. Um clique e o token — e a conta — eram do atacante.
 * O mesmo valia para o link de confirmação do cadastro e o da troca de e-mail, URLs
 * assinadas montadas do mesmo jeito.
 *
 * Com a Cloudflare na frente, o caminho normal só entrega o nosso Host; o buraco é quem fala
 * DIRETO com o IP da VPS. Por isso são três camadas, e cada uma segura sozinha:
 *
 *  1. nginx do host: um `server` padrão que responde 444 (fecha a conexão) a Host que não é
 *     de nenhum site (deploy/nginx/00-host-desconhecido.conf);
 *  2. `TrustHosts` (bootstrap/app.php): em produção, Host diferente do APP_URL recebe 400
 *     antes de chegar a qualquer rota — `padroesDeHostConfiavel()`;
 *  3. `fixarEmProducao()`: em produção, TODO link gerado sai com a raiz do APP_URL, em https,
 *     seja qual for o Host que chegou. Mesmo que as duas primeiras falhem, o link não aponta
 *     para fora.
 *
 * Fora de produção nada disto vale, de propósito: em desenvolvimento o app é aberto por
 * http://localhost:8001 E pelo IP da máquina no Wi-Fi (testar no celular, ver CLAUDE.md), e os
 * links têm de seguir o endereço que foi aberto. Com a raiz presa ao APP_URL de dev, o celular
 * receberia links para "localhost" — que, no celular, é o próprio celular.
 */
final class EnderecoPublico
{
    /**
     * Padrão que não casa com host NENHUM (lookahead negativo vazio). É o que sobra quando o
     * APP_URL não tem host — ver `padroesDeHostConfiavel()`.
     */
    public const NENHUM_HOST = '(?!)';

    /**
     * Os padrões de Host que o `TrustHosts` aceita em produção: só o host EXATO do APP_URL.
     *
     * Sem subdomínios, ao contrário do padrão do framework (`^(.+\.)?host$`): nenhum
     * subdomínio de stabilmoney.victordemelo.com.br existe, e aceitar o que não existe não
     * compra nada. São regex sem delimitador — o Symfony as envolve em `{...}i`.
     *
     * APP_URL sem host (vazio, "https://") dá `NENHUM_HOST`: em produção, o app recusa todo
     * Host com 400. O `TrustHosts` do framework, com a lista vazia, voltaria a aceitar
     * QUALQUER Host — a porta aberta sem aviso. Falhar no primeiro teste do deploy (o /up
     * responde 400) é melhor, e o scripts/deploy.sh confere o APP_URL antes de publicar.
     *
     * @return list<string>
     */
    public static function padroesDeHostConfiavel(): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return [self::NENHUM_HOST];
        }

        return ['^'.preg_quote(strtolower($host)).'$'];
    }

    /**
     * Em produção, todo link gerado pelo app — `url()`, `route()`, `asset()`, os links
     * assinados dos e-mails — sai com a raiz do APP_URL e em https, nunca com o Host nem o
     * esquema da requisição. Chamado no boot do AppServiceProvider.
     *
     * O https vai forçado também porque, com o TRUSTED_PROXIES errado, o Laravel enxerga a
     * requisição como http (o Apache do container não tem TLS; quem tem é o nginx do host). Os
     * assets sairiam em http:// numa página servida em https, o navegador os bloquearia (mixed
     * content) e o app abriria sem CSS e sem JavaScript.
     *
     * ⚠️ A CONFERÊNCIA dos links assinados (middleware `signed`) continua olhando a URL da
     * requisição, não esta raiz. Com o TRUSTED_PROXIES errado, o link sai em https (daqui) e
     * a requisição chega "em http" — a assinatura não bate e o link responde 403. O conserto é
     * o TRUSTED_PROXIES, nunca desligar isto.
     */
    public static function fixarEmProducao(Application $app): void
    {
        if (! $app->isProduction()) {
            return;
        }

        $raiz = rtrim((string) $app['config']->get('app.url'), '/');

        if ($raiz !== '') {
            URL::forceRootUrl($raiz);
        }

        URL::forceScheme('https');
    }
}
