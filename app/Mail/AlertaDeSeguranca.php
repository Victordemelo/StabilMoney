<?php

namespace App\Mail;

use App\Mail\Concerns\TextoSemMarcacao;
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
 * **Um Mailable com construtores nomeados**, e não uma classe por aviso: o formato é
 * idêntico (título, o que houve, quando/onde, o que fazer se não foi você) e o que muda é
 * só o texto. Arquivos quase iguais convidariam a divergirem com o tempo — e a parte que
 * não pode divergir é justamente a instrução do "não foi você".
 *
 * ⚠️ Interpolação de dado do usuário nos parágrafos passa por `e()`: eles são impressos
 * com `{!! !!}` no layout (para permitir <strong>), então texto cru viraria injeção de
 * HTML no e-mail.
 */
class AlertaDeSeguranca extends Mailable
{
    use Queueable, SerializesModels, TextoSemMarcacao;

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

    /**
     * O TITULAR definiu uma senha nova para o dependente (achado A-6 da auditoria de 05/09).
     *
     * É o único alerta em que quem recebe não foi quem agiu, por isso não usa o
     * `naoFoiVoce()`: o dependente sabe que não foi ele. O que ele precisa saber é QUEM foi
     * e o que fazer se não esperava por isso.
     *
     * Sem IP nem aparelho nos detalhes, ao contrário dos outros alertas. Lá o IP é do próprio
     * destinatário indo para ele mesmo (ver ContextoDeSeguranca); aqui seria o do titular,
     * que é dado de OUTRA pessoa — e não ajudaria o dependente a decidir nada.
     *
     * 🚨 A senha nova nunca entra aqui, pelo mesmo motivo do BemVindoDependente.
     *
     * @param  string|null  $emailNovo  preenchido quando a MESMA edição trocou também o e-mail
     *                                  de acesso. Nesse caso quem chama envia para o endereço
     *                                  antigo (ver DependentController::update), e o
     *                                  "Esqueci a senha" deixa de ser saída: o link iria para
     *                                  um endereço que o dependente talvez não controle.
     */
    public static function senhaAlteradaPeloTitular(
        User $dependente,
        User $titular,
        ContextoDeSeguranca $contexto,
        ?string $emailNovo = null,
    ): self {
        $nomeTitular = e($titular->name);

        $paragrafos = [
            '<strong>'.$nomeTitular.'</strong>, titular da sua conta-família, definiu uma <strong>senha nova</strong> para a sua conta no Stabil Money.',
            'Por segurança, a sua conta foi desconectada de todos os aparelhos, e eles vão pedir a senha nova no próximo acesso.',
        ];

        if ($emailNovo === null) {
            $paragrafos[] = 'Peça a senha nova a '.$nomeTitular.' — ou, melhor, use <strong>"Esqueci a senha"</strong> '
                .'na tela de entrada para criar uma senha que só você conhece.';
        } else {
            $paragrafos[] = 'Na mesma alteração, o e-mail com que você entra passou a ser <strong>'
                .e(self::mascararEmail($emailNovo)).'</strong>, e os próximos avisos da conta vão para lá. '
                .'Peça a senha nova a '.$nomeTitular.'.';
        }

        return new self(
            user: $dependente,
            assunto: $titular->name.' alterou a senha da sua conta no Stabil Money',
            titulo: 'Sua senha foi alterada',
            preheader: $titular->name.' definiu uma senha nova para a sua conta.',
            paragrafos: $paragrafos,
            detalhes: [
                'Quando' => $contexto->quando,
                'Alterada por' => $titular->name,
            ],
            rodapeAviso: '<strong>Não esperava por isso?</strong> Fale com '.$nomeTitular.'. '
                .'Se ele(a) também não reconhecer a mudança, a conta dele(a) pode estar com outra pessoa: '
                .'escreva para '.e(config('legal.contact_email')).'.',
        );
    }

    // ───────────────────────────────────────────────────────────── e-mail da conta

    /**
     * Pediram para trocar o e-mail da conta — aviso ao endereço ATUAL (achado A-7).
     *
     * **Sai no pedido** porque é o único momento em que o dono ainda consegue impedir a
     * troca. O link de confirmação vai para o endereço novo, e quem escolheu esse endereço o
     * controla; mas, enquanto ninguém confirma, a conta continua ligada ao antigo — o
     * "Esqueci a senha" ainda chega aqui, e a senha nova derruba todas as sessões. Sem
     * sessão da própria conta ninguém confirma (ProfileController::confirmEmail).
     *
     * O texto diz "pedido", nunca "trocado": a troca pode nem acontecer (endereço digitado
     * errado, link expirado). Anunciar como feita o que só foi pedido é o alarme falso que
     * ensina a pessoa a ignorar o próximo aviso.
     */
    public static function emailTrocaPedida(User $user, string $enderecoNovo, ContextoDeSeguranca $contexto): self
    {
        return new self(
            user: $user,
            assunto: 'Pedido para trocar o e-mail da sua conta no Stabil Money',
            titulo: 'Pedido de troca de e-mail',
            preheader: 'Pediram para trocar o e-mail da sua conta. A troca ainda não vale.',
            paragrafos: [
                'Recebemos um pedido para trocar o e-mail da sua conta no Stabil Money para <strong>'
                    .e(self::mascararEmail($enderecoNovo)).'</strong>.',
                '<strong>Nada mudou ainda.</strong> A troca só vale depois que o link que enviamos para esse endereço '
                    .'for aberto, e ele expira em 2 horas. Até lá, a sua conta continua ligada a este e-mail.',
                'Se foi você, é só abrir o link na caixa de entrada do endereço novo.',
            ],
            detalhes: $contexto->paraDetalhes(),
            rodapeAviso: '<strong>Não foi você?</strong> Então alguém que sabe a sua senha pediu a troca. '
                .'Use "Esqueci a senha" na tela de entrada <strong>agora</strong>: enquanto a troca não for confirmada, '
                .'o link chega neste endereço, e a senha nova desconecta todos os aparelhos — sem estar conectado à sua conta, '
                .'ninguém confirma a troca. Se o link não chegar, escreva para '.e(config('legal.contact_email')).'.',
        );
    }

    /**
     * O e-mail da conta MUDOU — aviso ao endereço ANTIGO (achado A-7).
     *
     * Quem chama endereça ao antigo explicitamente: a esta altura `$user->email` já é o
     * endereço novo. E é justamente o antigo que importa — é o único canal que quem fez a
     * troca não controla.
     *
     * O "não foi você" é próprio: o "Esqueci a senha" não serve mais, porque com este
     * endereço ele não encontra a conta. A saída é o contato humano, escrito A PARTIR deste
     * endereço — é o que mostra de quem a conta era.
     */
    public static function emailAlterado(
        User $user,
        string $enderecoAntigo,
        string $enderecoNovo,
        ContextoDeSeguranca $contexto,
    ): self {
        return new self(
            user: $user,
            assunto: 'O e-mail da sua conta no Stabil Money foi trocado',
            titulo: 'E-mail da conta trocado',
            preheader: 'Este endereço deixou de ser o e-mail da sua conta.',
            paragrafos: [
                'O e-mail da sua conta no Stabil Money foi trocado de <strong>'.e($enderecoAntigo)
                    .'</strong> para <strong>'.e(self::mascararEmail($enderecoNovo)).'</strong>.',
                'A partir de agora, entrar no app e recuperar a senha passam pelo endereço novo, '
                    .'e os próximos avisos da conta vão para lá.',
            ],
            detalhes: $contexto->paraDetalhes(),
            rodapeAviso: '<strong>Não foi você?</strong> Então alguém que sabia a sua senha trocou o e-mail da sua conta, '
                .'e o "Esqueci a senha" não funciona mais com este endereço. Escreva o quanto antes para '
                .e(config('legal.contact_email')).', a partir deste endereço, para recuperarmos o acesso.',
        );
    }

    /**
     * O TITULAR trocou o e-mail com que o dependente entra — aviso ao endereço ANTIGO dele.
     *
     * Era o caminho de tomada silenciosa do login do dependente: troca-se o e-mail para um
     * endereço que se controla e pede-se "Esqueci a senha" nele. Nada disso passa pelo
     * dependente, e ninguém lhe contava nada. Quem chama endereça ao antigo
     * (DependentController::update): a esta altura `$dependente->email` já é o novo, e o
     * antigo é o único canal que quem fez a troca não controla.
     *
     * Só para a troca de e-mail SOZINHA. Quando a mesma edição troca também a senha, o aviso
     * é o `senhaAlteradaPeloTitular(emailNovo:)`, que cobre as duas mudanças num e-mail só.
     *
     * Mesmo formato daquele: quem agiu foi o titular, então não há "não foi você", e não vão
     * IP nem aparelho (seriam dados do titular). O endereço novo sai mascarado, como em todo
     * aviso de troca de e-mail (ver `mascararEmail`).
     */
    public static function emailAlteradoPeloTitular(
        User $dependente,
        User $titular,
        string $emailNovo,
        ContextoDeSeguranca $contexto,
    ): self {
        $nomeTitular = e($titular->name);

        return new self(
            user: $dependente,
            assunto: $titular->name.' trocou o e-mail da sua conta no Stabil Money',
            titulo: 'O e-mail da sua conta foi trocado',
            preheader: $titular->name.' trocou o e-mail com que você entra no app.',
            paragrafos: [
                '<strong>'.$nomeTitular.'</strong>, titular da sua conta-família, trocou o e-mail com que você entra no '
                    .'Stabil Money para <strong>'.e(self::mascararEmail($emailNovo)).'</strong>.',
                'A partir de agora, entrar no app e recuperar a senha passam pelo endereço novo, e os próximos avisos da '
                    .'conta vão para lá. A sua senha não mudou.',
            ],
            detalhes: [
                'Quando' => $contexto->quando,
                'Alterado por' => $titular->name,
            ],
            // O "Esqueci a senha" não é saída aqui: com este endereço ele já não acha a conta,
            // e é justamente pelo endereço novo que a senha pode ser trocada.
            rodapeAviso: '<strong>Não combinou essa troca?</strong> Fale com '.$nomeTitular.' agora: quem controla o '
                .'endereço novo consegue criar uma senha nova para a sua conta. Se ele(a) também não reconhecer a mudança, '
                .'a conta dele(a) pode estar com outra pessoa: escreva o quanto antes para '
                .e(config('legal.contact_email')).', a partir deste endereço.',
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

    /**
     * O último aviso ao TITULAR (ou ao dependente que apagou o próprio login).
     *
     * Excluir o titular apaga junto o login de cada dependente (hook `deleting` do User), e o
     * aviso dizia só "sua conta foi excluída". Quem apagou sem querer — ou quem NÃO apagou, se
     * a conta estava com outra pessoa — ficava sem saber que a família inteira perdeu o
     * acesso, e a quem precisava explicar isso. Agora os dependentes são nomeados.
     *
     * @param  list<string>  $dependentes  nomes de quem perdeu o acesso junto (ver
     *                                     ProfileController::dependentesQuePerdemOAcesso),
     *                                     capturados ANTES do delete: depois dele as linhas
     *                                     não existem mais
     */
    public static function contaExcluida(User $user, ContextoDeSeguranca $contexto, array $dependentes = []): self
    {
        // O dependente leva só o que é dele: o dinheiro é da família e fica com o titular. Dizer
        // a ele que "os lançamentos, contas e metas" foram apagados era falso — o mesmo engano
        // que o card de exclusão cometia (profile/partials/delete-user-form).
        $paragrafos = $user->isTitular()
            ? ['Sua conta no Stabil Money foi <strong>excluída</strong>, junto com os lançamentos, contas, metas e a foto de perfil, como promete a nossa Política de Privacidade.']
            : [
                'Sua conta no Stabil Money foi <strong>excluída</strong>: o seu login, a sua foto de perfil e os seus dados pessoais foram apagados, como promete a nossa Política de Privacidade.',
                'O dinheiro da família — contas, lançamentos, metas e investimentos — não foi apagado: continua com <strong>'
                    .e($user->titular?->name ?? 'o titular').'</strong>, que responde pela conta.',
            ];

        $quantos = count($dependentes);

        if ($quantos > 0) {
            $nomes = collect($dependentes)
                ->map(fn (string $nome) => '<strong>'.e($nome).'</strong>')
                ->join(', ', ' e ');

            $paragrafos[] = $quantos === 1
                ? 'Junto com ela foi apagado também o acesso de '.$nomes.', que era dependente da sua conta-família: '
                    .'o login, a foto de perfil e os dados pessoais. Essa pessoa não consegue mais entrar no app, '
                    .'e também recebe um aviso por e-mail.'
                : 'Junto com ela foram apagados também os acessos de quem era dependente da sua conta-família: '
                    .$nomes.'. O login, a foto de perfil e os dados pessoais de cada um foram apagados; ninguém '
                    .'mais entra no app com eles, e cada um também recebe um aviso por e-mail.';
        }

        $paragrafos[] = 'Este é o último e-mail que você recebe de nós. Obrigado por ter usado o app.';

        return new self(
            user: $user,
            assunto: 'Sua conta do Stabil Money foi excluída',
            titulo: 'Conta excluída',
            preheader: match (true) {
                $quantos === 0 => 'Sua conta e seus dados foram removidos.',
                $quantos === 1 => 'Sua conta e o acesso de 1 dependente foram removidos.',
                default => 'Sua conta e o acesso de '.$quantos.' dependentes foram removidos.',
            },
            paragrafos: $paragrafos,
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

    /**
     * "ma***@gmail.com" — o bastante para o dono reconhecer o próprio endereço.
     *
     * Os avisos de troca de e-mail vão para o endereço ANTIGO, e ele pode já não ser da
     * pessoa (e-mail do emprego anterior, caixa abandonada). Mandar para lá o endereço novo
     * por inteiro entregaria o contato atual dela a quem lê aquela caixa — alguém que talvez
     * não tenha mais nada a ver com a conta. O domínio fica inteiro: é o que mais ajuda a
     * reconhecer ("gmail? não uso"). Quem digitou o endereço o vê completo na própria tela,
     * logo depois de salvar.
     */
    private static function mascararEmail(string $email): string
    {
        $arroba = mb_strrpos($email, '@');

        if ($arroba === false) {
            return '***';
        }

        $local = mb_substr($email, 0, $arroba);

        // Local curto mostraria quase tudo com duas letras: aí só a primeira aparece.
        return mb_substr($local, 0, mb_strlen($local) >= 4 ? 2 : 1).'***@'.mb_substr($email, $arroba + 1);
    }

    /** O bloco vermelho de "e se não foi você?" — mesma instrução em todos os alertas. */
    private static function naoFoiVoce(string $oQuePodeTerAcontecido): string
    {
        return '<strong>Não foi você?</strong> Então '.$oQuePodeTerAcontecido.'. '
            .'Use "Esqueci a senha" na tela de entrada para retomar o acesso e, se não conseguir, '
            .'escreva para '.e(config('legal.contact_email')).'.';
    }
}
