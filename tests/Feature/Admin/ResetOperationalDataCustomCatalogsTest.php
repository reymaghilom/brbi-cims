<?php

namespace Tests\Feature\Admin;

use App\Actions\Admin\ResetOperationalData;
use App\Enums\ActivityStatus;
use App\Enums\RecordState;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\BusinessReport;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CustomBusinessCategory;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Reset Operational Data also clears the two user-created catalogs — custom Activity Types
 * (ActivityDefinition::isCustom(), the custom_ code prefix) and every custom Other Business /
 * Source of Income category — while every user account and every built-in Activity Type survives
 * with its exact id, code and credentials.
 */
class ResetOperationalDataCustomCatalogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_reset_preserves_users_and_built_ins_and_removes_both_custom_catalogs_repeatably(): void
    {
        $admin = User::factory()->administrator()->create(['full_name' => 'System Administrator']);
        $ci = User::factory()->create(['full_name' => 'Field Investigator']);
        $senior = User::factory()->seniorCreditInvestigator()->create(['full_name' => 'Senior Investigator']);
        $usersBefore = $this->userSnapshot();
        $this->assertCount(3, $usersBefore);

        $builtInsBefore = $this->builtInSnapshot();
        $this->assertNotEmpty($builtInsBefore);
        foreach ([ActivityDefinition::BARANGAY_CHECK_CODE, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityDefinition::ASSET_CHECK_CODE, ActivityDefinition::BANK_COOP_CHECK_CODE] as $code) {
            $this->assertContains($code, array_column($builtInsBefore, 'code'));
        }
        // A non-custom code that merely looks similar must never be caught by the custom rule.
        $lookalike = ActivityDefinition::query()->create(['code' => 'customer_visit', 'name' => 'Customer Visit', 'is_required' => false, 'is_active' => true]);
        $builtInsBefore = $this->builtInSnapshot();

        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);

        // Custom Activity Type through the existing Add Activity architecture, used by a real activity.
        $this->actingAs($ci)->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => '',
            'create_new_activity_type' => '1',
            'new_activity_type' => 'Employer Verification',
            'status' => ActivityStatus::Pending->value,
            'intent' => 'return',
        ])->assertSessionHasNoErrors();
        $viaForm = ActivityDefinition::query()->where('name', 'Employer Verification')->sole();
        $this->assertTrue($viaForm->isCustom());
        // An operational activity that references it (ci_activities restricts deletes of its type).
        if (! CiActivity::query()->where('activity_definition_id', $viaForm->id)->exists()) {
            CiActivity::create([
                'client_folder_id' => $folder->id, 'co_maker_id' => null,
                'activity_definition_id' => $viaForm->id, 'name' => $viaForm->name,
                'status' => ActivityStatus::Pending, 'creator_id' => $ci->id,
            ]);
        }
        $this->assertGreaterThan(0, CiActivity::query()->where('activity_definition_id', $viaForm->id)->count());

        // Two more custom types with the same authoritative marker, one inactive and unused.
        ActivityDefinition::query()->create(['code' => 'custom_supplier_call_abc123', 'name' => 'Supplier Call', 'is_required' => false, 'is_active' => true]);
        ActivityDefinition::query()->create(['code' => 'custom_old_type_def456', 'name' => 'Old Type', 'is_required' => false, 'is_active' => false]);

        // Custom Other Business categories, one referenced by a saved Other Business report.
        $usedCategory = CustomBusinessCategory::query()->create(['name' => 'Fish Pond', 'created_by' => $ci->id, 'updated_by' => $ci->id]);
        CustomBusinessCategory::query()->create(['name' => 'Tricycle Rental', 'created_by' => $ci->id]);
        CustomBusinessCategory::query()->create(['name' => 'Retired Category', 'is_active' => false, 'created_by' => $senior->id]);
        $otherBusiness = IncomeSourceTemplate::query()->where('template_type', 'other_business_source_of_income')->firstOrFail();
        $source = IncomeSource::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null, 'income_source_template_id' => $otherBusiness->id,
            'template_type' => $otherBusiness->template_type, 'state' => RecordState::Complete, 'revision' => 2,
        ]);
        (new BusinessReport)->forceFill([
            'income_source_id' => $source->id, 'business_name' => 'Pond', 'report_category' => 'other',
            'template_data' => ['fields' => ['income_sources' => [$usedCategory->optionKey()]]],
        ])->save();

        $templatesBefore = IncomeSourceTemplate::query()->orderBy('id')->pluck('template_type', 'id')->all();

        // Non-administrators still cannot run it, and nothing moves.
        $this->actingAs($ci)->post(route('admin.settings.reset-operational-data'), ['confirmation' => ResetOperationalData::CONFIRMATION_PHRASE])->assertForbidden();
        $this->assertSame(3, CustomBusinessCategory::query()->count());
        $this->assertSame(3, $this->customDefinitionCount());

        foreach ([1, 2] as $run) {
            $this->actingAs($admin)->post(route('admin.settings.reset-operational-data'), ['confirmation' => ResetOperationalData::CONFIRMATION_PHRASE])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('admin.settings.index'));

            // Users: identical rows — same ids, count, roles, status, credentials.
            $this->assertSame($usersBefore, $this->userSnapshot(), "Users must be untouched after reset #{$run}.");

            // Activity Types: only built-ins remain, with their exact ids and codes.
            $this->assertSame(0, $this->customDefinitionCount(), "No custom Activity Type may survive reset #{$run}.");
            $this->assertSame($builtInsBefore, $this->builtInSnapshot());
            $this->assertNotNull($lookalike->fresh());

            // Custom Other Business categories: catalog empty, no dangling report reference.
            $this->assertSame(0, CustomBusinessCategory::query()->count());
            $this->assertSame([], CustomBusinessCategory::catalogChoices());
            $this->assertSame(0, BusinessReport::query()->count());
            $this->assertSame(0, IncomeSource::query()->count());
            $this->assertSame(0, CiActivity::query()->count());

            // Static business templates/options are untouched.
            $this->assertSame($templatesBefore, IncomeSourceTemplate::query()->orderBy('id')->pluck('template_type', 'id')->all());
            $this->assertNotEmpty(config('business-report-templates'));
        }

        $this->assertSame(2, AuditLog::query()->where('action', 'system.operational_data_reset')->count());

        // The look-alike row was a test-only probe for the prefix rule, not a real built-in the Add
        // Activity page knows how to render, so it is removed before rendering that page.
        $lookalike->delete();

        // Built-in Add Activity options are still offered, and no custom one is.
        $fresh = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $this->actingAs($ci)->get(route('client-folders.activities.index', $fresh))->assertOk()
            ->assertViewHas('definitions', function ($definitions): bool {
                $codes = collect($definitions)->pluck('code');

                return $codes->contains(ActivityDefinition::BARANGAY_CHECK_CODE)
                    && $codes->contains(ActivityDefinition::BANK_COOP_CHECK_CODE)
                    && $codes->filter(fn ($code) => str_starts_with((string) $code, ActivityDefinition::CUSTOM_CODE_PREFIX))->isEmpty();
            });

        // Users can still sign in and work.
        $this->actingAs($ci)->get(route('home'))->assertOk();
    }

    public function test_a_failure_rolls_the_whole_reset_back_including_the_custom_catalogs(): void
    {
        $admin = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $custom = ActivityDefinition::query()->create(['code' => 'custom_rollback_type_111', 'name' => 'Rollback Type', 'is_required' => false, 'is_active' => true]);
        $category = CustomBusinessCategory::query()->create(['name' => 'Rollback Category']);
        $usersBefore = $this->userSnapshot();

        // The final step of the transaction (the system audit event) fails.
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->action === 'system.operational_data_reset') {
                throw new RuntimeException('Forced reset failure.');
            }
        });

        try {
            app(ResetOperationalData::class)->execute($admin);
            $this->fail('The forced failure was not raised.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNotNull($folder->fresh(), 'Operational data is rolled back.');
        $this->assertNotNull($custom->fresh(), 'Custom Activity Types are rolled back.');
        $this->assertNotNull($category->fresh(), 'Custom categories are rolled back.');
        $this->assertSame($usersBefore, $this->userSnapshot());
    }

    /** @return list<array<string, mixed>> */
    private function userSnapshot(): array
    {
        return DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    /** @return list<array{id: int, code: string}> */
    private function builtInSnapshot(): array
    {
        return ActivityDefinition::query()->orderBy('id')->get()
            ->reject(fn (ActivityDefinition $definition) => $definition->isCustom())
            ->map(fn (ActivityDefinition $definition) => ['id' => $definition->id, 'code' => $definition->code, 'name' => $definition->name, 'is_active' => $definition->is_active])
            ->values()
            ->all();
    }

    private function customDefinitionCount(): int
    {
        return ActivityDefinition::query()->get(['id', 'code'])->filter->isCustom()->count();
    }
}
