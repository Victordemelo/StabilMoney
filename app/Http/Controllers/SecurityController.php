<?php

namespace App\Http\Controllers;

use App\Mail\AlertaDeSeguranca;
use App\Support\BrowserSessions;
use App\Support\ContextoDeSeguranca;
use App\Support\Notificador;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Ações de segurança da conta (tela Configurações › Segurança).
 * Por enquanto: encerrar as demais sessões/dispositivos conectados.
 */
class SecurityController extends Controller
{
    /**
     * Encerra todas as outras sessões do usuário, mantendo a atual.
     * Exige a senha atual (confirmação), remove as linhas das outras
     * sessões da tabela `sessions` — é isso que efetivamente desconecta
     * os outros navegadores no driver de sessão `database` — e troca o
     * remember token, que é o que invalida os cookies de "lembrar de mim".
     */
    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        $request->validateWithBag('logoutOtherSessions', [
            'password' => ['required', 'current_password'],
        ], [
            'password.required' => 'Informe sua senha para continuar.',
            'password.current_password' => 'A senha informada está incorreta.',
        ]);

        $user = $request->user();

        // Token novo = os cookies de "lembrar de mim" dos outros aparelhos deixam de
        // valer. Sem isto, "encerrar" não encerrava ninguém que tivesse marcado a caixa
        // (e ela vem marcada no login): o cookie re-autentica SEM sessão nenhuma, e o
        // guard confere só id + token — apagar as linhas de `sessions`, abaixo, não o
        // alcança, e `logoutOtherDevices` NÃO troca o token (só regrava o hash da senha,
        // que só o `AuthenticateSession` conferiria, e ele não está ligado).
        //
        // ⚠️ ANTES do `logoutOtherDevices`, e já salvo: se ESTE aparelho entrou com
        // "lembrar de mim", é lá que ele ganha um cookie novo — montado com o token que
        // estiver no model naquele instante. Trocado depois, o aparelho que pediu a
        // limpeza seria o único a perder o "lembrar de mim".
        $user->setRememberToken(Str::random(60));
        $user->save();

        // Regrava o hash da senha (com a mesma senha) e devolve a este aparelho o cookie
        // de "lembrar de mim" com o token novo, se ele tinha um.
        Auth::logoutOtherDevices($request->input('password'));

        // Remove as linhas das outras sessões (o que de fato as desconecta).
        $encerradas = BrowserSessions::purgeForUser(
            $user->getAuthIdentifier(),
            exceptSessionId: $request->session()->getId(),
        );

        // Avisa quantos aparelhos caíram. Serve para os dois lados: confirma ao dono que
        // a limpeza funcionou, e denuncia a ele se quem mandou desconectar foi outra
        // pessoa — porque nesse caso o aparelho derrubado foi o dele.
        Notificador::avisar($user, AlertaDeSeguranca::sessoesEncerradas(
            $user,
            ContextoDeSeguranca::doRequest($request),
            $encerradas,
        ));

        return back()->with('status', 'sessions-cleared');
    }
}
