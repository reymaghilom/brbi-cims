<?php

namespace Tests\Feature\ClientFolders;

use App\Exceptions\CloudMediaUploadException;
use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\Media\CloudinaryMediaStorage;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessCheckAjaxSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_ajax_update_returns_the_payload_used_to_complete_the_modal_flow(): void
    {
        [$ci, $folder, $source, $check] = $this->createBusinessCheck();

        $response = $this->ajaxPost($ci, $folder, $this->updateData($source, $check->id, [
            'remarks' => 'Updated through the modal.',
        ]));

        $response->assertOk()->assertJson([
            'result' => 'success',
            'message' => 'Business Check updated successfully.',
            'status_type' => 'success',
            'return_url' => route('client-folders.residence-business.edit', $folder),
        ]);
        $this->assertSame('Updated through the modal.', $check->fresh()->remarks);
    }

    public function test_ajax_no_change_returns_an_in_place_outcome(): void
    {
        [$ci, $folder, $source, $check] = $this->createBusinessCheck();

        $this->ajaxPost($ci, $folder, $this->updateData($source, $check->id))
            ->assertOk()
            ->assertJson([
                'result' => 'no_change',
                'message' => 'Nothing changed. No updates were saved to the database.',
                'status_type' => 'info',
            ]);
    }

    public function test_omitted_name_preserves_the_saved_linked_snapshot_on_a_real_update(): void
    {
        [$ci, $folder, $source, $check] = $this->createBusinessCheck();
        $originalName = $check->business_name;
        $originalRevision = $check->revision;
        $source->forceFill(['business_name' => 'Renamed source'])->save();
        $payload = $this->updateData($source, $check->id, ['remarks' => 'New observation']);
        unset($payload['business_name']);

        $this->ajaxPost($ci, $folder, $payload)->assertOk()->assertJsonPath('result', 'success');

        $saved = $check->fresh();
        $this->assertSame($originalName, $saved->business_name);
        $this->assertSame($source->id, $saved->income_source_id);
        $this->assertSame('New observation', $saved->remarks);
        $this->assertSame($originalRevision + 1, $saved->revision);
        $this->assertSame(1, AuditLog::query()->where('action', 'business_check.updated')->count());
    }

    public function test_ajax_validation_failure_returns_json_and_does_not_update_the_check(): void
    {
        [$ci, $folder, $source, $check] = $this->createBusinessCheck();

        $response = $this->ajaxPost($ci, $folder, $this->updateData($source, $check->id, [
            'location' => '',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors('location');
        $this->assertSame('Poblacion, San Miguel, Bulacan', $check->fresh()->location);
    }

    public function test_ajax_cloud_upload_failure_returns_a_safe_502_and_rolls_back_the_entire_create(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $progressBefore = (float) $folder->progress_percent;
        $this->useCloudEvidenceStorage();
        $this->mock(CloudinaryMediaStorage::class, function ($cloud): void {
            $cloud->shouldReceive('enabled')->andReturn(true);
            $cloud->shouldReceive('store')->once()->andThrow(new CloudMediaUploadException);
        });

        $response = $this->ajaxPost($ci, $folder, [
            'request_token' => 'business-cloud-failure',
            'business_name' => 'Test Shop',
            'ci_date' => now()->toDateString(),
            'location' => 'Test Street',
            'photo_groups' => [[
                'caption' => 'Storefront',
                'photos' => [UploadedFile::fake()->image('front.jpg', 900, 700)->size(500)],
            ]],
        ]);

        $response->assertStatus(502)->assertJson([
            'result' => 'cloud_failure',
            'message' => 'Business Check was not saved because one or more photos could not be uploaded to cloud storage. Please check your connection and try again.',
            'status_type' => 'error',
        ]);
        $this->assertDatabaseCount('business_checks', 0);
        $this->assertDatabaseCount('business_check_photos', 0);
        $this->assertSame(0, AuditLog::query()->where('action', 'business_check.created')->where('client_folder_id', $folder->id)->count());
        $this->assertSame($progressBefore, (float) $folder->fresh()->progress_percent);
    }

    public function test_cloud_upload_failure_keeps_the_html_create_form_and_its_input_available_for_retry(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->useCloudEvidenceStorage();
        $this->mock(CloudinaryMediaStorage::class, function ($cloud): void {
            $cloud->shouldReceive('enabled')->andReturn(true);
            $cloud->shouldReceive('store')->once()->andThrow(new CloudMediaUploadException);
        });

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'business_name' => 'Test Shop',
            'ci_date' => now()->toDateString(),
            'location' => 'Test Street',
            'photo_groups' => [[
                'caption' => 'Storefront',
                'photos' => [UploadedFile::fake()->image('front.jpg', 900, 700)->size(500)],
            ]],
        ])->assertRedirect(route('client-folders.business-checks.create', $folder))
            ->assertSessionHas('statusType', 'error')
            ->assertSessionHasInput('business_name', 'Test Shop');

        $this->assertDatabaseCount('business_checks', 0);
    }

    public function test_retry_with_the_same_create_token_can_succeed_after_a_cloud_upload_failure(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->useCloudEvidenceStorage();
        $this->mock(CloudinaryMediaStorage::class, function ($cloud): void {
            $cloud->shouldReceive('enabled')->andReturn(true);
            $cloud->shouldReceive('store')->once()->andThrow(new CloudMediaUploadException);
            $cloud->shouldReceive('store')->once()->andReturn([
                'file_name' => 'business-photo.jpg', 'mime_type' => 'image/jpeg', 'byte_size' => 100,
                'checksum' => hash('sha256', 'business-photo'), 'cloud_public_id' => 'business-photo',
                'cloud_resource_type' => 'image', 'cloud_delivery_type' => 'authenticated',
                'cloud_format' => 'jpg', 'cloud_width' => 900, 'cloud_height' => 700,
            ]);
        });
        $data = [
            'request_token' => 'business-cloud-retry',
            'business_name' => 'Test Shop',
            'ci_date' => now()->toDateString(),
            'location' => 'Test Street',
        ];

        $this->ajaxPost($ci, $folder, $data + ['photo_groups' => [['photos' => [UploadedFile::fake()->image('front.jpg', 900, 700)->size(500)]]]])
            ->assertStatus(502);
        $this->ajaxPost($ci, $folder, $data + ['photo_groups' => [['photos' => [UploadedFile::fake()->image('front.jpg', 900, 700)->size(500)]]]])
            ->assertOk()->assertJsonPath('result', 'success');

        $this->assertDatabaseCount('business_checks', 1);
        $this->assertDatabaseCount('business_check_photos', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'business_check.created')->where('client_folder_id', $folder->id)->count());
    }

    public function test_ajax_handler_shows_the_structured_cloud_error_without_losing_its_generic_fallback(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $start = strpos($script, "document.querySelectorAll('[data-business-check-form]')");
        $end = strpos($script, '// Opt-in single-flight guard', $start);
        $handler = substr($script, $start, $end - $start);

        $this->assertStringContainsString("xhr.status === 502 && payload?.result === 'cloud_failure'", $handler);
        $this->assertStringContainsString("showToast(payload.message, 'error');", $handler);
        $this->assertStringContainsString("statusText.textContent = 'Uploading media…';", $handler);
        $this->assertStringContainsString('xhr.status === 404', $handler);
        $this->assertStringContainsString('This Business Check is no longer available.', $handler);
        $this->assertStringContainsString('Business Check could not be saved. Please check your connection and try again.', $handler);
        $this->assertStringContainsString("if (form.dataset.submitting === 'true' || submitButton.disabled) return;", $handler);
    }

    public function test_success_keeps_save_disabled_while_failures_restore_it_for_retry(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $start = strpos($script, "document.querySelectorAll('[data-business-check-form]')");
        $end = strpos($script, '// Opt-in single-flight guard', $start);
        $handler = substr($script, $start, $end - $start);

        $success = strpos($handler, "if (xhr.status === 200 && payload?.result === 'success')");
        $successReturn = strpos($handler, 'return;', $success);
        $responseReset = strpos($handler, 'resetButton();', $success);

        $this->assertNotFalse($success);
        $this->assertNotFalse($successReturn);
        $this->assertNotFalse($responseReset);
        $this->assertGreaterThan($successReturn, $responseReset);
        $this->assertStringContainsString("xhr.addEventListener('error', () => {\n            resetButton();", $handler);
        $this->assertStringContainsString("xhr.addEventListener('abort', resetButton);", $handler);
    }

    public function test_edit_form_exposes_the_exact_person_authoritative_close_refresh_target(): void
    {
        [$ci, $folder, , $check] = $this->createBusinessCheck();

        $page = $this->actingAs($ci)
            ->get(route('client-folders.business-checks.edit', [$folder, $check]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'data-business-check-list-return-url="'.e(route('client-folders.residence-business.edit', $folder)).'"',
            $page,
        );
    }

    public function test_manual_close_releases_presence_unloads_the_business_check_iframe_and_refreshes_authoritatively(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));
        $start = strpos($javascript, "const businessCheckForm = frameDocument?.querySelector('[data-business-check-form]')");
        $closeLifecycle = substr($javascript, $start, 6500);

        $this->assertStringContainsString('const checkForm = residenceForm || businessCheckForm;', $closeLifecycle);
        $this->assertStringContainsString('businessCheckForm?.dataset.businessCheckListReturnUrl', $closeLifecycle);
        $this->assertStringContainsString("dialog.dataset.checkCloseNeedsRefresh = saveConfirmed ? 'false' : 'true';", $closeLifecycle);
        $this->assertStringContainsString("new Event('editing-presence-release')", $closeLifecycle);
        $this->assertStringContainsString("new Event('unsaved-form-reset')", $closeLifecycle);
        $this->assertStringContainsString("frame.src = 'about:blank';", $closeLifecycle);
        $this->assertStringContainsString("dialog.dataset.checkCloseNeedsRefresh === 'true'", $closeLifecycle);
        $this->assertStringContainsString('refreshChecksListing();', $closeLifecycle);
        $this->assertStringContainsString('refreshReportsWorkspace();', $closeLifecycle);
        $this->assertStringContainsString('window.location.assign(returnUrl.href);', $closeLifecycle);
        $this->assertLessThan(strpos($closeLifecycle, 'refreshChecksListing();'), strpos($closeLifecycle, "frame.src = 'about:blank';"));

        $refreshStart = strpos($javascript, 'function refreshChecksListing()');
        $refreshEnd = strpos($javascript, 'function initializeClientSearch', $refreshStart);
        $smoothRefresh = substr($javascript, $refreshStart, $refreshEnd - $refreshStart);
        $this->assertStringContainsString('fetch(window.location.href', $smoothRefresh);
        $this->assertStringContainsString("new DOMParser().parseFromString(html, 'text/html')", $smoothRefresh);
        $this->assertStringContainsString('region.innerHTML = next.innerHTML;', $smoothRefresh);
        $this->assertStringNotContainsString('region.replaceWith(', $smoothRefresh);
        $this->assertStringNotContainsString('data-refreshing', $smoothRefresh);
        $this->assertStringNotContainsString('window.location.reload()', $smoothRefresh);
    }

    /** @return array{0: User, 1: ClientFolder, 2: IncomeSource, 3: BusinessCheck} */
    private function createBusinessCheck(): array
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create([
            'income_source_template_id' => $template->id,
            'template_type' => $template->template_type,
            'template_version' => $template->version,
            'source_name' => 'Sari-Sari Store',
            'business_name' => 'Sari-Sari Store',
        ]);
        $source->businessReport()->create([
            'business_name' => 'Sari-Sari Store',
            'main_business_address' => 'Poblacion, San Miguel, Bulacan',
            'report_category' => 'retail_grocery_water_refilling',
        ]);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [[
                'caption' => 'Storefront',
                'photos' => [UploadedFile::fake()->image('front.jpg', 900, 700)->size(500)],
            ]],
        ])->assertSessionHasNoErrors();

        return [$ci, $folder, $source, $folder->businessChecks()->firstOrFail()];
    }

    private function updateData(IncomeSource $source, int $checkId, array $overrides = []): array
    {
        return array_replace([
            'check_id' => $checkId,
            'expected_revision' => BusinessCheck::query()->whereKey($checkId)->value('revision'),
            'income_source_id' => $source->id,
            // The form always submits the (read-only for a linked business) Business Name field.
            'business_name' => BusinessCheck::query()->whereKey($checkId)->value('business_name'),
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
        ], $overrides);
    }

    private function ajaxPost(User $ci, ClientFolder $folder, array $data)
    {
        return $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $data, [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }
}
