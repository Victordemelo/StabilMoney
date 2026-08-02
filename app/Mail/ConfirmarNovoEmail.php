<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Link que confirma a troca de e-mail, enviado AO ENDEREÇO NOVO.
 *
 * É um Mailable (e não `Mail::raw`) por dois motivos: dá para testar com `Mail::fake()`
 * — inclusive que o destinatário é mesmo o endereço novo, que é o ponto da feature — e
 * fica pronto para virar fila quando houver volume.
 */
class ConfirmarNovoEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $enderecoNovo,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirme seu novo e-mail — Stabil Money');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.confirmar-novo-email');
    }
}
