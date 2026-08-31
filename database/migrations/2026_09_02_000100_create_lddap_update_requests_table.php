<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff-proposed corrections to an LDDAP's details. Nothing on the LDDAP changes until an
     * admin approves — the same request/approve flow the cheque register uses.
     */
    public function up(): void
    {
        Schema::create('lddap_update_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lddap_id')->constrained()->cascadeOnDelete();

            // The corrected values being proposed. The check number is not among them: it is
            // assigned by the series and never edited.
            $table->string('proposed_lddap_no');
            $table->string('proposed_obj_no')->nullable();
            $table->string('proposed_payee_name')->nullable();
            $table->decimal('proposed_amount', 15, 2);

            $table->text('reason');
            $table->string('status')->default('pending'); // pending | approved | rejected

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            // "Does this LDDAP have a pending request?" is asked on every list render.
            $table->index(['lddap_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lddap_update_requests');
    }
};
