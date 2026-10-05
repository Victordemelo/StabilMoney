<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Confiar neste aparelho por 7 dias" na segunda etapa do login (App\Support\AparelhoConfiavel).
 *
 * `two_factor_trust_version` entra na assinatura do cookie de aparelho confiável. Somar 1
 * aqui derruba de uma vez a confiança de TODOS os aparelhos da conta — é o que fazem
 * desligar/religar o 2FA, trocar os códigos de recuperação, trocar ou redefinir a senha,
 * "Encerrar outras sessões" e o botão "Esquecer todos os aparelhos confiáveis".
 *
 * Um contador, e não o `remember_token`: o token é reciclado a cada logout
 * (`SessionGuard::logout`), e a confiança cairia sempre que a pessoa saísse da conta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('two_factor_trust_version')->default(0)->after('two_factor_last_step');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_trust_version');
        });
    }
};
