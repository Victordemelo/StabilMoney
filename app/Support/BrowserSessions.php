<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Lista as sessões ativas do usuário a partir da tabela `sessions`
 * (driver de sessão `database`). Sem dependência externa: o user-agent é
 * interpretado por correspondência simples — suficiente para a UI de
 * "dispositivos conectados" da tela de Segurança.
 *
 * Quando o driver não é `database` (ex.: ambiente de teste com `array`),
 * devolve uma coleção vazia — a view trata como "sessão atual apenas".
 */
class BrowserSessions
{
    /**
     * Sessões do usuário autenticado, da mais recente para a mais antiga.
     *
     * @return Collection<int, object{device:string, browser:string, platform:string, ip:?string, is_current:bool, last_active:Carbon}>
     */
    public static function forUser(Request $request): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        $currentId = $request->session()->getId();

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($s) => (object) [
                'device' => self::isMobile($s->user_agent) ? 'Celular' : 'Computador',
                'browser' => self::browser($s->user_agent),
                'platform' => self::platform($s->user_agent),
                'ip' => $s->ip_address,
                'is_current' => $s->id === $currentId,
                'last_active' => Carbon::createFromTimestamp($s->last_activity),
            ]);
    }

    /**
     * Apaga as linhas de sessão do usuário — é isso que efetivamente desconecta
     * os navegadores no driver `database`, e o que remove IP e user-agent guardados
     * junto da sessão.
     *
     * @param  string|null  $exceptSessionId  sessão a preservar (ex.: a atual, ao
     *                                        encerrar "as outras"); null apaga todas.
     * @return int linhas removidas
     */
    public static function purgeForUser(int|string $userId, ?string $exceptSessionId = null): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        $query = DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $userId);

        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }

        return $query->delete();
    }

    /**
     * Apaga as sessões em que o guard `$guard` está autenticado como `$id` — procurando o
     * login DENTRO do payload, e não na coluna `user_id`.
     *
     * Existe por causa do painel administrativo (`admin:zerar-2fa`). A coluna `user_id` é
     * preenchida pelo guard PADRÃO (`DatabaseSessionHandler::userId()` pede o
     * `Contracts\Auth\Guard`, que é o `web`), e nada no painel troca o padrão: numa sessão do
     * painel ela guarda o cliente do APP logado no mesmo navegador — ou null —, nunca o
     * admin. Um `purgeForUser($admin->id)` erraria duas vezes: não alcançaria sessão nenhuma
     * do admin, e derrubaria o cliente do app que tivesse o mesmo número de id (as duas
     * tabelas contam a partir de 1).
     *
     * O login de cada guard mora no payload, na chave do próprio guard
     * (`SessionGuard::getName()`, "login_admin_<sha1>"), cifrado quando `session.encrypt`
     * está ligado (o padrão). Por isso a leitura linha a linha, desfazendo o que a `Store`
     * do framework faz ao gravar.
     *
     * A linha sai INTEIRA, mesmo quando o navegador também estava logado no app: a sessão é
     * uma só (um cookie). Tirar só as chaves do admin e regravar o resto seria desfeito pela
     * primeira requisição que estivesse em andamento naquela sessão — ela grava de volta, no
     * fim, tudo o que leu no começo. Apagar resiste a isso (a gravação dela vira um UPDATE de
     * zero linhas). Quem estava no app entra de novo (ou volta sozinho pelo "lembrar de mim").
     *
     * Payload que não abre (chave do app trocada, lixo) é pulado: a própria aplicação também
     * não conseguiria lê-lo, então ele não autentica ninguém.
     *
     * @return int linhas removidas (0 quando o driver não é `database`)
     */
    public static function purgeForGuard(string $guard, int|string $id): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        $chave = Auth::guard($guard)->getName();
        $tabela = fn () => DB::connection(config('session.connection'))->table(config('session.table', 'sessions'));

        $ids = [];

        // Lendo em lotes, pela chave: a tabela é de todo mundo, e carregá-la inteira num
        // array só para achar as linhas de uma pessoa pesaria à toa.
        foreach ($tabela()->select(['id', 'payload'])->lazyById(200, 'id') as $linha) {
            $atributos = self::lerPayload((string) $linha->payload);

            if (isset($atributos[$chave]) && (string) $atributos[$chave] === (string) $id) {
                $ids[] = $linha->id;
            }
        }

        $apagadas = 0;

        foreach (array_chunk($ids, 200) as $lote) {
            $apagadas += $tabela()->whereIn('id', $lote)->delete();
        }

        return $apagadas;
    }

    /**
     * Os atributos de uma sessão como a `Store` do framework os leria: base64 → decifra (se
     * `session.encrypt`) → desserializa (php ou json, conforme `session.serialization`).
     *
     * `allowed_classes => false`: aqui só interessam chaves e números; objeto guardado na
     * sessão (a bag de erros de validação, por exemplo) volta como classe incompleta, sem
     * que nada seja instanciado por um varredor que lê a sessão de todo mundo.
     *
     * @return array<string, mixed>
     */
    private static function lerPayload(string $payload): array
    {
        $dados = base64_decode($payload, true);

        if (! is_string($dados) || $dados === '') {
            return [];
        }

        if (config('session.encrypt')) {
            try {
                // Mesma chamada da `EncryptedStore::prepareForUnserialize`: a camada externa
                // desserializada aqui é só a string que a `Store` serializou.
                $dados = app('encrypter')->decrypt($dados);
            } catch (DecryptException) {
                return [];
            }

            if (! is_string($dados)) {
                return [];
            }
        }

        $atributos = config('session.serialization', 'php') === 'json'
            ? json_decode($dados, true)
            : @unserialize($dados, ['allowed_classes' => false]);

        return is_array($atributos) ? $atributos : [];
    }

    /**
     * Descrição curta do aparelho a partir do user-agent ("Chrome no Windows").
     *
     * Público porque os alertas de segurança por e-mail precisam da MESMA leitura que a
     * tela de dispositivos mostra: se o e-mail dissesse "Chrome/Windows" e a tela
     * "Edge no Windows", a pessoa não teria como cruzar as duas informações — que é
     * exatamente o que ela faz ao desconfiar de um acesso.
     */
    public static function descrever(?string $ua): string
    {
        if ($ua === null || $ua === '') {
            return 'Dispositivo desconhecido';
        }

        return self::browser($ua).' no '.self::platform($ua);
    }

    private static function isMobile(?string $ua): bool
    {
        return $ua !== null && preg_match('/Mobi|Android|iPhone|iPad|iPod/i', $ua) === 1;
    }

    private static function browser(?string $ua): string
    {
        if ($ua === null) {
            return 'Navegador desconhecido';
        }

        return match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'OPR'), str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Navegador desconhecido',
        };
    }

    private static function platform(?string $ua): string
    {
        if ($ua === null) {
            return 'Sistema desconhecido';
        }

        return match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad'), str_contains($ua, 'iPod') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Sistema desconhecido',
        };
    }
}
