<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An LDDAP no longer consumes a number from the cheque register: it takes one from its own
     * independent check series. Dropping the cheque link means the status and the ACIC
     * membership that used to be read through the cheque now live on the LDDAP itself.
     */
    public function up(): void
    {
        Schema::table('lddaps', function (Blueprint $table) {
            // The unique index has to go before the column it covers, or SQLite refuses the drop.
            $table->dropUnique(['cheque_id']);
            $table->dropConstrainedForeignId('cheque_id');

            // The check number this LDDAP took, from the independent series. Unique: a number
            // can never be claimed twice, whatever happens above it.
            $table->foreignId('lddap_check_id')->unique()->constrained('lddap_checks')->cascadeOnDelete();

            // Details that used to sit on the cheque row.
            $table->string('payee_name')->nullable();
            $table->date('check_date')->nullable();

            // The lifecycle, previously derived from the cheque and now the LDDAP's own.
            $table->string('status')->default('used'); // used | received | completed | compliance | cancelled
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            // ACIC membership, previously read through `cheques.acic_id`.
            $table->foreignId('acic_id')->nullable()->constrained('acics')->nullOnDelete();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('lddaps', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('acic_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('used_by');
            $table->dropColumn(['payee_name', 'check_date', 'status', 'used_at', 'received_at', 'reviewed_at', 'review_note']);
            $table->dropUnique(['lddap_check_id']);
            $table->dropConstrainedForeignId('lddap_check_id');
            $table->foreignId('cheque_id')->unique()->constrained()->cascadeOnDelete();
        });
    }
};
