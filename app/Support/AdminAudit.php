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
        $descricao = $alvoDescricao !== null
            // Descrição pronta (o e-mail digitado num login que falhou, o "nome <e-mail>"
            // que o `excluir` monta antes do delete): entra como veio, só que dentro da
            // coluna. O log não pode falhar por causa de tamanho de texto.
            ? Texto::paraColuna($alvoDescricao)
            : ($alvo ? self::descreverAlvo($alvo) : null);

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

    /**
     * "Nome <e-mail>" de quem sofreu a ação, dentro dos 255 caracteres de `alvo_descricao`.
     *
     * Nome e e-mail aceitam 255 caracteres cada: juntos chegam a 513, e o MySQL recusava a
     * linha com erro 1406 (M-2 da auditoria de 05/09/2026). A ação é gravada ANTES do log,
     * então o banimento (ou a exclusão, que não tem volta) acontecia — e o que se perdia era
     * justamente o registro dela e o alerta por e-mail, com o admin olhando para um HTTP 500.
     *
     * Quem encolhe é o NOME: depois de uma exclusão, o e-mail é o único identificador único
     * que sobra da pessoa. Pública para quem precise montar a descrição antes de a pessoa
     * deixar de existir (é o caso do `excluir` do painel).
     */
    public static function descreverAlvo(User $alvo): string
    {
        return Texto::paraColuna($alvo->name, depois: ' <'.$alvo->email.'>');
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
