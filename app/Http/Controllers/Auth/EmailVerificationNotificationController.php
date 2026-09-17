<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Mailer;
use App\Support\Notificador;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Reenvia o link de confirmação do e-mail (botão da tela `verify-email`).
     *
     * Três saídas — e a diferença entre as duas de falha é uma decisão de SEGURANÇA, não de
     * conveniência:
     *
     *  1. **Sem transporte que entregue** (`Mailer::entrega()` falso): não existe link a
     *     mandar. A tela respondia "link enviado" — mentira — e a única saída de quem está
     *     nela é justamente este botão. Vale o invariante do projeto: *enquanto o app não
     *     consegue enviar e-mail, ninguém fica pendente de confirmação* (o mesmo do cadastro).
     *     A conta é liberada. Seguro porque o gatilho é configuração do servidor: nenhum
     *     usuário consegue desligar o mailer.
     *
     *  2. **O envio falhou** (o SMTP recusou): era HTTP 500. Agora a tela diz a verdade e a
     *     falha vai para o log pelo `Notificador`. ⚠️ Aqui a conta **NÃO** é liberada, ao
     *     contrário do cadastro. No cadastro a falha acontece uma vez, no primeiro contato; aqui
     *     o botão é repetível. Se cada falha abrisse a porta, quem se cadastrou com o e-mail de
     *     OUTRA pessoa só precisaria insistir até esgotar a cota de envio do provedor para
     *     pular a confirmação — e é esse buraco que o middleware `verified` existe para fechar.
     *     **Falha que o usuário consegue provocar não pode liberar a conta.**
     *
     *  3. **Enviou:** o aviso de sempre.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        if (! Mailer::entrega()) {
            // Sem o evento `Verified`, como no cadastro: nada foi confirmado de fato — a conta
            // só deixou de exigir uma confirmação que não tem como acontecer.
            $user->markEmailAsVerified();

            return redirect()->intended(route('dashboard', absolute: false));
        }

        $enviado = Notificador::tentarEnviar(
            $user,
            'link de verificação de e-mail (reenvio)',
            fn () => $user->sendEmailVerificationNotification(),
        );

        return back()->with('status', $enviado ? 'verification-link-sent' : 'verification-link-failed');
    }
}
