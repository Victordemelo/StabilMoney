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

        // Manda o navegador apagar o cache do site ao sair. O service worker do PWA
        // guarda `/transactions/create` — HTML autenticado, com nomes de contas, de
        // categorias e da família — e esse cache sobrevive ao logout. Até aqui a limpeza
        // dependia de o JS rodar na tela de login; num aparelho compartilhado, quem
        // navegasse offline para aquela URL veria os dados do usuário anterior.
        //
        // Só "cache", DE PROPÓSITO: incluir "storage" apagaria o IndexedDB da fila
        // offline e destruiria silenciosamente lançamentos ainda não sincronizados.
        // Perder o cache custa um download; perder a fila custa o dado do usuário.
        $resposta->headers->set('Clear-Site-Data', '"cache"');

        return $resposta;
    }
}
