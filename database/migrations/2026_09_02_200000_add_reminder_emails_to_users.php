<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lembrete de vencimento por e-mail (comando `lembretes:vencimentos`).
 *
 *  - `reminder_emails`: a preferência. Nasce LIGADA — o lembrete é a função do
 *    app chegando a quem não abriu o PWA, não marketing; quem não quiser desliga em
 *    Configurações › Conta e o comando pula a pessoa.
 *  - `reminder_last_sent_on`: a trava de idempotência do dia. O cron pode ser
 *    reexecutado (deploy, `schedule:run` chamado duas vezes); com a data gravada, a
 *    segunda passada do mesmo dia não manda um segundo e-mail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('reminder_emails')->default(true)->after('email_verified_at');
            $table->date('reminder_last_sent_on')->nullable()->after('reminder_emails');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['reminder_emails', 'reminder_last_sent_on']);
        });
    }
};
