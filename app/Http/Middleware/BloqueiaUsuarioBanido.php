<?php

namespace App\Http\Middleware;

use App\Support\BrowserSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta o acesso de quem foi banido, a cada requisição.
 *
 * **Por que middleware e não só uma checagem no login:** apagar as linhas de `sessions`
 * na hora do banimento derruba quem está logado, mas o cookie de "lembrar de mim"
 * re-autentica sozinho na requisição seguinte — o banido voltaria em silêncio. Aqui a
 * verificação acontece em TODA requisição autenticada, então não há porta de entrada
 * que escape: sessão viva, remember-me, ou login novo.
 *
 * Roda no grupo `web` e sai de graça para visitante (sem query, sem custo).
 */
class BloqueiaUsuarioBanido
{
    /**
     * O que o banido lê — em qualquer porta: aqui, no login (`LoginRequest`) e no desafio
     * do 2FA (`TwoFactorChallengeController`). Uma fonte só: o texto já esteve copiado em
     * dois lugares, e cópia de mensagem diverge no primeiro ajuste.
     *
     * Sem detalhe do motivo: quem precisa saber o porquê fala com o suporte, e o texto da
     * moderação não é para virar tela pública.
     */
    public static function mensagem(): string
    {
        return 'Esta conta está suspensa. Fale com o suporte em '.config('legal.contact_email').'.';
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Guard EXPLÍCITO: `$request->user()` devolve o guard padrão, que numa
        // requisição do painel é o `admin` — e Admin não tem (nem deve ter) banimento.
        $user = $request->user('web');

        if ($user && $user->estaBanido()) {
            // Limpa também as linhas de `sessions`: senão o remember-me recria a sessão
            // e a pessoa fica batendo nesta parede a cada request, deixando lixo no banco.
            BrowserSessions::purgeForUser($user->id);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => self::mensagem()]);
        }

        return $next($request);
    }
}
