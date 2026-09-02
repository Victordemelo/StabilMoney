<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de tudo que um administrador faz sobre a conta de outra pessoa.
 *
 * **Por que existe:** o painel é o único lugar do app onde alguém age sobre dados que
 * não são seus. Poder sem rastro é o que transforma um erro honesto ("bani a pessoa
 * errada") num mistério, e um abuso em algo indemonstrável. Aqui fica quem fez, em
 * quem, quando, de onde e por quê.
 *
 * **Guarda o nome/e-mail do alvo em texto** (`alvo_descricao`): depois de uma exclusão,
 * o `target_user_id` aponta para o vazio e a linha perderia todo o sentido. É a única
 * cópia de identificação que sobrevive — e é por isso que ela NÃO inclui nada
 * financeiro: registro de moderação não é lugar para saldo de ninguém.
 */
class AdminAuditLog extends Model
{
    // Ações registradas. Constantes (não string solta) para o filtro da tela e as
    // asserções dos testes não dependerem de literal repetido.
    public const LOGIN = 'login';

    public const LOGIN_FALHOU = 'login_falhou';

    public const TOTP_FALHOU = 'totp_falhou';

    public const BANIU = 'baniu';

    public const DESBANIU = 'desbaniu';

    public const EXCLUIU = 'excluiu';

    /** Rótulos PT-BR para a tela. */
    public const ROTULOS = [
        self::LOGIN => 'Entrou no painel',
        self::LOGIN_FALHOU => 'Tentativa de login falhou',
        self::TOTP_FALHOU => 'Código de autenticação errado',
        self::BANIU => 'Baniu',
        self::DESBANIU => 'Desbaniu',
        self::EXCLUIU => 'Excluiu a conta',
    ];

    protected $fillable = [
        'admin_id', 'target_user_id', 'acao', 'alvo_descricao', 'motivo', 'ip',
    ];

    protected function casts(): array
    {
        return [
            // IP é dado pessoal (LGPD) e este log fica para sempre: cifrado em repouso.
            // Cifrar, não hashear — a tela precisa exibi-lo de volta.
            'ip' => 'encrypted',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function rotulo(): string
    {
        return self::ROTULOS[$this->acao] ?? $this->acao;
    }

    /** A ação representa alguma coisa dando errado? (pinta de vermelho na tela) */
    public function ehAlerta(): bool
    {
        return in_array($this->acao, [self::LOGIN_FALHOU, self::TOTP_FALHOU, self::EXCLUIU], true);
    }
}
