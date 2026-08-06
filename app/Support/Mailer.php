<?php

namespace App\Support;

/**
 * O app consegue MESMO entregar e-mail agora?
 *
 * Enquanto `MAIL_MAILER` for `log` (o padrão em desenvolvimento), o Laravel "envia" tudo
 * para `storage/logs` — nada chega a ninguém. Isso tem duas consequências que precisam de
 * decisão explícita, e não de silêncio:
 *
 *  1. **Recuperação de senha não funciona.** Dizer "enviamos o link para o seu e-mail"
 *     quando nada saiu é mentir para quem está trancado fora da conta: a pessoa fica
 *     esperando um e-mail que não vem, em vez de procurar o suporte.
 *  2. **Verificação de e-mail não pode ser exigida.** Ligar `MustVerifyEmail` sem
 *     entrega trancaria TODOS os usuários fora do app, inclusive quem já estava dentro.
 *
 * Por isso os fluxos que dependem de e-mail consultam este helper e se adaptam, em vez de
 * fingir que funcionaram. Quando o SMTP entrar no `.env`, tudo passa a valer sozinho —
 * sem mudar código.
 */
class Mailer
{
    /**
     * Transportes que NÃO entregam ao destinatário.
     *
     * `array` fica DE FORA de propósito: ele só existe em teste (é o que o `Mail::fake()`
     * usa) e ali o que se quer exercitar é o fluxo funcionando. Os dois que aparecem em
     * dev/produção mal configurada — e que fariam o app mentir — são `log` e `null`.
     */
    private const NAO_ENTREGAM = ['log', 'null'];

    /** O mailer configurado entrega de verdade? */
    public static function entrega(): bool
    {
        return ! in_array((string) config('mail.default'), self::NAO_ENTREGAM, true);
    }

    /**
     * Texto a mostrar quando o app não consegue enviar e-mail.
     *
     * Fica aqui (e não espalhado nas views) para a mensagem ser a mesma em todos os
     * lugares e citar sempre o contato oficial do `config/legal.php`.
     */
    public static function avisoDeIndisponibilidade(): string
    {
        return 'No momento o aplicativo não está enviando e-mails (fase de testes). '
            .'Para recuperar o acesso, fale com '.config('legal.contact_email').'.';
    }
}
