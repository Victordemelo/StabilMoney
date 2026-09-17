<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Um SMTP que recusa TODO destinatário, como o servidor real faz com um endereço que ele
 * não aceita entregar ("550 The mail server could not deliver mail to …").
 *
 * `Mail::fake()` e `Notification::fake()` não servem para isto: trocam o envio inteiro por
 * um dublê que nunca falha, então o cenário que se quer exercitar — a exceção de transporte
 * — deixa de existir. É preciso descer ao transporte do Symfony, que é onde o "550" nasce.
 *
 * O nome do mailer não está em `Mailer::NAO_ENTREGAM`, então para o app este é um
 * transporte que ENTREGA: ele tenta enviar, e é a tentativa que falha. É exatamente o
 * caso de produção com o SMTP configurado e o destinatário recusado.
 */
trait SimulaSmtpQueRecusa
{
    protected function smtpQueRecusa(): void
    {
        Mail::extend('smtp-que-recusa', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new UnexpectedResponseException(
                    'Expected response code "250/251/252" but got code "550", with message '
                    .'"550 The mail server could not deliver mail to this address.".',
                    550
                );
            }

            public function __toString(): string
            {
                return 'smtp-que-recusa://';
            }
        });

        config()->set('mail.mailers.smtp-que-recusa', ['transport' => 'smtp-que-recusa']);
        config()->set('mail.default', 'smtp-que-recusa');
    }
}
