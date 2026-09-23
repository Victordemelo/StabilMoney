<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Avisa o DEPENDENTE de que o titular excluiu a conta-família — e, com ela, o acesso dele.
 *
 * Excluir a conta do titular apaga os dependentes um a um (hook `deleting` do User): o
 * login, a foto e as sessões de cada um somem junto. Sem este aviso a pessoa só descobria
 * ao tentar entrar, com "credenciais inválidas" — e concluía que errou a senha, ou que a
 * conta foi invadida. O dependente não escolheu se cadastrar (ver BemVindoDependente) e
 * também não escolheu sair: o mínimo é saber o que aconteceu, e por quem.
 *
 * **Recebe texto, não o `User`.** Este e-mail só sai DEPOIS do commit da exclusão
 * (ProfileController::destroy), quando as linhas já não existem: os dados são capturados
 * antes do delete. E se um dia os Mailables forem para a fila (`ShouldQueue`), um model
 * apagado não teria como ser recarregado pelo `SerializesModels`.
 *
 * Sem IP nem aparelho nos detalhes, como em `AlertaDeSeguranca::senhaAlteradaPeloTitular`:
 * seriam os do titular — dado de outra pessoa, que não ajuda o dependente a decidir nada.
 *
 * ⚠️ Nomes vão para os parágrafos por `e()`: o layout os imprime com `{!! !!}`.
 */
class ContaDaFamiliaExcluida extends Mailable
{
    use Queueable;

    public function __construct(
        public string $dependenteNome,
        public string $titularNome,
        public string $quando,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->titularNome.' excluiu a conta-família no Stabil Money',
        );
    }

    public function content(): Content
    {
        $titular = e($this->titularNome);

        $paragrafos = [
            '<strong>'.$titular.'</strong>, titular da conta-família de que você fazia parte no Stabil Money, '
                .'<strong>excluiu a conta</strong>.',
            'Com isso, o seu acesso também deixou de existir: o seu login, a sua foto de perfil e os seus dados '
                .'pessoais foram apagados, junto com os lançamentos, as contas, as metas e os investimentos da família. '
                .'A exclusão é definitiva.',
            'Você não precisa fazer nada. Se quiser voltar a usar o Stabil Money, pode criar uma conta própria '
                .'com este mesmo e-mail.',
        ];

        // Sem "não foi você?": o dependente sabe que não foi ele. O que ele precisa saber é
        // com quem falar se a exclusão não era esperada — inclusive porque a conta do
        // titular pode ter sido usada por outra pessoa.
        $rodapeAviso = '<strong>Não esperava por isso?</strong> Fale com '.$titular.'. '
            .'Se ele(a) também não reconhecer a exclusão, escreva para '.e(config('legal.contact_email')).': '
            .'não há como desfazê-la, mas queremos saber o que aconteceu.';

        return new Content(
            view: 'emails.layout',
            text: 'emails.layout-texto',
            with: [
                'titulo' => 'A conta-família foi excluída',
                'preheader' => $this->titularNome.' excluiu a conta-família, e o seu acesso foi apagado junto.',
                'saudacao' => 'Olá, '.$this->dependenteNome.'.',
                'paragrafos' => $paragrafos,
                'paragrafosTexto' => array_map(self::semMarcacao(...), $paragrafos),
                'detalhes' => [
                    'Quando' => $this->quando,
                    'Excluída por' => $this->titularNome,
                ],
                // Sem botão: não existe mais conta em que entrar.
                'acaoUrl' => null,
                'acaoRotulo' => null,
                'rodapeAviso' => $rodapeAviso,
                'rodapeAvisoTexto' => self::semMarcacao($rodapeAviso),
                // O rodapé padrão fala em "aviso de segurança da sua conta" — conta que, para
                // quem recebe este e-mail, já não existe.
                'rodapeNota' => 'Este é um aviso automático do Stabil&nbsp;Money sobre a conta-família de que você fazia parte.',
                'rodapeNotaTexto' => 'Stabil Money — aviso automático sobre a conta-família de que você fazia parte.',
            ],
        );
    }

    private static function semMarcacao(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }
}
