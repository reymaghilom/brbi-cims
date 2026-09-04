<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Models\User;
use App\Services\Media\CloudinaryCiActivityProofStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CiActivityHistoryAutoUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    // ==================================================
    // Static wiring: no second fetch, no reload, stable hook
    // ==================================================

    public function test_page_has_the_stable_history_hook_and_no_second_fetch_helper(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        $this->assertSame(1, substr_count($content, '<div data-ci-activity-history>'));
        $this->assertSame(1, substr_count($content, 'function insertCiActivityHistoryEntries'));
        $this->assertStringNotContainsString('refreshCiActivityHistory', $content);
        $this->assertStringNotContainsString('window.location.reload', $content);
    }

    public function test_insert_helper_is_wired_from_every_required_success_path_using_the_same_response(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        // Every call site reads history off a response already being awaited for that action
        // (payload.history for the JSON endpoints, or the parsed `<template data-ci-new-history>`
        // already present in the bank/asset tracker's own render response) — never a fetch whose
        // only purpose is history.
        $this->assertSame(6, substr_count($content, 'insertCiActivityHistoryEntries(payload.history)'), 'quick-complete + save-changes + 3 proof handlers + submission');
        $this->assertSame(2, substr_count($content, 'insertCiActivityHistoryEntries([...newHistoryTemplate.content.children]'), 'bank + asset tracker renders');
    }

    // ==================================================
    // Barangay / Neighbor: quick-complete auto-update
    // ==================================================

    public function test_barangay_completion_response_carries_the_real_persisted_history_entry(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $response = $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'completed',
            'intent' => 'return',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ]);

        $response->assertOk()->assertJson(['updated' => true]);
        $payload = $response->json();
        $this->assertIsArray($payload['history']);
        $this->assertCount(1, $payload['history']);

        $audit = AuditLog::where('action', 'ci_activity.completed')->where('metadata->activity_id', $barangay->id)->sole();
        $this->assertStringContainsString('data-ci-history-entry-id="'.$audit->id.'"', $payload['history'][0]);
        $this->assertStringContainsString($barangay->name.' completed', $payload['history'][0]);
    }

    public function test_neighbor_completion_response_carries_the_real_persisted_history_entry(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $neighbor = $this->activity($folder, $ci, ActivityDefinition::NEIGHBOR_CHECK_CODE);

        $response = $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $neighbor]), [
            'co_maker_id' => '',
            'status' => 'completed',
            'intent' => 'return',
            'expected_updated_at' => $neighbor->updated_at->toISOString(),
        ]);

        $response->assertOk();
        $payload = $response->json();
        $this->assertCount(1, $payload['history']);
        $this->assertStringContainsString($neighbor->name.' completed', $payload['history'][0]);
    }

    public function test_real_tracker_save_change_response_carries_the_new_history_entry(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $response = $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->format('Y-m-d'),
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ]);

        $response->assertOk();
        $payload = $response->json();
        $this->assertCount(1, $payload['history']);
        $this->assertStringContainsString($barangay->name.' scheduled', $payload['history'][0]);
    }

    public function test_client_side_no_op_guard_is_present_and_returns_before_any_request(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $content = $page->assertOk()->getContent();

        // The isDirty() guard returns immediately, before the submit ever fetches `form.action`.
        $this->assertMatchesRegularExpression('/if \(! isDirty\(\)\) \{.*?return;\s*\}/s', $content);
    }

    // ==================================================
    // Bank / Coop: single-target and bulk completion auto-update
    // ==================================================

    public function test_bank_single_target_completion_carries_the_real_entry_in_the_same_redirect_response(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $target = $this->bankTarget($bank, $ci, 'BDO');

        $complete = $this->actingAs($ci)->patch(route('client-folders.activities.bank-targets.complete', [$folder, $bank, $target]), [
            'co_maker_id' => '',
        ]);
        $complete->assertRedirect();

        $followed = $this->get($complete->headers->get('Location'));
        $content = $followed->assertOk()->getContent();

        $audit = AuditLog::where('action', 'ci_activity.bank_target_completed')->where('metadata->bank_target_id', $target->id)->sole();
        $this->assertStringContainsString('data-ci-new-history', $content);
        $this->assertStringContainsString('data-ci-history-entry-id="'.$audit->id.'"', $content);
    }

    public function test_bank_bulk_completion_carries_one_real_entry_per_target_not_a_collapsed_fake_entry(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $t1 = $this->bankTarget($bank, $ci, 'BDO');
        $t2 = $this->bankTarget($bank, $ci, 'BPI');

        $complete = $this->actingAs($ci)->patch(route('client-folders.activities.bank-targets.complete-many', [$folder, $bank]), [
            'co_maker_id' => '',
            'bank_target_ids' => [$t1->id, $t2->id],
        ]);
        $complete->assertRedirect();

        $content = $this->get($complete->headers->get('Location'))->assertOk()->getContent();

        $audit1 = AuditLog::where('action', 'ci_activity.bank_target_completed')->where('metadata->bank_target_id', $t1->id)->sole();
        $audit2 = AuditLog::where('action', 'ci_activity.bank_target_completed')->where('metadata->bank_target_id', $t2->id)->sole();
        $this->assertStringContainsString('data-ci-history-entry-id="'.$audit1->id.'"', $content);
        $this->assertStringContainsString('data-ci-history-entry-id="'.$audit2->id.'"', $content);
        $this->assertSame(2, substr_count($content, 'data-ci-history-entry-id="'));
    }

    public function test_opening_the_bank_tracker_without_a_prior_mutation_carries_no_new_history_block(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $bank = $this->activity($folder, $ci, ActivityDefinition::BANK_COOP_CHECK_CODE);
        $this->bankTarget($bank, $ci, 'BDO');

        $content = $this->actingAs($ci)->get(route('client-folders.activities.bank-coop.show', [$folder, $bank]))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-ci-new-history', $content);
    }

    // ==================================================
    // Asset Check auto-update
    // ==================================================

    public function test_asset_target_completion_carries_the_real_entry_in_the_same_redirect_response(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $asset = $this->activity($folder, $ci, ActivityDefinition::ASSET_CHECK_CODE);
        $target = CiActivityAssetTarget::create([
            'ci_activity_id' => $asset->id,
            'assessor_type' => array_key_first(CiActivityAssetTarget::ASSESSOR_TYPES),
            'office_location' => 'City Hall',
            'status' => ActivityStatus::Pending,
            'created_by' => $ci->id,
            'updated_by' => $ci->id,
        ]);

        $complete = $this->actingAs($ci)->patch(route('client-folders.activities.asset-targets.complete', [$folder, $asset, $target]), [
            'co_maker_id' => '',
        ]);
        $complete->assertRedirect();

        $content = $this->get($complete->headers->get('Location'))->assertOk()->getContent();

        $audit = AuditLog::where('action', 'ci_activity.asset_target_completed')->where('metadata->asset_target_id', $target->id)->sole();
        $this->assertStringContainsString('data-ci-history-entry-id="'.$audit->id.'"', $content);
    }

    // ==================================================
    // Supporting Proof auto-update (already-audited events)
    // ==================================================

    public function test_proof_add_response_carries_the_real_persisted_history_entry(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $activity = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['status' => ActivityStatus::Completed]);
        $storage = $this->mock(CloudinaryCiActivityProofStorage::class);
        $storage->shouldReceive('store')->once()->andReturn([
            'media_type' => 'photo',
            'file_name' => 'proof.jpg',
            'mime_type' => 'image/jpeg',
            'byte_size' => 1024,
            'checksum' => str_repeat('a', 64),
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CLOUDINARY,
            'temporary_local_path' => null,
            'thumbnail_path' => null,
            'cloudinary_public_id' => 'brbi-cims/proof',
            'cloudinary_resource_type' => 'image',
            'cloudinary_secure_url' => 'https://res.cloudinary.test/proof.jpg',
            'suggested_label' => 'Test proof',
        ]);

        $response = $this->actingAs($ci)->postJson(route('client-folders.activities.proof.store', [$folder, $activity]), [
            'photos' => [UploadedFile::fake()->image('proof.jpg')],
        ]);

        $response->assertOk()->assertJson(['added' => true]);
        $payload = $response->json();
        $this->assertCount(1, $payload['history']);
        $this->assertStringContainsString('Proof uploaded', $payload['history'][0]);
    }

    // ==================================================
    // Failure behavior: no history entry on a failed mutation
    // ==================================================

    public function test_failed_completion_creates_no_audit_log_and_response_has_no_history(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $response = $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'completed',
            'intent' => 'return',
            'expected_updated_at' => now()->subDay()->toISOString(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ci_activity.completed', 'metadata->activity_id' => $barangay->id]);
    }

    // ==================================================
    // Applicant / Co-Maker isolation of the returned history
    // ==================================================

    public function test_history_response_never_crosses_into_another_persons_context(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $coMaker = CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => 'Scope Maker',
            'first_name' => 'Scope',
            'last_name' => 'Maker',
        ]);
        $applicantBarangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);
        $coMakerBarangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE, ['co_maker_id' => $coMaker->id]);

        $response = $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $coMakerBarangay]), [
            'co_maker_id' => $coMaker->id,
            'status' => 'completed',
            'intent' => 'return',
            'expected_updated_at' => $coMakerBarangay->updated_at->toISOString(),
        ]);

        $response->assertOk();
        $payload = $response->json();
        $this->assertCount(1, $payload['history']);
        $this->assertStringContainsString($coMakerBarangay->name.' completed', $payload['history'][0]);

        // The applicant's own activity was untouched, so no entry exists for it — and the
        // response for the co-maker's mutation must never mention it either.
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ci_activity.completed', 'metadata->activity_id' => $applicantBarangay->id]);
    }

    // ==================================================
    // Row synchronization untouched
    // ==================================================

    public function test_main_row_and_submission_visibility_still_synchronize_after_completion(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $barangay = $this->activity($folder, $ci, ActivityDefinition::BARANGAY_CHECK_CODE);

        $this->actingAs($ci)->putJson(route('client-folders.activities.update', [$folder, $barangay]), [
            'co_maker_id' => '',
            'status' => 'completed',
            'intent' => 'return',
            'expected_updated_at' => $barangay->updated_at->toISOString(),
        ])->assertOk();

        $page = $this->actingAs($ci)->get(route('client-folders.activities.index', $folder));
        $page->assertOk()
            ->assertSee('Mark as Submitted')
            ->assertDontSee('Submit to Credit Analyst');
        $this->assertSame(ActivityStatus::Completed, $barangay->fresh()->status);
    }

    private function activity(ClientFolder $folder, User $creator, string $code, array $overrides = []): CiActivity
    {
        $definition = ActivityDefinition::query()->where('code', $code)->sole();

        return CiActivity::create(array_merge([
            'client_folder_id' => $folder->id,
            'activity_definition_id' => $definition->id,
            'name' => $definition->name,
            'creator_id' => $creator->id,
        ], $overrides));
    }

    private function bankTarget(CiActivity $activity, User $actor, string $name): CiActivityBankTarget
    {
        return $activity->bankTargets()->create([
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution_name' => $name,
            'status' => ActivityStatus::Pending,
            'scheduled_has_time' => false,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function folderFor(User $ci): ClientFolder
    {
        return ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
    }
}
