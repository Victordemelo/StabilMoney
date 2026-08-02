<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Mailer;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // Sem confirmação de senha (decisão do design v2: campo único com
        // toggle de visibilidade + medidor de força); termos são obrigatórios.
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', Rules\Password::defaults()],
            'terms' => ['required', 'accepted'],
        ], [
            'terms.required' => 'Você precisa aceitar os Termos de Uso e a Política de Privacidade.',
            'terms.accepted' => 'Você precisa aceitar os Termos de Uso e a Política de Privacidade.',
        ]);

        // Quem se cadastra pelo formulário é o titular da conta (admin da própria
        // família). Dependentes (próxima rodada) entrarão com is_admin = false.
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            // Prova do aceite (LGPD art. 8º, §1º): quando, qual versão do documento
            // e de qual IP. Sem isso não há como comprovar o consentimento depois.
            'terms_accepted_at' => now(),
            'terms_version' => config('legal.version'),
            'terms_accepted_ip' => $request->ip(),
        ]);

        // Fora do mass assignment (ver $fillable no model): quem se cadastra pelo
        // formulário é sempre titular, e isso é decisão do servidor.
        $user->is_admin = true;

        // O app só pode EXIGIR confirmação de e-mail se conseguir enviá-la. Enquanto o
        // mailer não entrega (fase de testes, MAIL_MAILER=log), o usuário nasce já
        // verificado: exigir uma confirmação que nunca chega trancaria todo mundo fora.
        //
        // Isso é o que deixa a verificação PRONTA para ligar sozinha — no dia em que o
        // SMTP entrar no `.env`, este `if` para de valer, o campo fica nulo e o link sai
        // no `Registered` abaixo (listener SendEmailVerificationNotification, registrado
        // pelo próprio framework). Quem se cadastrou na fase de testes continua entrando,
        // porque foi marcado como verificado no cadastro dele.
        //
        // A ordem importa: marcar ANTES do evento, senão o listener manda um link inútil.
        if (! Mailer::entrega()) {
            $user->email_verified_at = now();
        }

        $user->save();

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
