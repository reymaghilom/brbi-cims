<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom "Other Business / Source of Income" checkbox categories, added alongside the default
 * catalog in config/business-report-templates.php rather than replacing any part of it.
 *
 * Purely additive: it creates one new table and touches no existing table, no existing row and no
 * stored Business Report data. The row id is the category's stable identity — the option key a
 * Business Report stores is derived from it (see CustomBusinessCategory::optionKey()), so renaming
 * the category never changes what saved reports point at.
 *
 * `is_active` is what makes removal safe: a category already used by saved report data is
 * deactivated (dropped from future selection, every historical report left intact) instead of
 * deleted, mirroring the Active/Inactive pattern ActivityDefinition already uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_business_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_business_categories');
    }
};
