<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The LDDAP routing becomes Registered → For Out → Returned for ACIC → Approved | Cancelled,
 * with a history table recording every step.
 *
 * The old statuses map onto the new ones so no record is stranded:
 *
 *   compliance ("Returned")   → returned_for_acic   awaiting the admin's action, as before
 *   returned_from_routing     → returned_for_acic   likewise: back, awaiting review
 *   used / received (legacy)  → returned_for_acic   numbered under the old flow, still to be
 *                                                   reviewed — the check number is kept
 *
 * `remap()` is public so the mapping can be exercised directly in a test.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    public const REMAP = [
        'compliance' => 'returned_for_acic',
        'returned_from_routing' => 'returned_for_acic',
        'used' => 'returned_for_acic',
        'received' => 'returned_for_acic',
    ];

    public function up(): void
    {
        Schema::create('lddap_routing_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lddap_id')->constrained('lddaps')->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // The unit forwarded to, or received from.
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            // "Forward To" — whoever or wherever it went.
            $table->string('counterparty')->nullable();
            $table->date('acted_on')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['lddap_id', 'id']);
        });

        Schema::table('lddaps', function (Blueprint $table) {
            // The most recent forward and return, denormalised for the table and the record.
            $table->string('forward_to')->nullable()->after('remarks');
            $table->foreignId('forward_unit_id')->nullable()->after('forward_to')
                ->constrained('units')->nullOnDelete();
            $table->foreignId('forwarded_by')->nullable()->after('forward_unit_id')
                ->constrained('users')->nullOnDelete();
            $table->date('date_forwarded')->nullable()->after('forwarded_by');

            $table->foreignId('return_unit_id')->nullable()->after('date_forwarded')
                ->constrained('units')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->after('return_unit_id')
                ->constrained('users')->nullOnDelete();
            $table->date('date_returned')->nullable()->after('returned_by');
        });

        $this->remap();
    }

    /** Move every record on a retired status onto its replacement. */
    public function remap(): void
    {
        foreach (self::REMAP as $old => $new) {
            DB::table('lddaps')->where('status', $old)->update(['status' => $new]);
        }
    }

    public function down(): void
    {
        Schema::table('lddaps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropConstrainedForeignId('return_unit_id');
            $table->dropConstrainedForeignId('forwarded_by');
            $table->dropConstrainedForeignId('forward_unit_id');
            $table->dropColumn(['forward_to', 'date_forwarded', 'date_returned']);
        });

        Schema::dropIfExists('lddap_routing_history');
    }
};
