<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Quem lançou (titular ou dependente). Autor removido => null ("Removido").
            $table->foreignId('made_by_user_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });

        // Backfill: lançamentos antigos foram feitos pelo titular dono (user_id).
        DB::table('transactions')->whereNull('made_by_user_id')
            ->update(['made_by_user_id' => DB::raw('user_id')]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('made_by_user_id');
        });
    }
};
