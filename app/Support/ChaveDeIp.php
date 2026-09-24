<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * A chave "por IP" dos limites de tentativa (cadastro, "esqueci a senha", login, código
 * do 2FA, painel).
 *
 * IPv4: o endereço, como sempre. IPv6: a REDE /64 do endereço, e não o endereço inteiro.
 *
 * Em IPv6, quem recebe um endereço recebe a rede toda: a menor rede entregue a uma casa, a
 * um celular ou a uma VPS é um /64 — 2^64 endereços, e o próprio visitante escolhe de qual
 * sai cada requisição, sem pedir a ninguém. Com o endereço inteiro como chave, cada
 * tentativa podia sair de um endereço novo e cair num balde novo: o limite não existia
 * para quem tem IPv6. E a Cloudflare atende em IPv6 por padrão e repassa o endereço IPv6
 * do visitante (CF-Connecting-IP → nginx → X-Forwarded-For → `$request->ip()`).
 *
 * Um /64 é para o IPv6 o que um IP é para o IPv4 atrás de NAT: uma casa, um aparelho, uma
 * máquina — o mesmo agrupamento que o limite por IPv4 já fazia.
 *
 * Só para CHAVE de limite. Onde o IP é registro (tela de sessões, prova do aceite, histórico
 * do painel), continua o endereço completo de `$request->ip()`.
 */
final class ChaveDeIp
{
    /** Quantos bits iniciais do IPv6 identificam UM visitante. */
    public const PREFIXO_IPV6 = 64;

    public static function da(Request $request): string
    {
        return self::de($request->ip());
    }

    public static function de(?string $ip): string
    {
        $ip = (string) $ip;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            // IPv4, ou algo que não é IP: como veio.
            return $ip;
        }

        $bytes = (string) inet_pton($ip);

        // IPv4 escrito como IPv6 (::ffff:203.0.113.7) é o IPv4 — senão todo IPv4 nesse
        // formato cairia no mesmo /64 (os 64 primeiros bits são zero para todos eles).
        if (str_starts_with($bytes, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($bytes, 12));
        }

        $rede = substr($bytes, 0, intdiv(self::PREFIXO_IPV6, 8)).str_repeat("\0", 16 - intdiv(self::PREFIXO_IPV6, 8));

        return inet_ntop($rede).'/'.self::PREFIXO_IPV6;
    }
}
