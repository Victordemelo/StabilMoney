<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Mailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Sem transporte que entregue, o link iria para storage/logs e mais ninguém o
        // veria. Dizer "enviamos para o seu e-mail" seria mentir para quem está trancado
        // fora da conta — a pessoa esperaria um e-mail que nunca chega em vez de pedir
        // ajuda. Enquanto o SMTP não entra no .env, o app assume a limitação.
        if (! Mailer::entrega()) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => Mailer::avisoDeIndisponibilidade()]);
        }

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $status = Password::sendResetLink(
            $request->only('email')
        );

        // Resposta SEMPRE igual quando o e-mail não existe (INVALID_USER): dizer
        // "não existe nenhum usuário com esse e-mail" entregava a um script a lista
        // de quem tem conta aqui — insumo para phishing dirigido e credential
        // stuffing. E igual também no pedido REPETIDO (THROTTLED, dentro de 60 s): o
        // "aguarde para tentar de novo" só aparecia para e-mail cadastrado — pedir duas
        // vezes bastava para descobrir quem tem conta (rodada de 22-23/09/2026). Quem
        // pediu há pouco já recebeu o link.
        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return back()->with('status', __(Password::RESET_LINK_SENT));
        }

        return back()->withInput($request->only('email'))
            ->withErrors(['email' => __($status)]);
    }
}
