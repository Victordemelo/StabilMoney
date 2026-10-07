<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permissões da página Família (out/2026 — pedido do Victor), escolhidas pelo TITULAR em
 * Configurações › Conta e guardadas na linha dele:
 *  - `familia_visivel`: os dependentes veem a página Família (quem usa a conta e quanto cada
 *    um gastou no mês), só para ver. Nasce LIGADA.
 *  - `familia_editavel`: além de ver, eles editam o cadastro dos OUTROS dependentes (nome,
 *    e-mail, foto, parentesco e senha). Nasce desligada. O titular nunca é editável por eles.
 * Nas linhas dos dependentes as colunas existem, mas não valem: quem decide é o titular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('familia_visivel')->default(true)->after('reminder_emails');
            $table->boolean('familia_editavel')->default(false)->after('familia_visivel');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['familia_visivel', 'familia_editavel']);
        });
    }
};
