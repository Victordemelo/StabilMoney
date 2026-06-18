<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Marca uma despesa como PAGA. Usado pela recorrência "infinita":
            // a série tem 1 ocorrência em aberto por vez; pagá-la gera a próxima.
            // Nulo = em aberto.
            $table->timestamp('paid_at')->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
    }
};
