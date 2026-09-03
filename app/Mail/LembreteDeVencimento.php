<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Brl;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * O lembrete diário de vencimentos — o sino da topbar chegando por e-mail.
 *
 * Num app PWA quem não abre a tela não vê o sino; este e-mail é a mesma lista
 * (`FaturaService::upcomingDue`) para quem ficou dias sem entrar. É montado pelo
 * comando `lembretes:vencimentos`, que decide QUEM recebe e QUANDO; aqui só se
 * escreve a mensagem.
 *
 * Cada item é um array com `nome`, `valor` (float), `due` (Carbon) e `diasRestantes`
 * (negativo = dias de atraso) — o formato que o `upcomingDue` já devolve.
 *
 * ⚠️ O nome do cartão/conta fixa é dado do usuário. Ele NÃO entra nos parágrafos
 * (impressos com `{!! !!}` no layout): vai nas `secoes`, que o layout imprime com
 * `{{ }}`, escapado.
 *
 * @phpstan-type Item array{nome: string, valor: float, due: CarbonInterface, diasRestantes: int}
 */
class LembreteDeVencimento extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<Item>  $vencidas
     * @param  list<Item>  $proximas
     */
    public function __construct(
        public User $user,
        public array $vencidas,
        public array $proximas,
    ) {}

    // ───────────────────────────────────────────────────────────── textos

    /**
     * "Stabil Money: 1 conta vencida e 2 vencem nos próximos dias".
     *
     * O assunto carrega os números porque é o que a caixa de entrada mostra sem
     * abrir a mensagem — e "2 contas vencem" já diz se vale a pena abrir agora.
     */
    public function assunto(): string
    {
        $partes = [];

        if ($n = count($this->vencidas)) {
            $partes[] = $n === 1 ? '1 conta vencida' : "{$n} contas vencidas";
        }
        if ($n = count($this->proximas)) {
            $partes[] = $n === 1 ? '1 conta vence nos próximos dias' : "{$n} contas vencem nos próximos dias";
        }

        return 'Stabil Money: '.implode(' e ', $partes);
    }

    /** "vence hoje" · "vence amanhã" · "vence em 3 dias" · "venceu ontem" · "venceu há 5 dias". */
    public static function quando(int $diasRestantes): string
    {
        return match (true) {
            $diasRestantes === 0 => 'vence hoje',
            $diasRestantes === 1 => 'vence amanhã',
            $diasRestantes > 1 => "vence em {$diasRestantes} dias",
            $diasRestantes === -1 => 'venceu ontem',
            default => 'venceu há '.abs($diasRestantes).' dias',
        };
    }

    // ───────────────────────────────────────────────────────────── montagem

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->assunto());
    }

    public function content(): Content
    {
        $total = count($this->vencidas) + count($this->proximas);

        $paragrafos = [
            $total === 1
                ? 'Você tem <strong>1 conta</strong> pedindo atenção:'
                : "Você tem <strong>{$total} contas</strong> pedindo atenção:",
        ];

        $secoes = [];
        if ($this->vencidas) {
            $secoes[] = ['titulo' => 'Vencidas', 'tom' => 'alerta', 'itens' => $this->linhas($this->vencidas)];
        }
        if ($this->proximas) {
            $secoes[] = ['titulo' => 'Próximas', 'tom' => 'normal', 'itens' => $this->linhas($this->proximas)];
        }

        // O app nunca paga nada sozinho (regra do modelo de dinheiro): o e-mail avisa e
        // aponta a tela onde a pessoa paga — inclusive escolhendo a fonte, se faltar saldo.
        $paragrafos[] = 'Nada foi pago automaticamente. Quando pagar, marque em <strong>Pagar despesas</strong> para o limite do cartão voltar e a conta sair desta lista.';

        $rodapeNota = 'Este é um lembrete automático de vencimentos da sua conta no Stabil&nbsp;Money. '
            .'Você pode desligá-lo em <a href="'.e(route('settings', 'conta')).'" style="color:#15795A; text-decoration:none;">Configurações › Conta</a>.';

        return new Content(
            view: 'emails.layout',
            text: 'emails.layout-texto',
            with: [
                'titulo' => $this->vencidas ? 'Contas vencidas e a vencer' : 'Contas a vencer',
                'preheader' => $this->assunto(),
                'saudacao' => 'Olá, '.$this->user->name.'.',
                'paragrafos' => $paragrafos,
                'paragrafosTexto' => array_map(self::semMarcacao(...), $paragrafos),
                'detalhes' => [],
                'secoes' => $secoes,
                'acaoUrl' => route('faturas.index'),
                'acaoRotulo' => 'Abrir Pagar despesas',
                'rodapeAviso' => null,
                'rodapeAvisoTexto' => null,
                'rodapeNota' => $rodapeNota,
                'rodapeNotaTexto' => 'Este é um lembrete automático de vencimentos da sua conta no Stabil Money. '
                    .'Você pode desligá-lo em Configurações › Conta: '.route('settings', 'conta'),
            ],
        );
    }

    /**
     * @param  list<Item>  $itens
     * @return list<array{nome: string, quando: string, valor: string}>
     */
    private function linhas(array $itens): array
    {
        return array_map(fn (array $i) => [
            'nome' => $i['nome'],
            'quando' => self::quando((int) $i['diasRestantes']).' · '.$i['due']->format('d/m/Y'),
            'valor' => Brl::format($i['valor']),
        ], $itens);
    }

    private static function semMarcacao(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }
}
