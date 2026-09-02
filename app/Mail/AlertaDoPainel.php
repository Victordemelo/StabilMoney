<?php

namespace App\Mail;

use App\Models\AdminAuditLog;
use App\Support\ContextoDeSeguranca;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso de que algo aconteceu no painel administrativo.
 *
 * Separado do `AlertaDeSeguranca` (que é tipado para `User` e fala com o cliente sobre
 * a conta DELE) porque o público, o tom e o gatilho são outros: aqui o destinatário é o
 * administrador, e o assunto é uma ação sobre a conta de terceiros.
 *
 * ⚠️ Nada de valor financeiro entra neste e-mail — a mesma parede do painel. E o texto
 * interpolado passa por `e()`: os parágrafos são impressos com `{!! !!}` no layout.
 */
class AlertaDoPainel extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $acao,
        public string $adminNome,
        public ContextoDeSeguranca $contexto,
        public ?string $alvo = null,
        public ?string $motivo = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Painel] '.$this->titulo());
    }

    public function content(): Content
    {
        $detalhes = ['Administrador' => $this->adminNome] + $this->contexto->paraDetalhes();

        if ($this->alvo) {
            $detalhes['Pessoa'] = $this->alvo;
        }
        if ($this->motivo) {
            $detalhes['Motivo'] = $this->motivo;
        }

        return new Content(
            view: 'emails.layout',
            text: 'emails.layout-texto',
            with: [
                'saudacao' => 'Olá.',
                'titulo' => $this->titulo(),
                'preheader' => $this->titulo().' no painel do Stabil Money.',
                'paragrafos' => $this->paragrafos(),
                'paragrafosTexto' => array_map('strip_tags', $this->paragrafos()),
                'detalhes' => $detalhes,
                'acaoUrl' => null,
                'acaoRotulo' => null,
                'rodapeAviso' => $aviso = 'Se não foi você, troque a senha do painel imediatamente '
                    .'e desligue ADMIN_PANEL_ENABLED no servidor.',
                'rodapeAvisoTexto' => $aviso,
            ],
        );
    }

    private function titulo(): string
    {
        return AdminAuditLog::ROTULOS[$this->acao] ?? $this->acao;
    }

    /** @return list<string> */
    private function paragrafos(): array
    {
        $alvo = $this->alvo ? e($this->alvo) : 'uma conta';

        return match ($this->acao) {
            AdminAuditLog::LOGIN => [
                'Alguém entrou no <strong>painel administrativo</strong> do Stabil Money.',
                'Se foi você, não precisa fazer nada.',
            ],
            AdminAuditLog::TOTP_FALHOU => [
                'Houve uma tentativa de entrar no painel com <strong>código de autenticação '
                    .'errado</strong>. A senha estava correta.',
                'Se não foi você, considere que a senha do painel vazou.',
            ],
            AdminAuditLog::BANIU => ['A conta de <strong>'.$alvo.'</strong> foi <strong>banida</strong>.'],
            AdminAuditLog::DESBANIU => ['A conta de <strong>'.$alvo.'</strong> voltou a ter acesso.'],
            AdminAuditLog::EXCLUIU => [
                'A conta de <strong>'.$alvo.'</strong> foi <strong>excluída definitivamente</strong>, '
                    .'com todos os dados dela.',
                'Esta ação não tem volta.',
            ],
            default => ['Uma ação foi registrada no painel administrativo.'],
        };
    }
}
