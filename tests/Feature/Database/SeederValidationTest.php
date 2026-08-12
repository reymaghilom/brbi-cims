<?php

namespace Tests\Feature\Database;

use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CompletionRule;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_and_guarded_demo_seeders_create_consistent_data(): void
    {
        config()->set('cims.demo_data_enabled', true);
        $this->seed();

        $this->assertSame(6, ActivityDefinition::count());
        $this->assertSame(4, IncomeSourceTemplate::count());
        $this->assertSame(3, IncomeSourceTemplate::where('is_active', true)->count());
        $this->assertSame(1, IncomeSourceTemplate::where('is_fallback', true)->count());
        $this->assertSame(0, CompletionRule::whereNotNull('weight')->count());
        $this->assertSame(1, User::count());
        $this->assertSame(0, User::where('role', 'administrator')->count());
        $this->assertSame(3, ClientFolder::count());
        $this->assertSame(18, CiActivity::count());

        IncomeSource::query()->each(function (IncomeSource $source) {
            $detailCount = (int) $source->generalReport()->exists() + (int) $source->businessReport()->exists();
            $this->assertSame(1, $detailCount, "Income source {$source->id} must have exactly one compatible detail record.");
        });
    }
}
