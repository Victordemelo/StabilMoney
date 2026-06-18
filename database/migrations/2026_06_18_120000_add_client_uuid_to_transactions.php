<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Idempotência da fila de lançamentos offline: o cliente gera um UUID
            // ao salvar offline e o reenvia no replay. O servidor não duplica se
            // já existir um lançamento com este uuid na família. Nulo para
            // lançamentos feitos pela web normal (não passam pela fila).
            $table->uuid('client_uuid')->nullable()->after('id');

            // Único POR FAMÍLIA (idempotency key escopada ao titular). Vários nulos
            // são permitidos (web normal) em MySQL e sqlite.
            $table->unique(['user_id', 'client_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
