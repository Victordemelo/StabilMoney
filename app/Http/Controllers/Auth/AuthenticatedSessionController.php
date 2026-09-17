<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse|JsonResponse
    {
        // Credenciais erradas / throttle: authenticate() lança ValidationException,
        // que para requisições AJAX (expectsJson) já volta como 422 JSON com os erros.
        $request->authenticate();

        // Verificação em duas etapas (opcional): quem ligou o 2FA ainda NÃO está logado
        // aqui — a senha foi conferida sem abrir sessão. Vai para a tela do código, e a
        // sessão só nasce lá. Quem não ligou (o padrão) segue direto, como sempre.
        if ($request->precisaDeSegundaEtapa()) {
            TwoFactorChallengeController::aguardar(
                $request->session(),
                $request->usuarioAutenticado(),
                $request->boolean('remember'),
            );

            $destino = route('two-factor.login');

            // O login por AJAX (sm/auth.js) só navega para o `redirect` que vier no JSON,
            // então a segunda etapa funciona sem uma linha de JavaScript nova.
            return $request->expectsJson()
                ? response()->json(['redirect' => $destino])
                : redirect()->to($destino);
        }

        $request->session()->regenerate();

        $redirect = redirect()->intended(route('dashboard', absolute: false));

        // Login por AJAX (tela v2): devolve o destino p/ o JS redirecionar sem reload.
        if ($request->expectsJson()) {
            return response()->json(['redirect' => $redirect->getTargetUrl()]);
        }

        return $redirect;
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        $resposta = redirect('/');

        // Manda o navegador apagar o cache HTTP do site ao sair, para uma página
        // autenticada guardada ali não reaparecer depois do logout (no "Voltar", por
        // exemplo).
        //
        // ⚠️ "cache" NÃO alcança o Cache Storage do service worker (`caches.*`). A
        // premissa antiga era essa, e estava errada — achado P-2 da auditoria de PWA
        // (docs/auditoria-pwa-e-painel-admin-2026-09-06.md). É no Cache Storage que o SW
        // guarda `/transactions/create`, HTML com as contas, as categorias e a família.
        // Quem apaga aquilo é o próprio service worker: ele vê este POST passar e limpa,
        // e limpa de novo quando o /login responde sem sessão — que é onde este redirect
        // termina. Ver `HTML_AUTENTICADO` em resources/views/pwa/service-worker.blade.php
        // e o ServiceWorkerApagaHtmlAutenticadoTest.
        //
        // Só "cache", DE PROPÓSITO: o valor que limparia o Cache Storage é "storage", que
        // apagaria junto o IndexedDB da fila offline — destruindo em silêncio lançamentos
        // ainda não sincronizados — e desregistraria o service worker, levando o
        // Background Sync. Perder o cache custa um download; perder a fila custa o dado
        // do usuário.
        $resposta->headers->set('Clear-Site-Data', '"cache"');

        return $resposta;
    }
}
