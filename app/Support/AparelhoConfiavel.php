<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * "Confiar neste aparelho por 7 dias" — a caixa da segunda etapa do login.
 *
 * Quem a marca e acerta o código recebe um cookie que, por 7 dias, dispensa o código NESTE
 * navegador e PARA ESTA conta. A senha continua sendo pedida em todo login; o que se pula é
 * só o código do autenticador. Todo o resto do login segue igual: limite de tentativas,
 * banido barrado antes de entrar, sessão regenerada, "lembrar de mim" do formulário.
 *
 * **O cookie (`sm-aparelho-confiavel`, 7 dias, httpOnly, SameSite=Lax, `secure` conforme a
 * sessão, cifrado pelo EncryptCookies)** guarda até {@see self::MAXIMO_DE_CONTAS} entradas
 * `id:validade:assinatura` — uma por conta, para o computador de casa servir ao titular e a
 * um dependente sem que a confiança de um apague a do outro. Cada entrada só vale para o
 * id que carrega: a de A nunca pula o desafio de B, mesmo no mesmo navegador.
 *
 * **A assinatura** é uma HMAC (com a APP_KEY) sobre o id, a validade e um "carimbo de
 * confiança" da conta: `two_factor_trust_version`, o hash da senha, o instante em que o 2FA
 * foi confirmado e o segredo TOTP. Por isso ela não pode ser forjada (sem a APP_KEY), nem
 * esticada (a validade está dentro dela), nem levada para outra conta (o id também), e morre
 * sozinha quando:
 *  - a senha muda, por QUALQUER caminho — inclusive os que ainda não existem —, porque o
 *    hash muda (e "Encerrar outras sessões" regrava o hash);
 *  - o 2FA é desligado e religado (segredo e `two_factor_confirmed_at` novos);
 *  - `User::revogarAparelhosConfiaveis()` soma 1 na versão: trocar os códigos de
 *    recuperação, o botão "Esquecer todos os aparelhos confiáveis" e, por garantia, os
 *    caminhos de senha e de sessões acima.
 *
 * Nunca o `remember_token`: ele é reciclado a cada logout, e a confiança cairia sempre que a
 * pessoa saísse da conta — que é justamente o que se faz num aparelho que se quer confiável.
 *
 * A validade é FIXA (7 dias a partir do código), não renovada a cada login: "confiar por 7
 * dias" significa pedir o código de novo em no máximo 7 dias.
 */
final class AparelhoConfiavel
{
    public const COOKIE = 'sm-aparelho-confiavel';

    public const DIAS = 7;

    /** Contas por navegador. Além disso, a mais antiga sai. */
    public const MAXIMO_DE_CONTAS = 5;

    /** Separa o propósito desta HMAC de qualquer outra feita com a mesma APP_KEY. */
    private const CONTEXTO = 'stabilmoney:aparelho-confiavel:v1';

    /**
     * Até quando ESTE aparelho dispensa o código desta conta — ou null, se não dispensa.
     * Só faz sentido com o 2FA ligado; sem ele, não há o que dispensar.
     */
    public static function validoAte(Request $request, User $user): ?CarbonImmutable
    {
        if (! $user->temDoisFatores()) {
            return null;
        }

        $agora = now()->getTimestamp();

        foreach (self::entradas($request) as [$id, $validade, $assinatura]) {
            if ($id !== (int) $user->getKey() || $validade <= $agora) {
                continue;
            }

            // Validade além de 7 dias a partir de agora não sai deste código. Só a APP_KEY
            // a produziria — e, se ela vazou, ao menos a confiança não dura para sempre.
            if ($validade > $agora + self::DIAS * 86400) {
                continue;
            }

            if (hash_equals(self::assinatura($user, $validade), $assinatura)) {
                return CarbonImmutable::createFromTimestamp($validade, config('app.timezone'));
            }
        }

        return null;
    }

    public static function confia(Request $request, User $user): bool
    {
        return self::validoAte($request, $user) !== null;
    }

    /** Marca este aparelho como confiável para `$user` pelos próximos 7 dias. */
    public static function confiar(Request $request, User $user): void
    {
        $validade = now()->addDays(self::DIAS)->getTimestamp();

        $entradas = array_merge(
            [[(int) $user->getKey(), $validade, self::assinatura($user, $validade)]],
            self::outrasValidas($request, $user),
        );

        self::gravar(array_slice($entradas, 0, self::MAXIMO_DE_CONTAS));
    }

    /** Tira `$user` da lista deste aparelho (as outras contas continuam confiáveis). */
    public static function esquecerNeste(Request $request, User $user): void
    {
        if ($request->cookie(self::COOKIE) === null) {
            return;
        }

        self::gravar(self::outrasValidas($request, $user));
    }

    /**
     * As entradas das OUTRAS contas que ainda estão no prazo. A assinatura delas não é
     * conferida aqui (seria preciso carregar cada conta); quem confere é `validoAte()`, no
     * login de cada uma.
     *
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private static function outrasValidas(Request $request, User $user): array
    {
        $agora = now()->getTimestamp();

        return array_values(array_filter(
            self::entradas($request),
            fn (array $e) => $e[0] !== (int) $user->getKey() && $e[1] > $agora,
        ));
    }

    /** @param list<array{0: int, 1: int, 2: string}> $entradas */
    private static function gravar(array $entradas): void
    {
        if ($entradas === []) {
            Cookie::queue(Cookie::forget(self::COOKIE));

            return;
        }

        $valor = implode(',', array_map(fn (array $e) => implode(':', $e), $entradas));

        // Caminho e domínio: os da sessão (padrão do CookieJar). `secure` também segue a
        // sessão — em produção, só HTTPS.
        Cookie::queue(Cookie::make(
            self::COOKIE,
            $valor,
            self::DIAS * 24 * 60,
            null,
            null,
            config('session.secure'),
            true,   // httpOnly: o JavaScript da página não lê
            false,  // cifrado pelo EncryptCookies
            'lax',
        ));
    }

    /**
     * O que o navegador mandou, já decifrado pelo EncryptCookies (um cookie adulterado chega
     * como null). Entrada fora do formato é descartada, nunca "consertada".
     *
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private static function entradas(Request $request): array
    {
        $valor = $request->cookie(self::COOKIE);

        if (! is_string($valor) || $valor === '' || strlen($valor) > 2048) {
            return [];
        }

        $entradas = [];

        foreach (explode(',', $valor) as $pedaco) {
            if (preg_match('/^(\d{1,19}):(\d{1,12}):([0-9a-f]{64})$/D', $pedaco, $m) === 1) {
                $entradas[] = [(int) $m[1], (int) $m[2], $m[3]];
            }
        }

        return $entradas;
    }

    /** A HMAC que amarra a entrada à conta, à validade e ao estado de segurança da conta. */
    private static function assinatura(User $user, int $validade): string
    {
        $carimbo = implode('|', [
            self::CONTEXTO,
            (int) $user->getKey(),
            $validade,
            (int) $user->two_factor_trust_version,
            (string) $user->getAuthPassword(),
            (string) $user->two_factor_confirmed_at?->getTimestamp(),
            hash('sha256', (string) $user->two_factor_secret),
        ]);

        return hash_hmac('sha256', $carimbo, (string) config('app.key'));
    }
}
