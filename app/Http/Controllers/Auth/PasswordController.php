<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\AlertaDeSeguranca;
use App\Support\BrowserSessions;
use App\Support\ContextoDeSeguranca;
use App\Support\Notificador;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
            'password_changed_at' => now(),
        ]);

        // Trocar a senha PRECISA desconectar os outros dispositivos: é a ação que a
        // pessoa toma justamente ao suspeitar de invasão, e sem isto o invasor com o
        // cookie continuava logado. `logoutOtherDevices` recicla o hash na sessão; o
        // que de fato derruba as outras no driver `database` é apagar as linhas.
        Auth::logoutOtherDevices($validated['password']);
        BrowserSessions::purgeForUser(
            $request->user()->getKey(),
            exceptSessionId: $request->session()->getId(),
        );

        // Derrubar as outras sessões protege contra quem NÃO tem a senha. Este aviso
        // cobre o caso oposto e pior: quem já entrou e está trocando a senha justamente
        // para trancar o dono do lado de fora. O e-mail é o único canal que o invasor
        // não controla.
        Notificador::avisar($request->user(), AlertaDeSeguranca::senhaAlterada(
            $request->user(),
            ContextoDeSeguranca::doRequest($request),
        ));

        return back()->with('status', 'password-updated');
    }
}
