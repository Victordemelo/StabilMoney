<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\AlertaDeSeguranca;
use App\Models\User;
use App\Support\BrowserSessions;
use App\Support\ContextoDeSeguranca;
use App\Support\Notificador;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    // Token novo = todo cookie de "lembrar de mim" emitido antes deixa de
                    // valer. É a metade que apagar as sessões (abaixo) não alcança: esse
                    // cookie re-autentica sem sessão nenhuma.
                    'remember_token' => Str::random(60),
                    // A aba Segurança mostra a idade da senha a partir daqui. Sem o
                    // carimbo, quem acabou de redefinir continuava lendo "há um ano".
                    'password_changed_at' => now(),
                ])->save();

                // Derruba TODAS as sessões da conta (A-2 da auditoria de 05/09/2026).
                // Este é o caminho de quem PERDEU a conta — muitas vezes para um invasor
                // que já está logado. Sem isto a senha mudava e ele seguia dentro, com o
                // cookie de sessão que já tinha.
                //
                // Diferente da troca nas Configurações (`PasswordController`), aqui
                // ninguém está autenticado: não existe sessão "atual" a preservar, então
                // caem todas — inclusive as do próprio dono em outros aparelhos, que não
                // há como distinguir das do invasor. Pelo mesmo motivo não serve o
                // `Auth::logoutOtherDevices`: ele exige um usuário logado na requisição.
                //
                // Fica DENTRO do callback de propósito: ele só roda com token válido. Se
                // rodasse em qualquer tentativa, bastaria saber o e-mail de alguém e
                // mandar um token inventado para desconectá-lo de todos os aparelhos.
                BrowserSessions::purgeForUser($user->getKey());

                event(new PasswordReset($user));

                // Avisa o dono da conta. Este é o caminho que um invasor usa quando já
                // tomou a CAIXA DE E-MAIL da vítima: ele pede a recuperação e define a
                // senha nova sem nunca ter sabido a antiga. O aviso não impede isso —
                // mas chega junto com o link no mesmo endereço, e é a chance de a pessoa
                // perceber no mesmo minuto em vez de descobrir quando não conseguir mais
                // entrar. Nunca derruba a redefinição se o envio falhar (ver Notificador).
                Notificador::avisar($user, AlertaDeSeguranca::senhaRedefinida(
                    $user,
                    ContextoDeSeguranca::doRequest($request),
                ));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
