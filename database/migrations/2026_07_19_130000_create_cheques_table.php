<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cheque_number')->unique();
            $table->string('status')->default('available'); // available | used
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Fast lookup of "next available" = lowest available number.
            $table->index(['status', 'cheque_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheques');
    }
};
