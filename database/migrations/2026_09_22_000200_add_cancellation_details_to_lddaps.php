<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelling an LDDAP records who did it, when, and why in columns of their own, and the
 * status takes the spelling the rest of the product uses — `canceled`.
 *
 * Existing canceled records are carried across: the status value is remapped (on the record and
 * throughout its routing history), and the details are backfilled from the review stamp the
 * old Cancel action wrote (`reviewed_by`, `reviewed_at`, `review_note`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lddaps', function (Blueprint $table) {
            $table->foreignId('canceled_by')->nullable()->after('date_returned')
                ->constrained('users')->nullOnDelete();
            $table->date('date_canceled')->nullable()->after('canceled_by');
            $table->text('cancel_reason')->nullable()->after('date_canceled');
        });

        $this->remap();
    }

    /** `cancelled` → `canceled` everywhere it is stored, and the details onto their columns. */
    public function remap(): void
    {
        DB::table('lddaps')->where('status', 'cancelled')->update(['status' => 'canceled']);
        DB::table('lddap_routing_history')->where('from_status', 'cancelled')->update(['from_status' => 'canceled']);
        DB::table('lddap_routing_history')->where('to_status', 'cancelled')->update(['to_status' => 'canceled']);
        DB::table('lddap_routing_history')->where('action', 'cancelled')->update(['action' => 'canceled']);

        // Only rows still without the details; the old Cancel stamped them as a review.
        DB::table('lddaps')
            ->where('status', 'canceled')
            ->whereNull('canceled_by')
            ->update([
                'canceled_by' => DB::raw('reviewed_by'),
                'date_canceled' => DB::raw('reviewed_at'),
                'cancel_reason' => DB::raw('review_note'),
            ]);
    }

    public function down(): void
    {
        DB::table('lddaps')->where('status', 'canceled')->update(['status' => 'cancelled']);
        DB::table('lddap_routing_history')->where('from_status', 'canceled')->update(['from_status' => 'cancelled']);
        DB::table('lddap_routing_history')->where('to_status', 'canceled')->update(['to_status' => 'cancelled']);
        DB::table('lddap_routing_history')->where('action', 'canceled')->update(['action' => 'cancelled']);

        Schema::table('lddaps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canceled_by');
            $table->dropColumn(['date_canceled', 'cancel_reason']);
        });
    }
};
