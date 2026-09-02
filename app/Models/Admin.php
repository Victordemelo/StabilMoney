<?php

namespace App\Models;

use App\Support\RecoveryCodes;
use App\Support\Totp;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;

/**
 * Administrador do painel — conta separada da de cliente.
 *
 * Não estende `App\Models\User` nem compartilha tabela com ele: privilégio de admin e
 * conta de cliente não podem viver no mesmo registro. Ver a migration
 * `2026_08_06_100000_create_admin_panel_tables` para o raciocínio completo.
 *
 * O TOTP é reimplementado aqui em cima de `Totp`/`RecoveryCodes` (os mesmos helpers do
 * 2FA dos usuários) em vez de reusar o `TwoFactorService`, que é tipado para `User`. A
 * duplicação é pequena e proposital: as REGRAS são diferentes — aqui o segundo fator é
 * obrigatório e não existe caminho para desligá-lo.
 */
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            // Cifrados em repouso: quem lê um dump do banco não consegue gerar códigos
            // válidos nem usar os de recuperação.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_login_ip' => 'encrypted',
        ];
    }

    /** O segundo fator já foi configurado E provado? */
    public function temDoisFatores(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Cria (ou recria) o segredo ainda NÃO confirmado.
     *
     * Só vira 2FA de verdade quando o admin digita um código correto — senão bastaria
     * abrir a tela de setup para "ter" o segundo fator sem nunca ter escaneado o QR.
     */
    public function iniciarDoisFatores(): void
    {
        $this->forceFill([
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_recovery_codes' => RecoveryCodes::gerar(),
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /**
     * Confirma o setup. Devolve os códigos de recuperação (única vez que eles aparecem
     * em texto) ou null se o código estiver errado.
     *
     * @return list<string>|null
     */
    public function confirmarDoisFatores(string $codigo): ?array
    {
        if (! $this->two_factor_secret || ! $this->verificarTotp($codigo)) {
            return null;
        }

        $this->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $this->two_factor_recovery_codes ?? [];
    }

    /**
     * Valida um código do autenticador e QUEIMA o passo usado.
     *
     * Sem a queima, o mesmo código serve de novo enquanto a janela de 30 s durar — e
     * um código interceptado (ombro, phishing, log) vira uma segunda entrada.
     */
    public function verificarTotp(string $codigo): bool
    {
        if (! $this->two_factor_secret) {
            return false;
        }

        $passo = Totp::verificar($this->two_factor_secret, $codigo, (int) $this->two_factor_last_step);

        if ($passo === null) {
            return false;
        }

        $this->forceFill(['two_factor_last_step' => $passo])->save();

        return true;
    }

    /** Consome um código de recuperação (uso único). */
    public function consumirCodigoDeRecuperacao(string $codigo): bool
    {
        $restantes = RecoveryCodes::consumir($this->two_factor_recovery_codes ?? [], $codigo);

        if ($restantes === null) {
            return false;
        }

        $this->forceFill(['two_factor_recovery_codes' => $restantes])->save();

        return true;
    }

    /** URI `otpauth://` do QR do setup. */
    public function uriDoAutenticador(): string
    {
        return Totp::uri(
            (string) $this->two_factor_secret,
            $this->email,
            config('app.name').' (painel)',
        );
    }

    public function registrarLogin(?string $ip): void
    {
        $this->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->save();
    }

    /**
     * Cria/atualiza um admin pelo e-mail. Usado só pelo comando `admin:criar` —
     * não existe tela de cadastro de administrador, de propósito.
     */
    public static function provisionar(string $nome, string $email, string $senhaEmTexto): self
    {
        $admin = static::firstOrNew(['email' => $email]);
        $admin->name = $nome;
        $admin->password = Hash::make($senhaEmTexto);
        $admin->save();

        return $admin;
    }
}
