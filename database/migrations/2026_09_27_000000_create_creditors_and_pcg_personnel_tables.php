<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two reference lists: `creditors` and `pcg_personnel`. Each entry is a name, an account number
 * (kept as text so leading zeros survive) and an optional unit. `created_at` and `created_by`
 * are stamped when the entry is added and never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['creditors', 'pcg_personnel'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('account_no');
                $table->string('unit')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pcg_personnel');
        Schema::dropIfExists('creditors');
    }
};
