<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business Check is now independent from Business / Income Sources: it MAY reference an existing
 * business, but it must never create one. A person with no Business / Income Source yet still has
 * to be able to record a Business Check manually (Business Name, Location and CI Date typed by the
 * CI), and the NOT NULL income_source_id this table was created with made that impossible — the
 * only schema change the independent flow actually requires.
 *
 * The foreign key itself is deliberately kept: a check that DOES reference a business still points
 * at a real income_sources row, and existing linked Business Checks are untouched by this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_checks', function (Blueprint $table): void {
            $table->foreignId('income_source_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('business_checks', function (Blueprint $table): void {
            $table->foreignId('income_source_id')->nullable(false)->change();
        });
    }
};
