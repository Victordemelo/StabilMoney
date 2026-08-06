<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Avisa o dependente de que o titular criou uma conta para ele.
 *
 * O dependente é o único usuário do app que **não escolheu se cadastrar**: o titular
 * preenche nome, e-mail e senha por ele. Sem este e-mail, a pessoa passa a ter uma conta
 * num app financeiro — com acesso a todo o dinheiro da família — e só descobre quando
 * alguém lhe conta. Também é o que dá a ela a chance de dizer "não quero" e a chance de
 * perceber, se um dia o e-mail for usado sem seu conhecimento.
 *
 * 🚨 **A senha NUNCA vai neste e-mail**, ainda que o titular a tenha acabado de definir.
 * E-mail não é canal seguro (fica em caixa de entrada, backup e servidor intermediário
 * para sempre) e mandar senha em texto é o erro que transforma um vazamento de caixa
 * postal em invasão de conta. O caminho oferecido é o "Esqueci a senha": além de seguro,
 * é o único que deixa o dependente ter uma senha que **o titular não conhece**.
 */
class BemVindoDependente extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $dependente,
        public User $titular,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->titular->name.' criou uma conta para você no Stabil Money',
        );
    }

    public function content(): Content
    {
        $titular = e($this->titular->name);
        $email = e($this->dependente->email);

        $paragrafos = [
            '<strong>'.$titular.'</strong> adicionou você como dependente na conta-família dele(a) '
                .'no Stabil Money, o aplicativo que a família usa para organizar as finanças.',
            'Você entra com este endereço de e-mail: <strong>'.$email.'</strong>. '
                .'Como dependente, você enxerga as contas, os lançamentos e as metas da família, '
                .'e pode registrar seus próprios gastos.',
            'A senha foi definida por '.$titular.' — peça a ele(a), ou, melhor, use '
                .'<strong>"Esqueci a senha"</strong> na tela de entrada para criar uma senha sua, '
                .'que só você conhece.',
        ];

        return new Content(
            view: 'emails.layout',
            text: 'emails.layout-texto',
            with: [
                'titulo' => 'Você agora faz parte de uma conta-família',
                'preheader' => $this->titular->name.' criou uma conta para você no Stabil Money.',
                'saudacao' => 'Olá, '.$this->dependente->name.'.',
                'paragrafos' => $paragrafos,
                'paragrafosTexto' => array_map(
                    fn (string $p) => trim(html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5)),
                    $paragrafos,
                ),
                'detalhes' => [],
                'acaoUrl' => route('login'),
                'acaoRotulo' => 'Entrar no Stabil Money',
                'rodapeAviso' => 'Não esperava por isto? Não faça nada — sem a senha ninguém entra. '
                    .'Se preferir que a conta seja removida, responda para '.e(config('legal.contact_email')).'.',
                'rodapeAvisoTexto' => 'Não esperava por isto? Não faça nada — sem a senha ninguém entra. '
                    .'Se preferir que a conta seja removida, escreva para '.config('legal.contact_email').'.',
            ],
        );
    }
}
