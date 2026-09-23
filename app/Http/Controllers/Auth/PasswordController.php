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
use Illuminate\Support\Str;
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

        $user = $request->user();

        $user->fill([
            'password' => Hash::make($validated['password']),
            'password_changed_at' => now(),
        ]);

        // Token novo = todo cookie de "lembrar de mim" emitido antes deixa de valer. É a
        // metade que apagar as sessões (abaixo) não alcança: esse cookie re-autentica SEM
        // sessão nenhuma, e o guard confere só id + token — o hash de senha que vai dentro
        // dele só seria conferido pelo middleware `AuthenticateSession`, que não está
        // ligado. `logoutOtherDevices` não troca o token (só regrava o hash da senha), e a
        // caixa "Lembrar de mim" vem MARCADA no login: sem esta linha, o aparelho do
        // invasor seguia entrando depois da troca, que é justamente o que ela existe
        // para impedir. Mesma regra do NewPasswordController (A-2) e do
        // DependentController (A-6).
        //
        // ⚠️ ANTES do `logoutOtherDevices`, e já salvo: se ESTE aparelho entrou com
        // "lembrar de mim", é lá que ele ganha um cookie novo — montado com o token que
        // estiver no model naquele instante. Trocado depois, o cookie do próprio dono
        // sairia com o token velho e ele perderia o "lembrar de mim" sem motivo.
        $user->setRememberToken(Str::random(60));
        $user->save();

        // Trocar a senha PRECISA desconectar os outros dispositivos: é a ação que a
        // pessoa toma justamente ao suspeitar de invasão, e sem isto o invasor com o
        // cookie continuava logado. `logoutOtherDevices` regrava o hash da senha (que só
        // derrubaria alguém com o `AuthenticateSession` ligado) e devolve a este aparelho
        // o cookie de "lembrar de mim" com o token novo; o que de fato derruba as outras
        // sessões no driver `database` é apagar as linhas.
        Auth::logoutOtherDevices($validated['password']);
        BrowserSessions::purgeForUser(
            $user->getKey(),
            exceptSessionId: $request->session()->getId(),
        );

        // Derrubar as outras sessões protege contra quem NÃO tem a senha. Este aviso
        // cobre o caso oposto e pior: quem já entrou e está trocando a senha justamente
        // para trancar o dono do lado de fora. O e-mail é o único canal que o invasor
        // não controla.
        Notificador::avisar($user, AlertaDeSeguranca::senhaAlterada(
            $user,
            ContextoDeSeguranca::doRequest($request),
        ));

        return back()->with('status', 'password-updated');
    }
}
