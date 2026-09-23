<?php

namespace App\Notifications;

use App\Mail\RedefinirSenha;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;

/**
 * O link de "Esqueci a senha", no layout do app (achado E-2 da auditoria de 07/09/2026).
 *
 * Mesma ideia da `VerificacaoDeEmail`: o token e o link continuam do framework
 * (`ResetPassword::resetUrl`, o mesmo formato que a rota `password.reset` espera); o que muda
 * é só a mensagem. O `toMailUsing` global fica de fora pelo mesmo motivo de lá.
 *
 * Quem chama é o `PasswordBroker::sendResetLink`, via `User::sendPasswordResetNotification` —
 * o "esqueci a senha" continua respondendo igual para e-mail que não existe, porque isso é
 * decidido antes (PasswordResetLinkController), e este e-mail só sai para conta que existe.
 */
class RedefinicaoDeSenha extends ResetPassword
{
    /**
     * @param  User  $notifiable
     */
    public function toMail($notifiable): RedefinirSenha
    {
        return (new RedefinirSenha(
            $notifiable,
            $this->resetUrl($notifiable),
            (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
        ))->to($notifiable->getEmailForPasswordReset());
    }
}
