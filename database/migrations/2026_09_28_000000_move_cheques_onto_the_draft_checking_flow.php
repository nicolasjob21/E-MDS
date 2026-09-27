<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The cheque flow becomes: (no status) → Print Draft → For Checking → Approve → For Final Print →
 * Final Print → For Signature → Assign Cheque to ACIC → Approved …
 *
 * Out for Signature, Received and For ACIC are retired. A cheque sitting in one of them has been
 * printed and is waiting for its signature and an ACIC, so it moves to **For Signature** — the one
 * status "Assign Cheque to ACIC" now offers. The status history is left as it was written. No
 * columns change: `status` is text, and the timeline already records who, when and any comment.
 */
return new class extends Migration
{
    private const RETIRED = ['out_for_signature', 'received', 'for_acic'];

    public function up(): void
    {
        DB::table('cheques')->whereIn('status', self::RETIRED)->update(['status' => 'for_signature']);
    }

    public function down(): void
    {
        // For Signature has no single old equivalent; For ACIC is the nearest.
        DB::table('cheques')->where('status', 'for_signature')->update(['status' => 'for_acic']);
        DB::table('cheques')->whereIn('status', ['for_checking', 'for_compliance', 'for_final_print'])
            ->update(['status' => 'registered']);
    }
};
