<?php

namespace App\Mail;

use App\Mail\Concerns\TextoSemMarcacao;
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
 *
 * No layout do app, com HTML e texto, como todos os outros (achado E-3 da auditoria de
 * 07/09/2026). Era o único só-texto — e o texto imprimia o link com `{{ }}`: o `&` da URL
 * assinada virava `&amp;`, a assinatura deixava de conferir e quem clicava recebia "este
 * link expirou". A troca de e-mail com SMTP no ar não se completava pelo link.
 *
 * ⚠️ O endereço novo vai nos parágrafos por `e()`: o layout os imprime com `{!! !!}`.
 */
class ConfirmarNovoEmail extends Mailable
{
    use Queueable, SerializesModels, TextoSemMarcacao;

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
        $paragrafos = [
            'Você pediu para trocar o e-mail da sua conta no Stabil Money para <strong>'.e($this->enderecoNovo).'</strong>.',
            // O link só vale na sessão da PRÓPRIA conta (ProfileController::confirmEmail), e
            // até a confirmação quem abre a conta é o e-mail antigo. Sem esta frase, quem
            // abrisse o link deslogado tentaria entrar com o endereço novo — e não entraria.
            'A troca só vale depois que você abrir o link abaixo, que vale por <strong>2 horas</strong>. '
                .'Se o app pedir para você entrar, use o e-mail de sempre: até a confirmação, é ele que abre a sua conta.',
        ];

        $rodapeAviso = '<strong>Não pediu esta troca?</strong> Pode ignorar esta mensagem: nada muda sem a '
            .'confirmação, e o acesso à conta continua pelo e-mail antigo.';

        $rodapeNota = 'Você recebeu este e-mail porque este endereço foi informado como o novo e-mail de uma '
            .'conta no Stabil&nbsp;Money.';

        return new Content(
            view: 'emails.layout',
            text: 'emails.layout-texto',
            with: [
                'titulo' => 'Confirme seu novo e-mail',
                'preheader' => 'Abra o link para este endereço passar a ser o e-mail da sua conta.',
                'saudacao' => 'Olá, '.$this->user->name.'.',
                'paragrafos' => $paragrafos,
                'paragrafosTexto' => array_map(self::semMarcacao(...), $paragrafos),
                'detalhes' => [],
                'acaoUrl' => $this->url,
                'acaoRotulo' => 'Confirmar novo e-mail',
                'rodapeAviso' => $rodapeAviso,
                'rodapeAvisoTexto' => self::semMarcacao($rodapeAviso),
                'rodapeNota' => $rodapeNota,
                'rodapeNotaTexto' => self::semMarcacao($rodapeNota),
            ],
        );
    }
}
