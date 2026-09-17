<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Mailer;
use App\Support\Notificador;
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

        // 🚨 INVARIANTE: `email_verified_at` só fica NULO depois que o link SAIU de fato.
        //
        // O app inteiro roda sob o middleware `verified`, então conta por confirmar é
        // conta TRANCADA — e o endereço já ficou ocupado (`users.email` é `unique`), o que
        // impede até recadastrar. Só é legítimo exigir a confirmação quando existe um link
        // capaz de destravá-la.
        //
        // Por isso a ordem é invertida em relação ao que era: o usuário nasce VERIFICADO e
        // só volta ao limbo com a prova de entrega na mão. Antes o campo nascia nulo e o
        // envio saía do listener do framework (`SendEmailVerificationNotification`), que
        // não tem try/catch: um "550 could not deliver" do SMTP virava **HTTP 500 com o
        // usuário já gravado**, e nem a tela de "reenviar link" o destravava, porque o
        // servidor recusa aquele destinatário de novo. Conta perdida no primeiro clique.
        //
        // Nascer verificado também é o que já valia quando `Mailer::entrega()` é falso
        // (fase de testes, MAIL_MAILER=log): aqui a regra é a mesma, apenas aplicada ao
        // caso em que o mailer existe mas o envio falha — para o usuário, dá no mesmo.
        $user->email_verified_at = now();
        $user->save();

        // Categorias padrão (SeedDefaultCategoriesForNewUser). O listener de verificação do
        // framework também escuta este evento, mas se cala diante de um usuário já
        // verificado — quem manda o link é a linha abaixo, que sabe tratar a falha.
        event(new Registered($user));

        if (Mailer::entrega() && Notificador::tentarEnviar(
            $user,
            'link de verificação de e-mail (cadastro)',
            fn () => $user->sendEmailVerificationNotification(),
        )) {
            // O link está a caminho: agora exigir a confirmação tem saída, e o buraco que o
            // middleware `verified` fecha (cadastrar-se com o e-mail de outra pessoa) volta
            // a valer. Gravar isto DEPOIS do envio é o que torna o invariante estrutural:
            // se o processo morrer no meio, ele morre do lado seguro (usuário dentro do app).
            $user->forceFill(['email_verified_at' => null])->save();
        }

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
