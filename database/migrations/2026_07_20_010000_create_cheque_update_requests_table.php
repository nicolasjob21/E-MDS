<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheque_update_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cheque_id')->constrained('cheques')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason'); // why the staff member wants the details changed
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable(); // admin's note on approve/reject
            $table->timestamps();

            // Fast lookup of a cheque's pending request(s) and the admin queue.
            $table->index(['status', 'cheque_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheque_update_requests');
    }
};
