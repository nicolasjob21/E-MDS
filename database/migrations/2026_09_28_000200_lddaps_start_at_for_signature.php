<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The LDDAP flow becomes: Add → **For Signature** → Assign LDDAP to ACIC (→ Approved, on the
 * ACIC) → the teller's half as before. RTS and Cancel are taken on a For Signature record; a
 * corrected RTS record is **Resubmitted** (comment required, notes optional) back to For
 * Signature.
 *
 * - Registered, For Out and Returned for ACIC are retired; a record sitting in one moves to For
 *   Signature. So does an Approved record that is not yet on an ACIC — "Approved" now means "on
 *   an ACIC". The forward / receive columns and history rows are kept, never shown.
 * - `lddap_routing_history.notes` — the Resubmit form's optional notes, beside its comment
 *   (`note`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lddap_routing_history', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('note');
        });

        DB::table('lddaps')->whereIn('status', ['registered', 'for_out', 'returned_for_acic'])
            ->update(['status' => 'for_signature']);
        DB::table('lddaps')->where('status', 'approved')->whereNull('acic_id')
            ->update(['status' => 'for_signature']);
    }

    public function down(): void
    {
        DB::table('lddaps')->where('status', 'for_signature')->update(['status' => 'registered']);

        Schema::table('lddap_routing_history', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
