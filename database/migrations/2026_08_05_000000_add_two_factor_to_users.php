<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verificação em duas etapas (2FA por app autenticador, TOTP).
 *
 * **Opcional por definição:** as quatro colunas nascem nulas e continuam nulas em quem
 * não ligar o recurso. Não existe caminho que ative 2FA sozinho — nem no cadastro, nem
 * para dependente, nem por padrão. Conta com tudo nulo = 2FA desligado, e o login segue
 * exatamente como sempre foi.
 *
 * Por que cada coluna:
 *
 *  - `two_factor_secret` — a chave compartilhada com o autenticador. **`text`, não
 *    `varchar`**, porque o cast `encrypted` do model transforma 32 caracteres base32 em
 *    ~250 de texto cifrado. Essa lição custou caro em `terms_accepted_ip` (migration
 *    2026_08_02_000200): em MySQL a coluna curta é erro 1406, e como a suíte roda em
 *    **sqlite, que não aplica limite de tamanho**, o defeito passaria verde aqui e só
 *    apareceria em produção.
 *
 *  - `two_factor_recovery_codes` — a lista de códigos de emergência, em JSON cifrado.
 *    Cifrado e não com hash DE PROPÓSITO: o segredo TOTP ao lado é obrigatoriamente
 *    reversível (sem ele não há como calcular o código), então guardar os códigos com
 *    hash não fecharia buraco nenhum — quem tem o banco E a APP_KEY já teria o segredo.
 *    Em troca, cifrado permite reexibir a lista quando o usuário gera outra.
 *
 *  - `two_factor_confirmed_at` — quando o usuário PROVOU que o autenticador já está
 *    configurado. É este campo (e não o segredo) que liga a exigência no login: entre
 *    "gerei o QR" e "confirmei o primeiro código" existe uma janela em que o app ainda
 *    não pode cobrar o código, sob pena de trancar a pessoa fora com um QR que ela não
 *    chegou a escanear.
 *
 *  - `two_factor_last_step` — o último passo de 30 s já gasto, para o mesmo código não
 *    valer duas vezes (replay). Quem espia a tela por cima do ombro, ou intercepta o
 *    POST, tem 30 segundos de vida do código; sem esta coluna, isso basta.
 *
 * ⚠️ Como `two_factor_secret` usa o cast `encrypted`, rotacionar a `APP_KEY` sem
 * preencher `APP_PREVIOUS_KEYS` deixa o segredo ilegível — e todo mundo que tiver 2FA
 * ligado fica trancado fora, com os códigos de recuperação como única saída.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password_changed_at');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Reentrante: um rollback que parou no meio não pode travar no segundo.
            $colunas = array_values(array_filter([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_step',
            ], fn (string $coluna) => Schema::hasColumn('users', $coluna)));

            if ($colunas !== []) {
                $table->dropColumn($colunas);
            }
        });
    }
};
