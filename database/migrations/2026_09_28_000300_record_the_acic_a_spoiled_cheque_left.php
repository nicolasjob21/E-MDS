<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A spoiled cheque now comes off its ACIC when it is spoiled, and keeps a record of which ACIC
 * it was in (`spoiled_from_acic_id`) — so the ACIC is no longer held up by a record that can
 * never be forwarded, and the replacement may take the spoiled cheque's place on it.
 *
 * Existing spoiled cheques still sitting on an ACIC are moved the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->foreignId('spoiled_from_acic_id')->nullable()->after('acic_id')
                ->constrained('acics')->nullOnDelete();
        });

        DB::table('cheques')
            ->where('status', 'spoiled')
            ->whereNotNull('acic_id')
            ->update([
                'spoiled_from_acic_id' => DB::raw('acic_id'),
                'acic_id' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('cheques')
            ->where('status', 'spoiled')
            ->whereNull('acic_id')
            ->whereNotNull('spoiled_from_acic_id')
            ->update(['acic_id' => DB::raw('spoiled_from_acic_id')]);

        Schema::table('cheques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spoiled_from_acic_id');
        });
    }
};
