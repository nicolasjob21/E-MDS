<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every edit of an LDDAP record through "Edit LDDAP Record": who, when, and which fields changed
 * (`changes` = {field: {from, to}}). Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lddap_edit_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lddap_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('changes');
            $table->timestamp('created_at')->nullable();

            $table->index(['lddap_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lddap_edit_history');
    }
};
