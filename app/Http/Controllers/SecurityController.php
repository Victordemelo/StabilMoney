<?php

namespace App\Http\Controllers;

use App\Mail\AlertaDeSeguranca;
use App\Support\BrowserSessions;
use App\Support\ContextoDeSeguranca;
use App\Support\Notificador;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ações de segurança da conta (tela Configurações › Segurança).
 * Por enquanto: encerrar as demais sessões/dispositivos conectados.
 */
class SecurityController extends Controller
{
    /**
     * Encerra todas as outras sessões do usuário, mantendo a atual.
     * Exige a senha atual (confirmação) e remove as linhas das outras
     * sessões da tabela `sessions` — é isso que efetivamente desconecta
     * os outros navegadores no driver de sessão `database`.
     */
    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        $request->validateWithBag('logoutOtherSessions', [
            'password' => ['required', 'current_password'],
        ], [
            'password.required' => 'Informe sua senha para continuar.',
            'password.current_password' => 'A senha informada está incorreta.',
        ]);

        // Recicla o hash de senha da sessão atual e o remember token.
        Auth::logoutOtherDevices($request->input('password'));

        // Remove as linhas das outras sessões (o que de fato as desconecta).
        $encerradas = BrowserSessions::purgeForUser(
            $request->user()->getAuthIdentifier(),
            exceptSessionId: $request->session()->getId(),
        );

        // Avisa quantos aparelhos caíram. Serve para os dois lados: confirma ao dono que
        // a limpeza funcionou, e denuncia a ele se quem mandou desconectar foi outra
        // pessoa — porque nesse caso o aparelho derrubado foi o dele.
        Notificador::avisar($request->user(), AlertaDeSeguranca::sessoesEncerradas(
            $request->user(),
            ContextoDeSeguranca::doRequest($request),
            $encerradas,
        ));

        return back()->with('status', 'sessions-cleared');
    }
}
