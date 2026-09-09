<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\EnsureCanonicalActivityDefinitions;
use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CiActivityDefaultsAndDropdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_applicant_gets_pending_defaults_and_only_canonical_addable_types(): void
    {
        $user = User::factory()->create();
        $folder = $this->folderFor($user);
        $this->assertSame(0, ActivityDefinition::query()->count());
        ActivityDefinition::query()->create([
            'code' => 'test_only_definition',
            'name' => 'Test Only Definition',
            'sort_order' => 999,
            'is_required' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->assertSee('Barangay Check')
            ->assertSee('Neighbor Check')
            ->assertSee('Select Activity Type')
            ->assertSee('Asset Check')
            ->assertSee('Bank/Coop Check')
            ->assertDontSee('Test Only Definition')
            ->assertSee('+ Add New Activity Type');

        $this->assertSame(
            [ActivityDefinition::ASSET_CHECK_CODE, ActivityDefinition::BANK_COOP_CHECK_CODE],
            $response->viewData('definitions')->pluck('code')->all(),
        );
        $this->assertSame(
            collect([
                ActivityDefinition::BARANGAY_CHECK_CODE,
                ActivityDefinition::NEIGHBOR_CHECK_CODE,
                ActivityDefinition::ASSET_CHECK_CODE,
                ActivityDefinition::BANK_COOP_CHECK_CODE,
            ])->sort()->values()->all(),
            ActivityDefinition::query()
                ->whereIn('code', [
                    ActivityDefinition::BARANGAY_CHECK_CODE,
                    ActivityDefinition::NEIGHBOR_CHECK_CODE,
                    ActivityDefinition::ASSET_CHECK_CODE,
                    ActivityDefinition::BANK_COOP_CHECK_CODE,
                ])
                ->pluck('code')
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertDefaultStatus($folder, null, ActivityDefinition::BARANGAY_CHECK_CODE, ActivityStatus::Pending);
        $this->assertDefaultStatus($folder, null, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Pending);
    }

    public function test_existing_active_custom_types_appear_but_inactive_and_non_custom_types_do_not(): void
    {
        $user = User::factory()->create();
        $folder = $this->folderFor($user);
        $this->actingAs($user)->get(route('client-folders.activities.index', $folder))->assertOk();
        $custom = ActivityDefinition::query()->create([
            'code' => ActivityDefinition::CUSTOM_CODE_PREFIX.'existing_inquiry',
            'name' => 'Existing Custom Inquiry',
            'sort_order' => 20,
            'is_required' => false,
            'is_active' => true,
        ]);
        ActivityDefinition::query()->create([
            'code' => ActivityDefinition::CUSTOM_CODE_PREFIX.'inactive_inquiry',
            'name' => 'Inactive Custom Inquiry',
            'sort_order' => 21,
            'is_required' => false,
            'is_active' => false,
        ]);
        ActivityDefinition::query()->create([
            'code' => 'test_only_definition',
            'name' => 'Test Only Definition',
            'sort_order' => 22,
            'is_required' => false,
            'is_active' => true,
        ]);

        $response = $this->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->assertSee('Existing Custom Inquiry')
            ->assertDontSee('Test Only Definition');

        // An inactive custom type is still listed (read-only) in Activity Type Management so it
        // can be reactivated, but it is never offered as a selectable Add Activity type.
        $content = $response->getContent();
        $this->assertStringContainsString('data-activity-type-name="Inactive Custom Inquiry"', $content);
        $this->assertStringNotContainsString('data-label="Inactive Custom Inquiry"', $content);

        $this->assertSame([
            ActivityDefinition::ASSET_CHECK_CODE,
            ActivityDefinition::BANK_COOP_CHECK_CODE,
            $custom->code,
        ], $response->viewData('definitions')->pluck('code')->all());
    }

    public function test_add_activity_type_flow_creates_a_selectable_definition_without_creating_an_activity(): void
    {
        $user = User::factory()->create();
        $folder = $this->folderFor($user);
        $this->actingAs($user)->get(route('client-folders.activities.index', $folder))->assertOk();
        $initialActivityCount = CiActivity::query()->where('client_folder_id', $folder->id)->count();

        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => null,
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'create_new_activity_type' => true,
            'new_activity_type' => 'Supplier Interview',
        ])->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('ci_activity_modal_open', true);

        $definition = ActivityDefinition::query()->where('name', 'Supplier Interview')->sole();
        $this->assertStringStartsWith(ActivityDefinition::CUSTOM_CODE_PREFIX.'supplier_interview_', $definition->code);
        $this->assertSame($initialActivityCount, CiActivity::query()->where('client_folder_id', $folder->id)->count());

        $this->get(route('client-folders.activities.index', $folder))
            ->assertOk()
            ->assertSee('Supplier Interview')
            ->assertViewHas('definitions', fn ($definitions): bool => $definitions->contains('id', $definition->id));

        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => null,
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'create_new_activity_type' => true,
            'new_activity_type' => '  Supplier   Interview ',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, ActivityDefinition::query()->where('code', 'like', ActivityDefinition::CUSTOM_CODE_PREFIX.'supplier_interview_%')->count());
        $this->assertSame($initialActivityCount, CiActivity::query()->where('client_folder_id', $folder->id)->count());
    }

    public function test_reserved_canonical_names_cannot_be_created_as_custom_definitions(): void
    {
        $user = User::factory()->create();
        $folder = $this->folderFor($user);
        $this->actingAs($user)->get(route('client-folders.activities.index', $folder))->assertOk();

        foreach (['Barangay Check', 'Neighbor Check', 'Asset Check', 'Bank/Coop Check'] as $name) {
            $this->post(route('client-folders.activities.store', $folder), [
                'co_maker_id' => null,
                'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
                'create_new_activity_type' => true,
                'new_activity_type' => $name,
            ])->assertRedirect()->assertSessionHasErrors('new_activity_type');
        }

        $this->assertSame(4, ActivityDefinition::query()->count());
        $this->assertSame(0, ActivityDefinition::query()->where('code', 'like', ActivityDefinition::CUSTOM_CODE_PREFIX.'%')->count());
    }

    public function test_custom_activity_rows_are_isolated_to_the_exact_person(): void
    {
        $user = User::factory()->create();
        $folder = $this->folderFor($user);
        $coMakerA = $this->coMakerFor($folder, 'Person Alpha');
        $coMakerB = $this->coMakerFor($folder, 'Person Bravo');
        $this->actingAs($user)->get(route('client-folders.activities.index', $folder))->assertOk();
        $this->get($this->personRoute($folder, $coMakerA))->assertOk();
        $this->get($this->personRoute($folder, $coMakerB))->assertOk();

        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => null,
            'activity_definition_id' => ActivityDefinition::NEW_TYPE_VALUE,
            'create_new_activity_type' => true,
            'new_activity_type' => 'Reference Interview',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $definition = ActivityDefinition::query()->where('name', 'Reference Interview')->sole();

        foreach ([null, $coMakerA->id] as $coMakerId) {
            $this->post(route('client-folders.activities.store', $folder), [
                'co_maker_id' => $coMakerId,
                'activity_definition_id' => $definition->id,
                'create_new_activity_type' => false,
                'status' => ActivityStatus::Pending->value,
                'remarks' => 'Exact person only.',
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(1, $this->activitiesFor($folder, null, $definition->code)->count());
        $this->assertSame(1, $this->activitiesFor($folder, $coMakerA->id, $definition->code)->count());
        $this->assertSame(0, $this->activitiesFor($folder, $coMakerB->id, $definition->code)->count());
    }

    public function test_repeated_initialization_does_not_duplicate_defaults(): void
    {
        $user = User::factory()->create();
        $folder = $this->folderFor($user);

        $this->actingAs($user)->get(route('client-folders.activities.index', $folder))->assertOk();
        $this->get(route('client-folders.activities.index', $folder))->assertOk();

        foreach (ActivityDefinition::MANDATORY_DEFAULT_CODES as $code) {
            $this->assertSame(1, $this->activitiesFor($folder, null, $code)->count());
        }
    }

    public function test_restored_completed_default_keeps_all_saved_data(): void
    {
        $user = User::factory()->create();
        $folder = $this->folderFor($user);
        app(EnsureCanonicalActivityDefinitions::class)->execute();
        $definition = $this->definition(ActivityDefinition::BARANGAY_CHECK_CODE);
        $completedAt = now()->subDay()->startOfSecond();
        $activity = CiActivity::query()->create([
            'client_folder_id' => $folder->id,
            'co_maker_id' => null,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'status' => ActivityStatus::Completed,
            'scheduled_at' => now()->subDays(2)->startOfSecond(),
            'scheduled_has_time' => true,
            'remarks' => 'Saved completion evidence remains intact.',
            'completed_at' => $completedAt,
            'creator_id' => $user->id,
            'updated_by' => $user->id,
        ]);
        $activity->delete();

        $this->actingAs($user)->get(route('client-folders.activities.index', $folder))->assertOk();

        $activity->refresh();
        $this->assertNull($activity->deleted_at);
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertSame('Saved completion evidence remains intact.', $activity->remarks);
        $this->assertTrue($activity->completed_at->equalTo($completedAt));
        $this->assertDefaultStatus($folder, null, ActivityDefinition::NEIGHBOR_CHECK_CODE, ActivityStatus::Pending);
    }

    public function test_applicant_and_each_co_maker_receive_isolated_defaults(): void
    {
        $user = User::factory()->create();
        $folder = $this->folderFor($user);
        $coMakerA = $this->coMakerFor($folder, 'Person Alpha');
        $coMakerB = $this->coMakerFor($folder, 'Person Bravo');

        $this->actingAs($user)->get(route('client-folders.activities.index', $folder))->assertOk();
        $this->get($this->personRoute($folder, $coMakerA))->assertOk();
        $this->get($this->personRoute($folder, $coMakerB))->assertOk();

        foreach ([null, $coMakerA->id, $coMakerB->id] as $coMakerId) {
            foreach (ActivityDefinition::MANDATORY_DEFAULT_CODES as $code) {
                $this->assertSame(1, $this->activitiesFor($folder, $coMakerId, $code)->count());
            }
        }

        $this->assertSame(6, CiActivity::query()->where('client_folder_id', $folder->id)->count());
    }

    public function test_asset_and_bank_coop_checks_remain_addable_with_their_target_architecture(): void
    {
        $user = User::factory()->create();
        $folder = $this->folderFor($user);
        $this->actingAs($user)->get(route('client-folders.activities.index', $folder))->assertOk();
        $asset = $this->definition(ActivityDefinition::ASSET_CHECK_CODE);
        $bank = $this->definition(ActivityDefinition::BANK_COOP_CHECK_CODE);

        $this->actingAs($user)->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => null,
            'activity_definition_id' => $asset->id,
            'create_new_activity_type' => false,
            'asset_targets' => [[
                'assessor_type' => 'city_assessor',
                'office_location' => 'City Hall',
                'status' => ActivityStatus::Pending->value,
                'scheduled_at' => null,
                'scheduled_time' => null,
                'remarks' => 'Asset target remains person-scoped.',
            ]],
        ])->assertRedirect();

        $this->post(route('client-folders.activities.store', $folder), [
            'co_maker_id' => null,
            'activity_definition_id' => $bank->id,
            'create_new_activity_type' => false,
            'bank_targets' => [
                [
                    'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
                    'institution_name' => 'First Cooperative',
                    'branch_location' => 'Main Branch',
                    'status' => ActivityStatus::Pending->value,
                    'scheduled_at' => null,
                    'scheduled_time' => null,
                    'remarks' => null,
                ],
                [
                    'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY,
                    'institution_name' => 'Second Bank',
                    'branch_location' => null,
                    'status' => ActivityStatus::Completed->value,
                    'scheduled_at' => null,
                    'scheduled_time' => null,
                    'remarks' => 'Completed target.',
                ],
            ],
        ])->assertRedirect();

        $assetActivity = $this->activitiesFor($folder, null, ActivityDefinition::ASSET_CHECK_CODE)->sole();
        $bankActivity = $this->activitiesFor($folder, null, ActivityDefinition::BANK_COOP_CHECK_CODE)->sole();
        $this->assertSame(ActivityStatus::Pending, $assetActivity->status);
        $this->assertSame(1, $assetActivity->assetTargets()->count());
        $this->assertSame(ActivityStatus::Pending, $bankActivity->status);
        $this->assertSame(2, $bankActivity->bankTargets()->count());
        $this->assertSame(0, $assetActivity->bankTargets()->count());
        $this->assertSame(0, $bankActivity->assetTargets()->count());
    }

    private function folderFor(User $user): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $user->id,
            'created_by' => $user->id,
        ]);
    }

    private function coMakerFor(ClientFolder $folder, string $fullName): CoMaker
    {
        [$firstName, $lastName] = explode(' ', $fullName, 2);

        return CoMaker::query()->create([
            'client_folder_id' => $folder->id,
            'full_name' => $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }

    private function personRoute(ClientFolder $folder, CoMaker $coMaker): string
    {
        return route('client-folders.activities.index', [
            'clientFolder' => $folder,
            'person' => 'co-maker',
            'co_maker_id' => $coMaker->id,
        ]);
    }

    private function definition(string $code): ActivityDefinition
    {
        return ActivityDefinition::query()->where('code', $code)->sole();
    }

    private function activitiesFor(ClientFolder $folder, ?int $coMakerId, string $code)
    {
        return CiActivity::query()
            ->where('client_folder_id', $folder->id)
            ->where('co_maker_id', $coMakerId)
            ->whereHas('definition', fn ($query) => $query->where('code', $code))
            ->get();
    }

    private function assertDefaultStatus(
        ClientFolder $folder,
        ?int $coMakerId,
        string $code,
        ActivityStatus $status,
    ): void {
        $activity = $this->activitiesFor($folder, $coMakerId, $code)->sole();

        $this->assertSame($status, $activity->status);
    }
}
