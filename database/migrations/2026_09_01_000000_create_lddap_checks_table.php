<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The LDDAP check series: a register of check numbers that is entirely independent of
        // `cheques.cheque_number` and of `acics.acic_number`. Numbers are registered in ranges
        // and consumed lowest-first, exactly as cheque books are, but from their own pool.
        Schema::create('lddap_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('check_no')->unique();
            $table->string('status')->default('available'); // available | used
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Fast lookup of "next available" = lowest available number.
            $table->index(['status', 'check_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lddap_checks');
    }
};
