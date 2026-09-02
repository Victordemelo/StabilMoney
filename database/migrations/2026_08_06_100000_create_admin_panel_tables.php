<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Painel administrativo — tabelas e colunas.
 *
 * ## Por que `admins` é uma tabela SEPARADA, e não uma flag em `users`
 *
 * Privilégio de administrador e conta de cliente não podem morar no mesmo registro nem
 * na mesma sessão. Com uma flag, QUALQUER falha na área logada do app (um XSS, um IDOR,
 * uma sessão sequestrada) vira acesso ao painel — e o painel enxerga todo mundo. Com
 * guard próprio, o cookie de sessão do app não autentica no painel e vice-versa: são dois
 * mundos que só se encontram no banco.
 *
 * ⚠️ `users.is_admin` NÃO tem nada a ver com isto: lá `is_admin` significa "titular da
 * família" (todo mundo que não é dependente tem `true`). Foi por isso que uma coluna nova
 * de superadmin em `users` seria um convite ao acidente.
 *
 * ## Banimento
 *
 * Fica em `users` porque é atributo da pessoa, não do painel. `banned_at` nulo = ativa.
 * Nada é apagado: banir é reversível, e a exclusão de verdade é outra ação (LGPD).
 *
 * ## Sem FK de `banned_by_admin_id` para `admins` com cascade
 *
 * `nullOnDelete`: apagar um admin não pode apagar nem travar o registro de quem foi
 * banido. O histórico sobrevive ao autor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');

            // TOTP é OBRIGATÓRIO no painel, mas o admin nasce sem segredo: ele configura
            // no primeiro acesso, numa tela da qual não sai enquanto não confirmar.
            // `text` (não string): o cast `encrypted` infla o valor muito além de 255.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            // Último passo de 30s já gasto: barra o replay do MESMO código dentro da
            // janela em que ele ainda é válido.
            $table->unsignedBigInteger('two_factor_last_step')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->text('last_login_ip')->nullable(); // cast encrypted → text

            $table->timestamps();

            // Sem `remember_token` de propósito: "lembrar de mim" num painel de
            // administração é uma credencial de longa duração no disco de um navegador.
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('banned_at')->nullable()->after('relationship');
            $table->string('banned_reason', 500)->nullable()->after('banned_at');
            // SEM FK de propósito: `dropForeign` obriga o sqlite a recriar a tabela, e a
            // suíte roda em sqlite — o `down()` deixaria de ser real. Mesma decisão já
            // tomada para `transactions.fixed_bill_id` (ver CLAUDE.md).
            $table->unsignedBigInteger('banned_by_admin_id')->nullable()->after('banned_reason');
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            // Nulo quando o admin é excluído: a linha do histórico não pode sumir junto.
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            // Quem foi o alvo. SEM constrained: excluir a pessoa é justamente uma das
            // ações registradas aqui — com cascade, apagar alguém apagaria a prova de
            // que ela foi apagada.
            $table->unsignedBigInteger('target_user_id')->nullable()->index();
            $table->string('acao', 40)->index();
            // Nome/e-mail no momento da ação: depois da exclusão, o id não diz mais nada.
            $table->string('alvo_descricao')->nullable();
            $table->string('motivo', 500)->nullable();
            $table->text('ip')->nullable(); // cast encrypted → text
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['banned_at', 'banned_reason', 'banned_by_admin_id']);
        });

        Schema::dropIfExists('admins');
    }
};
