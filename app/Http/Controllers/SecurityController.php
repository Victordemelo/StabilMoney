<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return back()->with('status', 'sessions-cleared');
    }
}
