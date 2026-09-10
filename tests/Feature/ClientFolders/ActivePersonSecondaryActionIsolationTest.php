<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\BusinessCheck;
use App\Models\BusinessCheckPhoto;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Models\ResidenceCheck;
use App\Models\ResidenceCheckPhoto;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivePersonSecondaryActionIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_check_media_and_contributor_actions_require_the_exact_active_person(): void
    {
        $ci = User::factory()->create();
        $companion = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);
        $personA = ['person' => 'co-maker', 'co_maker_id' => $coMakerA->id];
        $personB = ['person' => 'co-maker', 'co_maker_id' => $coMakerB->id];

        $residence = ResidenceCheck::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerA->id,
            'ci_date' => '2026-09-01', 'location' => 'A Residence', 'ci_user_id' => $ci->id,
            'map_screenshot_cloud_public_id' => 'tests/residence-map-a',
        ]);
        $residencePhoto = ResidenceCheckPhoto::create([
            'residence_check_id' => $residence->id, 'file_name' => 'Residence-A.jpg',
            'mime_type' => 'image/jpeg', 'byte_size' => 100, 'uploaded_by' => $ci->id,
            'cloud_public_id' => 'tests/residence-a', 'cloud_resource_type' => 'image', 'cloud_delivery_type' => 'upload',
        ]);

        $business = BusinessCheck::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerA->id,
            'business_name' => 'A Store', 'ci_date' => '2026-09-02', 'location' => 'A Business', 'ci_user_id' => $ci->id,
            'map_screenshot_cloud_public_id' => 'tests/business-map-a',
        ]);
        $businessPhoto = BusinessCheckPhoto::create([
            'business_check_id' => $business->id, 'category' => 'business', 'file_name' => 'Business-A.jpg',
            'mime_type' => 'image/jpeg', 'byte_size' => 100, 'uploaded_by' => $ci->id,
            'cloud_public_id' => 'tests/business-a', 'cloud_resource_type' => 'image', 'cloud_delivery_type' => 'upload',
        ]);

        foreach ([
            route('client-folders.residence-checks.photo', [$folder, $residence, $residencePhoto]),
            route('client-folders.residence-checks.map-screenshot', [$folder, $residence]),
            route('client-folders.business-checks.photo', [$folder, $business, $businessPhoto]),
            route('client-folders.business-checks.map-screenshot', [$folder, $business]),
        ] as $applicantUrl) {
            $this->actingAs($ci)->get($applicantUrl)->assertNotFound();
            $this->actingAs($ci)->get($applicantUrl.'?'.http_build_query($personB))->assertNotFound();
        }

        $this->actingAs($ci)->put(route('client-folders.residence-checks.contributors.update', [$folder, $residence]), [
            'contributor_ids' => [$companion->id],
        ])->assertNotFound();
        $this->actingAs($ci)->put(route('client-folders.business-checks.contributors.update', [$folder, $business] + $personB), [
            'contributor_ids' => [$companion->id],
        ])->assertNotFound();
        $this->assertDatabaseCount('residence_check_contributors', 0);
        $this->assertDatabaseCount('business_check_contributors', 0);

        $this->actingAs($ci)->put(route('client-folders.residence-checks.contributors.update', [$folder, $residence] + $personA), [
            'contributor_ids' => [$companion->id],
        ])->assertRedirect();
        $this->actingAs($ci)->put(route('client-folders.business-checks.contributors.update', [$folder, $business] + $personA), [
            'contributor_ids' => [$companion->id],
        ])->assertRedirect();
        $this->assertDatabaseHas('residence_check_contributors', ['residence_check_id' => $residence->id, 'user_id' => $companion->id]);
        $this->assertDatabaseHas('business_check_contributors', ['business_check_id' => $business->id, 'user_id' => $companion->id]);

        $residenceHtml = $this->actingAs($ci)->get(route('client-folders.residence-checks.edit', [$folder, $residence] + $personA))->assertOk()->getContent();
        $businessHtml = $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $business] + $personA))->assertOk()->getContent();
        $this->assertStringContainsString('co_maker_id='.$coMakerA->id, $residenceHtml);
        $this->assertStringContainsString('co_maker_id='.$coMakerA->id, $businessHtml);
    }

    public function test_activity_proof_and_note_actions_require_the_exact_active_person(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMakerA = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker A']);
        $coMakerB = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Co-Maker B']);
        $definition = ActivityDefinition::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();
        $activity = CiActivity::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerA->id,
            'activity_definition_id' => $definition->id, 'name' => $definition->name,
            'status' => ActivityStatus::Completed, 'completed_at' => now(), 'creator_id' => $ci->id,
        ]);
        $proof = MediaReference::create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMakerA->id,
            'media_type' => 'photo', 'category' => 'other', 'file_name' => 'Proof-A.jpg',
            'mime_type' => 'image/jpeg', 'byte_size' => 100, 'uploaded_by' => $ci->id,
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY,
            'cloudinary_public_id' => 'tests/proof-a', 'cloudinary_resource_type' => 'image',
            'cloudinary_secure_url' => 'https://res.cloudinary.test/proof-a.jpg',
        ]);
        $activity->mediaReferences()->attach($proof->id, ['label' => 'Proof A']);
        $personA = ['person' => 'co-maker', 'co_maker_id' => $coMakerA->id];
        $personB = ['person' => 'co-maker', 'co_maker_id' => $coMakerB->id];

        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $activity, $proof]))->assertNotFound();
        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $activity, $proof] + $personB))->assertNotFound();
        $this->actingAs($ci)->get(route('client-folders.activities.proof.content', [$folder, $activity, $proof] + $personA))
            ->assertRedirect('https://res.cloudinary.test/proof-a.jpg');

        $this->actingAs($ci)->post(route('client-folders.activities.notes.store', [$folder, $activity]), ['note' => 'Applicant leak'])->assertNotFound();
        $this->actingAs($ci)->post(route('client-folders.activities.notes.store', [$folder, $activity] + $personB), ['note' => 'B leak'])->assertNotFound();
        $this->assertDatabaseCount('activity_notes', 0);
        $this->actingAs($ci)->post(route('client-folders.activities.notes.store', [$folder, $activity] + $personA), ['note' => 'A only'])->assertRedirect();
        $this->assertDatabaseHas('activity_notes', ['ci_activity_id' => $activity->id, 'note' => 'A only']);

        $html = $this->actingAs($ci)->get(route('client-folders.activities.edit', [$folder, $activity] + $personA))->assertOk()->getContent();
        $this->assertStringContainsString('co_maker_id='.$coMakerA->id, $html);
        $this->assertStringNotContainsString('co_maker_id='.$coMakerB->id, $html);
    }
}
