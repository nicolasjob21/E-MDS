<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An LDDAP is now registered first and takes its check number later, once it is back from
 * routing. The check-number link therefore has to allow null, and a new record starts as
 * `registered` rather than `used`. Existing rows are untouched: they already carry a number and
 * keep whatever status they had.
 *
 * `lddap_no` was already unique (`lddaps_lddap_no_unique`); the unique index on
 * `lddap_check_id` stays, and nulls do not collide under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lddaps', function (Blueprint $table) {
            $table->foreignId('lddap_check_id')->nullable()->change();
            $table->string('status')->default('registered')->change();
        });
    }

    public function down(): void
    {
        Schema::table('lddaps', function (Blueprint $table) {
            $table->string('status')->default('used')->change();
            $table->foreignId('lddap_check_id')->nullable(false)->change();
        });
    }
};
