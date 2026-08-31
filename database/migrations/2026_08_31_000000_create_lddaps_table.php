<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lddaps', function (Blueprint $table) {
            $table->id();

            // The cheque number this LDDAP-ADA was paid with. One LDDAP consumes exactly one
            // cheque number, so the link is 1:1 and the cheque is the single source of truth
            // for the LDDAP's status and for which ACIC it sits on.
            $table->foreignId('cheque_id')->unique()->constrained()->cascadeOnDelete();

            // The LDDAP-ADA serial as printed on the document. Unique: the same document can
            // never be registered twice, which is what stops a duplicate cheque assignment.
            $table->string('lddap_no')->unique();

            // Obligation / object of expenditure reference.
            $table->string('obj_no')->nullable();

            $table->decimal('amount', 15, 2);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lddaps');
    }
};
