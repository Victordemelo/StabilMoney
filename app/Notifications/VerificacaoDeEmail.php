<?php

namespace App\Notifications;

use App\Mail\ConfirmarEmailDoCadastro;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Config;

/**
 * O link de confirmação do cadastro, no layout do app (achado E-2 da auditoria de 07/09/2026).
 *
 * Estende a notificação do framework só para trocar o E-MAIL: o link continua vindo do
 * `verificationUrl()` herdado — assinado, com o `hash` do endereço e a validade de
 * `auth.verification.expire`, exatamente o que a rota `verification.verify` confere (e
 * respeitando um `createUrlUsing`, se um dia houver). Montar o link aqui seria abrir espaço
 * para os dois lados divergirem.
 *
 * O `toMailUsing` do framework fica de fora de propósito: esta classe existe para decidir o
 * e-mail, e um callback global mudando isso por baixo seria a mensagem padrão voltando por
 * outra porta.
 *
 * Continua sendo uma Notification (e não um `Mail::to` direto no User) para o caminho de envio
 * não mudar: quem chama — cadastro e reenvio — passa pelo `Notificador::tentarEnviar`, e a
 * exceção do SMTP sobe até ele do mesmo jeito que subia com a classe do framework.
 */
class VerificacaoDeEmail extends VerifyEmail
{
    /**
     * @param  User  $notifiable
     */
    public function toMail($notifiable): ConfirmarEmailDoCadastro
    {
        return (new ConfirmarEmailDoCadastro(
            $notifiable,
            $this->verificationUrl($notifiable),
            (int) Config::get('auth.verification.expire', 60),
        ))->to($notifiable->getEmailForVerification());
    }
}
