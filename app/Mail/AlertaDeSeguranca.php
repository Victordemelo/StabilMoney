<?php

namespace App\Mail;

use App\Models\User;
use App\Support\ContextoDeSeguranca;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Os avisos de "aconteceu algo importante na sua conta".
 *
 * **Para que servem:** o app já derruba as outras sessões quando a senha muda e já exige a
 * senha atual para desligar o 2FA. Isso protege contra quem NÃO tem a credencial. O que
 * faltava era o caso em que o atacante JÁ entrou: ele troca a senha, desliga o 2FA e o dono
 * da conta não fica sabendo de nada — descobre semanas depois, quando não consegue mais
 * entrar. O e-mail é o único canal que o invasor não controla; é ele que dá ao dono a
 * chance de reagir no mesmo minuto.
 *
 * **Um Mailable com construtores nomeados**, e não seis classes: o formato é idêntico
 * (título, o que houve, quando/onde, o que fazer se não foi você) e o que muda é só o
 * texto. Seis arquivos quase iguais convidariam a divergirem com o tempo — e a parte que
 * não pode divergir é justamente a instrução do "não foi você".
 *
 * ⚠️ Interpolação de dado do usuário nos parágrafos passa por `e()`: eles são impressos
 * com `{!! !!}` no layout (para permitir <strong>), então texto cru viraria injeção de
 * HTML no e-mail.
 */
class AlertaDeSeguranca extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $paragrafos  HTML confiável (ver aviso do docblock)
     * @param  array<string, string>  $detalhes
     */
    private function __construct(
        public User $user,
        public string $assunto,
        public string $titulo,
        public string $preheader,
        public array $paragrafos,
        public array $detalhes = [],
        public ?string $acaoUrl = null,
        public ?string $acaoRotulo = null,
        public ?string $rodapeAviso = null,
    ) {}

    // ───────────────────────────────────────────────────────────── senha

    public static function senhaAlterada(User $user, ContextoDeSeguranca $contexto): self
    {
        return new self(
            user: $user,
            assunto: 'Sua senha do Stabil Money foi alterada',
            titulo: 'Senha alterada',
            preheader: 'A senha da sua conta acabou de ser alterada.',
            paragrafos: [
                'A senha da sua conta no Stabil Money acabou de ser <strong>alterada</strong>.',
                'Por segurança, todos os seus outros aparelhos foram desconectados e vão pedir a senha nova no próximo acesso.',
            ],
            detalhes: $contexto->paraDetalhes(),
            rodapeAviso: self::naoFoiVoce('alguém com acesso à sua conta trocou a senha'),
        );
    }

    public static function senhaRedefinida(User $user, ContextoDeSeguranca $contexto): self
    {
        return new self(
            user: $user,
            assunto: 'Sua senha do Stabil Money foi redefinida',
            titulo: 'Senha redefinida',
            preheader: 'Sua senha foi redefinida pelo link de recuperação.',
            paragrafos: [
                'A senha da sua conta no Stabil Money foi <strong>redefinida</strong> pelo link de "esqueci a senha".',
                'Se foi você, não precisa fazer mais nada — é só entrar com a senha nova.',
            ],
            detalhes: $contexto->paraDetalhes(),
            rodapeAviso: self::naoFoiVoce('alguém com acesso ao seu e-mail redefiniu a senha da sua conta'),
        );
    }

    // ───────────────────────────────────────────────────────────── 2FA

    public static function doisFatoresAtivado(User $user, ContextoDeSeguranca $contexto): self
    {
        return new self(
            user: $user,
            assunto: 'Verificação em duas etapas ativada',
            titulo: 'Verificação em duas etapas ativada',
            preheader: 'Sua conta agora pede o código do aplicativo autenticador.',
            paragrafos: [
                'A verificação em duas etapas foi <strong>ativada</strong> na sua conta. A partir de agora, entrar em um aparelho novo vai pedir o código de 6 dígitos do seu aplicativo autenticador.',
                'Guarde os <strong>códigos de recuperação</strong> que apareceram na tela: são eles que devolvem o acesso se você perder o celular.',
            ],
            detalhes: $contexto->paraDetalhes(),
        );
    }

    public static function doisFatoresDesativado(User $user, ContextoDeSeguranca $contexto): self
    {
        return new self(
            user: $user,
            assunto: 'Verificação em duas etapas DESATIVADA',
            titulo: 'Verificação em duas etapas desativada',
            preheader: 'Sua conta voltou a ser protegida apenas pela senha.',
            paragrafos: [
                'A verificação em duas etapas foi <strong>desativada</strong> na sua conta.',
                'A partir de agora, entrar exige apenas a senha — o código do aplicativo autenticador não é mais pedido.',
            ],
            detalhes: $contexto->paraDetalhes(),
            // O alerta mais importante da família: desligar o 2FA é o primeiro passo de
            // quem tomou a conta e quer mantê-la.
            rodapeAviso: self::naoFoiVoce('alguém com acesso à sua conta desligou a proteção extra'),
        );
    }

    // ───────────────────────────────────────────────────────────── sessões e conta

    public static function sessoesEncerradas(User $user, ContextoDeSeguranca $contexto, int $quantas): self
    {
        $frase = $quantas === 1
            ? '<strong>1 aparelho</strong> foi desconectado'
            : '<strong>'.$quantas.' aparelhos</strong> foram desconectados';

        return new self(
            user: $user,
            assunto: 'Outros aparelhos foram desconectados da sua conta',
            titulo: 'Sessões encerradas',
            preheader: 'Os outros aparelhos conectados à sua conta foram desconectados.',
            paragrafos: [
                'Alguém pediu para encerrar as outras sessões da sua conta no Stabil Money: '.$frase.', e só o aparelho que fez o pedido continuou conectado.',
                'Esta é uma ação de segurança normal — costuma ser feita quando se desconfia de um acesso estranho.',
            ],
            detalhes: $contexto->paraDetalhes(),
            rodapeAviso: self::naoFoiVoce('alguém com acesso à sua conta desconectou seus aparelhos'),
        );
    }

    public static function contaExcluida(User $user, ContextoDeSeguranca $contexto): self
    {
        return new self(
            user: $user,
            assunto: 'Sua conta do Stabil Money foi excluída',
            titulo: 'Conta excluída',
            preheader: 'Sua conta e seus dados foram removidos.',
            paragrafos: [
                'Sua conta no Stabil Money foi <strong>excluída</strong>, junto com os lançamentos, contas, metas e a foto de perfil, como promete a nossa Política de Privacidade.',
                'Este é o último e-mail que você recebe de nós. Obrigado por ter usado o app.',
            ],
            detalhes: $contexto->paraDetalhes(),
            // Sem "entre na sua conta": ela não existe mais. A única saída é o contato humano.
            rodapeAviso: 'Não foi você? A exclusão é definitiva e não temos como desfazê-la, '
                .'mas queremos saber o que aconteceu: escreva para '.e(config('legal.contact_email')).'.',
        );
    }

    // ───────────────────────────────────────────────────────────── montagem

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->assunto);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.layout',
            text: 'emails.layout-texto',
            with: [
                'saudacao' => 'Olá, '.$this->user->name.'.',
                // O layout de texto recebe o mesmo conteúdo sem marcação — quem lê só a
                // parte texto não pode receber "<strong>" na cara.
                'paragrafosTexto' => array_map(self::semMarcacao(...), $this->paragrafos),
                'rodapeAvisoTexto' => $this->rodapeAviso ? self::semMarcacao($this->rodapeAviso) : null,
            ],
        );
    }

    private static function semMarcacao(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    /** O bloco vermelho de "e se não foi você?" — mesma instrução em todos os alertas. */
    private static function naoFoiVoce(string $oQuePodeTerAcontecido): string
    {
        return '<strong>Não foi você?</strong> Então '.$oQuePodeTerAcontecido.'. '
            .'Use "Esqueci a senha" na tela de entrada para retomar o acesso e, se não conseguir, '
            .'escreva para '.e(config('legal.contact_email')).'.';
    }
}
