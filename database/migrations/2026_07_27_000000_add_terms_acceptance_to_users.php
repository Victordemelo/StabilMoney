<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prova do aceite dos Termos de Uso / Política de Privacidade no cadastro.
     *
     * A LGPD (art. 8º, §1º) põe no controlador o ônus de PROVAR que o consentimento
     * foi obtido — validar o checkbox e não gravar nada não deixa rastro. Guardamos
     * quando, qual versão do documento e de qual IP o aceite veio.
     *
     * Fica nulo para dependentes (criados pelo titular, não passam pelo cadastro).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('password_changed_at');
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');
            $table->string('terms_accepted_ip', 45)->nullable()->after('terms_version'); // 45 = IPv6
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_version', 'terms_accepted_ip']);
        });
    }
};
