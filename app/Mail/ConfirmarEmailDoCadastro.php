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
 * O link que confirma o e-mail de quem acabou de se cadastrar (e o do botão "reenviar").
 *
 * Era a notificação padrão do framework (`VerifyEmail`), com o modelo de e-mail do Laravel:
 * sem a marca, com "Todos os direitos reservados" no rodapé e nota 48% de compatibilidade
 * (box-shadow e box-sizing que o Outlook e o Gmail não entendem) — achado E-2 da auditoria
 * de 07/09/2026. É um dos dois e-mails que TODA pessoa recebe no primeiro dia, e o primeiro
 * contato dela com o app fora da tela. Agora sai no layout do app, com HTML e texto.
 *
 * Quem monta o link continua sendo o framework (`VerifyEmail::verificationUrl`, chamado pela
 * `App\Notifications\VerificacaoDeEmail`): a assinatura e o `hash` precisam casar com o que
 * a rota `verification.verify` confere, e reescrevê-los aqui seria abrir espaço para os dois
 * divergirem.
 */
class ConfirmarEmailDoCadastro extends Mailable
{
    use Queueable, SerializesModels, TextoSemMarcacao;

    public function __construct(
        public User $user,
        public string $url,
        public int $validadeEmMinutos,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirme seu e-mail — Stabil Money');
    }

    public function content(): Content
    {
        $paragrafos = [
            'Boas-vindas ao Stabil Money! Falta só confirmar que este endereço de e-mail é seu.',
            // "O link", e não "o botão": a mesma frase vai para a parte texto, onde não há botão.
            'Para confirmar, abra o link abaixo. Ele vale por <strong>'.$this->validadeEmMinutos.' minutos</strong>; '
                .'se expirar, entre no app e peça outro na tela de confirmação.',
        ];

        // Quem recebe sem ter se cadastrado precisa saber que NÃO clicar basta: sem a
        // confirmação, o middleware `verified` não deixa ninguém usar o app com este endereço.
        $rodapeAviso = '<strong>Não criou uma conta no Stabil Money?</strong> Então alguém digitou este endereço. '
            .'Não abra o link: sem a confirmação, ninguém usa o app com ele. Se quiser que o cadastro seja '
            .'removido, escreva para '.e(config('legal.contact_email')).'.';

        $rodapeNota = 'Você recebeu este e-mail porque este endereço foi usado para criar uma conta no Stabil&nbsp;Money.';

        return new Content(
            view: 'emails.layout',
            text: 'emails.layout-texto',
            with: [
                'titulo' => 'Confirme seu e-mail',
                'preheader' => 'Falta só um passo para começar a usar o Stabil Money.',
                'saudacao' => 'Olá, '.$this->user->name.'.',
                'paragrafos' => $paragrafos,
                'paragrafosTexto' => array_map(self::semMarcacao(...), $paragrafos),
                'detalhes' => [],
                'acaoUrl' => $this->url,
                'acaoRotulo' => 'Confirmar meu e-mail',
                'rodapeAviso' => $rodapeAviso,
                'rodapeAvisoTexto' => self::semMarcacao($rodapeAviso),
                'rodapeNota' => $rodapeNota,
                'rodapeNotaTexto' => self::semMarcacao($rodapeNota),
            ],
        );
    }
}
