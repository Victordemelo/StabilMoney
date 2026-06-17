<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // null = titular; preenchido = dependente apontando para o titular.
            // Excluir o titular dissolve a família (cascade nos dependentes).
            $table->foreignId('account_owner_id')->nullable()->after('is_admin')
                ->constrained('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_owner_id');
        });
    }
};
