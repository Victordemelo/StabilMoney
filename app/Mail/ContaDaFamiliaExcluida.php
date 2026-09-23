<?php

namespace App\Mail;

use App\Mail\Concerns\TextoSemMarcacao;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Avisa o DEPENDENTE de que a conta-família foi excluída — e, com ela, o acesso dele.
 *
 * Excluir a conta do titular apaga os dependentes um a um (hook `deleting` do User): o
 * login, a foto e as sessões de cada um somem junto. Sem este aviso a pessoa só descobria
 * ao tentar entrar, com "credenciais inválidas" — e concluía que errou a senha, ou que a
 * conta foi invadida. O dependente não escolheu se cadastrar (ver BemVindoDependente) e
 * também não escolheu sair: o mínimo é saber o que aconteceu, e por quem.
 *
 * **Duas origens** (23/09/2026): o próprio titular (`new`, ProfileController::destroy) ou a
 * administração do app, pelo painel (`pelaAdministracao()`, Admin\ModeracaoController::excluir).
 * Muda quem é apontado como autor e a quem recorrer; o que o dependente perdeu é o mesmo.
 *
 * **Recebe texto, não o `User`.** Este e-mail só sai DEPOIS do commit da exclusão, quando as
 * linhas já não existem: os dados são capturados antes do delete. E se um dia os Mailables
 * forem para a fila (`ShouldQueue`), um model apagado não teria como ser recarregado pelo
 * `SerializesModels`.
 *
 * Sem IP nem aparelho nos detalhes, como em `AlertaDeSeguranca::senhaAlteradaPeloTitular`:
 * seriam os de quem excluiu (o titular ou o administrador) — dado de outra pessoa, que não
 * ajuda o dependente a decidir nada.
 *
 * ⚠️ Nomes vão para os parágrafos por `e()`: o layout os imprime com `{!! !!}`.
 */
class ContaDaFamiliaExcluida extends Mailable
{
    use Queueable, TextoSemMarcacao;

    public function __construct(
        public string $dependenteNome,
        public string $titularNome,
        public string $quando,
        public bool $pelaAdministracao = false,
    ) {}

    /**
     * A conta-família foi excluída pelo PAINEL administrativo, não pelo titular.
     *
     * O texto não diz por quê: o motivo de moderação é registro interno, e o dependente não
     * tem nada a ver com ele. Diz que foi a administração (dizer "o titular excluiu" seria
     * falso, e mandaria a pessoa cobrar de quem não fez) e a quem escrever.
     */
    public static function pelaAdministracao(string $dependenteNome, string $titularNome, string $quando): self
    {
        return new self($dependenteNome, $titularNome, $quando, pelaAdministracao: true);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->pelaAdministracao
                ? 'A conta-família de '.$this->titularNome.' foi excluída no Stabil Money'
                : $this->titularNome.' excluiu a conta-família no Stabil Money',
        );
    }

    public function content(): Content
    {
        $titular = e($this->titularNome);
        $contato = e(config('legal.contact_email'));

        $paragrafos = [
            $this->pelaAdministracao
                ? 'A conta-família de <strong>'.$titular.'</strong>, de que você fazia parte no Stabil Money, '
                    .'foi <strong>excluída pela administração do app</strong>.'
                : '<strong>'.$titular.'</strong>, titular da conta-família de que você fazia parte no Stabil Money, '
                    .'<strong>excluiu a conta</strong>.',
            'Com isso, o seu acesso também deixou de existir: o seu login, a sua foto de perfil e os seus dados '
                .'pessoais foram apagados, junto com os lançamentos, as contas, as metas e os investimentos da família. '
                .'A exclusão é definitiva.',
            'Você não precisa fazer nada. Se quiser voltar a usar o Stabil Money, pode criar uma conta própria '
                .'com este mesmo e-mail.',
        ];

        // Sem "não foi você?": o dependente sabe que não foi ele. O que ele precisa saber é
        // com quem falar se a exclusão não era esperada — inclusive porque a conta do
        // titular pode ter sido usada por outra pessoa. Quando foi a administração, o titular
        // não tem o que explicar: a conversa é com o contato do app.
        $rodapeAviso = $this->pelaAdministracao
            ? '<strong>Tem dúvidas sobre esta exclusão?</strong> Escreva para '.$contato.'. '
                .'Não há como desfazê-la, mas respondemos o que for possível.'
            : '<strong>Não esperava por isso?</strong> Fale com '.$titular.'. '
                .'Se ele(a) também não reconhecer a exclusão, escreva para '.$contato.': '
                .'não há como desfazê-la, mas queremos saber o que aconteceu.';

        return new Content(
            view: 'emails.layout',
            text: 'emails.layout-texto',
            with: [
                'titulo' => 'A conta-família foi excluída',
                'preheader' => $this->pelaAdministracao
                    ? 'A conta-família de '.$this->titularNome.' foi excluída, e o seu acesso foi apagado junto.'
                    : $this->titularNome.' excluiu a conta-família, e o seu acesso foi apagado junto.',
                'saudacao' => 'Olá, '.$this->dependenteNome.'.',
                'paragrafos' => $paragrafos,
                'paragrafosTexto' => array_map(self::semMarcacao(...), $paragrafos),
                'detalhes' => [
                    'Quando' => $this->quando,
                    'Excluída por' => $this->pelaAdministracao ? 'Administração do Stabil Money' : $this->titularNome,
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
}
