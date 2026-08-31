<?php

use App\Enums\AcicNumberStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ACIC numbers become a registered series rather than a generated counter.
 *
 * Blocks are registered up front and handed out lowest-unused-first, so a block registered below
 * numbers already in use is drawn on first. Every number already carried by an ACIC is backfilled
 * as `used`, so existing records keep their numbers and nothing can hand them out twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acic_numbers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('acic_number')->unique();
            $table->string('status')->default(AcicNumberStatus::Available->value);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // "The lowest available number" is the hot query.
            $table->index(['status', 'acic_number']);
        });

        $now = now();

        $taken = DB::table('acics')
            ->orderBy('acic_number')
            ->pluck('acic_number')
            ->map(fn ($number) => [
                'acic_number' => $number,
                'status' => AcicNumberStatus::Used->value,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($taken, 1000) as $chunk) {
            DB::table('acic_numbers')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acic_numbers');
    }
};
