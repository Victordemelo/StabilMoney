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
 * O link de "Esqueci a senha".
 *
 * Mesma história do `ConfirmarEmailDoCadastro` (achado E-2 da auditoria de 07/09/2026): era
 * o modelo padrão do framework, sem marca e em layout que o Outlook desmonta. Para quem está
 * trancado fora da conta, um e-mail que não parece do app é um e-mail que parece golpe.
 *
 * O link (com o token) é montado pelo framework (`ResetPassword::resetUrl`, chamado pela
 * `App\Notifications\RedefinicaoDeSenha`), que também guarda o token com hash no banco.
 *
 * Sem IP nem aparelho nos detalhes, ao contrário dos alertas de segurança: quem pede a
 * redefinição não está logado e pode ser qualquer pessoa que digitou este endereço — o IP
 * seria dado de um terceiro, e o dono da conta não decide nada com ele.
 */
class RedefinirSenha extends Mailable
{
    use Queueable, SerializesModels, TextoSemMarcacao;

    /** @param  string  $url  leva o token de redefinição: não pode aparecer em rastro de erro */
    public function __construct(
        public User $user,
        #[\SensitiveParameter] public string $url,
        public int $validadeEmMinutos,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Redefina sua senha do Stabil Money');
    }

    public function content(): Content
    {
        $paragrafos = [
            'Recebemos um pedido para <strong>redefinir a senha</strong> da sua conta no Stabil Money.',
            'Para criar uma senha nova, abra o link abaixo. Ele vale por <strong>'.$this->validadeEmMinutos
                .' minutos</strong> e só pode ser usado uma vez.',
            // É o que o NewPasswordController faz (todas as sessões caem). Dizer isto aqui é o
            // que faz a redefinição servir a quem desconfia de invasão, não só a quem esqueceu.
            'Ao salvar a senha nova, todos os aparelhos conectados à sua conta são desconectados.',
        ];

        $rodapeAviso = '<strong>Não pediu isto?</strong> Pode ignorar esta mensagem: sem abrir o link, a sua senha '
            .'continua a mesma. Se os pedidos se repetirem, escreva para '.e(config('legal.contact_email')).'.';

        return new Content(
            view: 'emails.layout',
            text: 'emails.layout-texto',
            with: [
                'titulo' => 'Redefinir sua senha',
                'preheader' => 'Recebemos um pedido para redefinir a sua senha. O link vale por '
                    .$this->validadeEmMinutos.' minutos.',
                'saudacao' => 'Olá, '.$this->user->name.'.',
                'paragrafos' => $paragrafos,
                'paragrafosTexto' => array_map(self::semMarcacao(...), $paragrafos),
                'detalhes' => [],
                'acaoUrl' => $this->url,
                'acaoRotulo' => 'Criar senha nova',
                'rodapeAviso' => $rodapeAviso,
                'rodapeAvisoTexto' => self::semMarcacao($rodapeAviso),
            ],
        );
    }
}
