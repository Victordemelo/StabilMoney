<?php

namespace App\Support;

use App\Mail\AlertaDoPainel;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Registra uma ação do painel e, quando ela é sensível, avisa por e-mail.
 *
 * As duas coisas juntas de propósito: um log que ninguém lê não protege de nada. O
 * e-mail é o canal que um invasor com a sessão do painel na mão NÃO controla — é ele
 * que dá a chance de reagir no mesmo minuto.
 *
 * ⚠️ Falha de e-mail NUNCA derruba a ação. Banir alguém não pode falhar porque o SMTP
 * caiu; o log local é a fonte da verdade e o e-mail é o aviso.
 */
final class AdminAudit
{
    /** Ações que disparam e-mail além do log. */
    private const AVISAM = [
        AdminAuditLog::LOGIN,
        AdminAuditLog::BANIU,
        AdminAuditLog::DESBANIU,
        AdminAuditLog::EXCLUIU,
        AdminAuditLog::TOTP_FALHOU,
    ];

    public static function registrar(
        string $acao,
        ?Admin $admin,
        Request $request,
        ?User $alvo = null,
        ?string $motivo = null,
        ?string $alvoDescricao = null,
    ): AdminAuditLog {
        $descricao = $alvoDescricao ?? ($alvo ? $alvo->name.' <'.$alvo->email.'>' : null);

        $log = AdminAuditLog::create([
            'admin_id' => $admin?->id,
            'target_user_id' => $alvo?->id,
            'acao' => $acao,
            'alvo_descricao' => $descricao,
            'motivo' => $motivo,
            'ip' => $request->ip(),
        ]);

        if (in_array($acao, self::AVISAM, true)) {
            self::avisar($acao, $admin, $request, $descricao, $motivo);
        }

        return $log;
    }

    private static function avisar(
        string $acao,
        ?Admin $admin,
        Request $request,
        ?string $alvo,
        ?string $motivo,
    ): void {
        $para = config('admin.alert_email') ?: $admin?->email;

        if (! $para || ! Mailer::entrega()) {
            return; // sem destinatário ou sem SMTP de verdade: só o log
        }

        try {
            Mail::to($para)->send(new AlertaDoPainel(
                acao: $acao,
                adminNome: $admin?->name ?? 'desconhecido',
                contexto: ContextoDeSeguranca::doRequest($request),
                alvo: $alvo,
                motivo: $motivo,
            ));
        } catch (\Throwable $e) {
            // O aviso é best-effort. Registrar e seguir: a ação já aconteceu e o log
            // acima é o que vale.
            Log::warning('Falha ao enviar alerta do painel: '.$e->getMessage());
        }
    }
}
