<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheque_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('username'); // denormalised so logs stay readable if a user is removed
            $table->unsignedInteger('cheque_number')->nullable();
            $table->string('action'); // login | logout | used_cheque | added_cheque_range | ...
            $table->text('description')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('action');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheque_logs');
    }
};
