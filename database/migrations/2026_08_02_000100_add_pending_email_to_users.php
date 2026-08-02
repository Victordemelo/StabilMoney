<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * E-mail novo aguardando confirmação NO PRÓPRIO endereço novo.
     *
     * Trocar o e-mail já exige a senha atual, mas isso não prova que o endereço digitado
     * é seu — e o e-mail é o que recupera a conta. Errar uma letra apontava a conta para
     * um endereço inexistente (ou de outra pessoa) e a recuperação ia junto.
     *
     * Por isso a troca passa a ser em duas etapas quando o app consegue enviar e-mail: o
     * novo endereço fica AQUI, e só substitui o `email` de verdade quando o link enviado
     * a ele for clicado. Enquanto isso o login continua pelo e-mail antigo.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');
            $table->timestamp('pending_email_sent_at')->nullable()->after('pending_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pending_email', 'pending_email_sent_at']);
        });
    }
};
