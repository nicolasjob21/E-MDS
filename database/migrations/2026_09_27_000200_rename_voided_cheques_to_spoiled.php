<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Void" becomes **Spoiled**: the number is used up and the payment moves to a replacement
 * cheque. A spoiled cheque records who marked it and when, beside the reason it already kept in
 * `exception_reason`.
 *
 * Existing voided cheques are relabelled only — no replacement is issued for them. Their
 * status, and the status history that names them, read `spoiled` from now on; who and when are
 * taken from the history row that voided them. The audit log is append-only and is left as it
 * was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->foreignId('spoiled_by')->nullable()->after('exception_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('spoiled_at')->nullable()->after('spoiled_by');
        });

        $voided = DB::table('cheques')->where('status', 'voided')->pluck('id');

        foreach ($voided as $id) {
            $step = DB::table('cheque_status_history')
                ->where('cheque_id', $id)->where('to_status', 'voided')
                ->orderByDesc('id')->first(['user_id', 'created_at']);

            DB::table('cheques')->where('id', $id)->update([
                'status' => 'spoiled',
                'spoiled_by' => $step?->user_id,
                'spoiled_at' => $step?->created_at,
            ]);
        }

        DB::table('cheque_status_history')->where('to_status', 'voided')->update(['to_status' => 'spoiled']);
        DB::table('cheque_status_history')->where('from_status', 'voided')->update(['from_status' => 'spoiled']);
        DB::table('cheque_status_history')->where('action', 'voided')->update(['action' => 'spoiled']);
    }

    public function down(): void
    {
        DB::table('cheque_status_history')->where('action', 'spoiled')->update(['action' => 'voided']);
        DB::table('cheque_status_history')->where('from_status', 'spoiled')->update(['from_status' => 'voided']);
        DB::table('cheque_status_history')->where('to_status', 'spoiled')->update(['to_status' => 'voided']);
        DB::table('cheques')->where('status', 'spoiled')->update(['status' => 'voided']);

        Schema::table('cheques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spoiled_by');
            $table->dropColumn('spoiled_at');
        });
    }
};
