<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
