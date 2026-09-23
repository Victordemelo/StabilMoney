<?php

// Lista de proxies confiáveis, normalizada ANTES de virar config (ver o comentário abaixo).
// Fica fora do array de propósito: com `config:cache`, o que é gravado em
// bootstrap/cache/config.php é o RESULTADO desta conta — uma lista simples ou null.
$proxiesConfiaveis = (static function (string $lista): ?array {
    $aceitos = [];

    foreach (preg_split('/[\s,]+/', trim($lista), -1, PREG_SPLIT_NO_EMPTY) as $item) {
        [$ip, $prefixo] = array_pad(explode('/', $item, 2), 2, null);

        $versao = match (true) {
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 4,
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 6,
            default => null,
        };

        // "*", "**", "REMOTE_ADDR", nome de host, IP inválido: nada disso vira confiança.
        if ($versao === null) {
            continue;
        }

        // Faixa CIDR: o prefixo tem de ser um número de 1 até o tamanho do endereço. O /0
        // ("0.0.0.0/0", "::/0") é o curinga disfarçado — confiaria na internet inteira.
        if ($prefixo !== null) {
            $maximo = $versao === 4 ? 32 : 128;

            if (! ctype_digit($prefixo) || (int) $prefixo < 1 || (int) $prefixo > $maximo) {
                continue;
            }
        }

        $aceitos[] = $item;
    }

    return $aceitos === [] ? null : array_values(array_unique($aceitos));
})((string) env('TRUSTED_PROXIES', ''));

return [

    /*
    |--------------------------------------------------------------------------
    | Proxies confiáveis
    |--------------------------------------------------------------------------
    |
    | Em produção a requisição chega assim:
    |
    |   visitante → Cloudflare → nginx do host da VPS → Apache do container → Laravel
    |
    | Para o PHP, TODA requisição vem do nginx: o endereço que ele enxerga
    | (REMOTE_ADDR) é o gateway da rede Docker, o mesmo para todo mundo, e a
    | conexão é http (quem fala TLS é o nginx). O IP de verdade e o "era https"
    | viajam nos cabeçalhos X-Forwarded-*, e o Laravel só acredita neles quando a
    | conexão vem de um endereço desta lista — quem lê a chave é o
    | Illuminate\Http\Middleware\TrustProxies, global por padrão. QUAIS cabeçalhos
    | ele aceita fica no bootstrap/app.php.
    |
    | Sem a lista (o padrão), atrás do proxy:
    |  - os limites por IP (cadastro, "esqueci a senha", login) viram UM limite para
    |    o site inteiro: cinco cadastros de quaisquer pessoas num minuto e o sexto
    |    visitante leva 429;
    |  - a tela de sessões, a prova do aceite dos termos (terms_accepted_ip) e o
    |    histórico do painel gravam o IP do gateway para todo mundo;
    |  - o app se acha em http: o HSTS não sai e os links montados a partir da
    |    requisição saem em http.
    |
    | Com endereço a MAIS na lista, é pior: quem se conecta a partir dele escolhe o
    | próprio IP e o próprio "https" mandando o cabeçalho que quiser.
    |
    | TRUSTED_PROXIES: IPs ou faixas CIDR separados por vírgula. Em produção, o
    | gateway FIXO da rede do docker-compose.prod.yml:
    |
    |   TRUSTED_PROXIES=172.16.80.1
    |
    | (é por ele que o nginx do host chega ao container pela porta publicada —
    | medido em 23/09/2026: conexão do host para 127.0.0.1:<porta> aparece no
    | container como o gateway da rede). Vazio ou ausente — desenvolvimento e
    | testes — ninguém é confiável.
    |
    | Recusados de propósito, sem erro: "*" e "**" (o curinga do framework, que
    | confia em QUALQUER um que se conecte), "0.0.0.0/0" e "::/0" (o mesmo curinga
    | escrito como faixa) e tudo que não for IP ou faixa válida. Viram "ninguém é
    | confiável" — falha que aparece (todo mundo com o IP do gateway na tela de
    | sessões, HSTS ausente), em vez de confiança que ninguém vê.
    |
    */

    'proxies' => $proxiesConfiaveis,

];
