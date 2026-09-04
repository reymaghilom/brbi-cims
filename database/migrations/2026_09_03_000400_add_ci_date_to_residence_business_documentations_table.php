<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residence_business_documentations', function (Blueprint $table) {
            // The CI Date the documentation set reports. Nullable because it is prefilled from the
            // person's saved CI/BI Report only when one exists — otherwise the CI encoder enters it
            // manually, and a draft may be saved before they have. Like `location`, it is an
            // independent snapshot after save: later CI/BI edits never reach back into it.
            $table->date('ci_date')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('residence_business_documentations', function (Blueprint $table) {
            $table->dropColumn('ci_date');
        });
    }
};
