<?php

namespace Tests\Feature\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResidenceCheckSaveAfterDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const DELETED = 'This Residence Check was deleted by another user while you were working on it. Please return to the Residence & Business Check page.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    #[DataProvider('personContexts')]
    public function test_stale_ajax_save_after_delete_is_terminal_for_the_exact_person(string $context): void
    {
        $deletingCi = User::factory()->create();
        $staleCi = User::factory()->create();
        $folder = $this->folder($deletingCi);
        $coMaker = $context === 'co-maker'
            ? $folder->coMakers()->create(['full_name' => 'Maria Santos', 'first_name' => 'Maria', 'last_name' => 'Santos'])
            : null;
        $personParams = ActivePersonResolver::queryParams($coMaker);

        $this->actingAs($deletingCi)->post(
            route('client-folders.residence-checks.store', [$folder] + $personParams),
            [
                'co_maker_id' => $coMaker?->id,
                'ci_date' => now()->toDateString(),
                'location' => 'Exact Person Address',
                'photos' => [UploadedFile::fake()->image('original.jpg', 900, 700)],
            ],
        )->assertSessionHasNoErrors();

        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker?->id)->sole();
        $openedRevision = $check->revision;

        $editPage = $this->actingAs($staleCi)
            ->get(route('client-folders.residence-checks.edit', [$folder, $check] + $personParams))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('data-residence-check-deleted', $editPage);
        $this->assertStringContainsString('data-residence-list-return-url="'.e(route('client-folders.residence-business.edit', [$folder] + $personParams)).'"', $editPage);

        $this->actingAs($deletingCi)
            ->delete(route('client-folders.residence-checks.destroy', [$folder, $check] + $personParams))
            ->assertRedirect(route('client-folders.residence-business.edit', [$folder] + $personParams));

        $updatedAudits = AuditLog::query()->where('action', 'residence_check.updated')->count();
        $storedFiles = Storage::disk('local')->allFiles();

        $response = $this->actingAs($staleCi)->post(
            route('client-folders.residence-checks.store', [$folder] + $personParams),
            [
                'check_id' => $check->id,
                'expected_revision' => $openedRevision,
                'co_maker_id' => $coMaker?->id,
                'remarks' => 'A stale replacement must never be saved.',
                'photos' => [UploadedFile::fake()->image('stale.jpg', 900, 700)],
            ],
            ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
        );

        $response->assertNotFound()->assertJson([
            'result' => 'deleted',
            'message' => self::DELETED,
            'status_type' => 'error',
            'residence_check_id' => $check->id,
            'return_url' => route('client-folders.residence-business.edit', [$folder] + $personParams),
        ]);
        $this->assertDatabaseMissing('residence_checks', ['id' => $check->id]);
        $this->assertSame($updatedAudits, AuditLog::query()->where('action', 'residence_check.updated')->count());
        $this->assertSame($storedFiles, Storage::disk('local')->allFiles());
    }

    public static function personContexts(): array
    {
        return [
            'Applicant' => ['applicant'],
            'Co-Maker' => ['co-maker'],
        ];
    }

    public function test_deleted_response_stays_inside_the_modal_until_manual_close_then_cleans_up_and_refreshes(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));
        $xhrHandler = substr($javascript, strpos($javascript, "document.querySelectorAll('[data-residence-check-form]')"), 10000);
        $parentHandler = substr($javascript, strpos($javascript, "event.data?.type !== 'brbi:residence-deleted'"), 1300);
        $closeLifecycle = substr($javascript, strpos($javascript, "const residenceForm = frameDocument?.querySelector('[data-residence-check-form]')"), 6000);

        $this->assertStringContainsString("xhr.status === 404 && payload?.result === 'deleted'", $xhrHandler);
        $this->assertStringContainsString('markDeleted(payload.message);', $xhrHandler);
        $this->assertStringContainsString("form.dataset.residenceDeleted = 'true';", $xhrHandler);
        $this->assertStringContainsString("form.dataset.residenceDeleted === 'true'", $xhrHandler);
        $this->assertStringContainsString('submitButton.disabled = true;', $xhrHandler);
        $this->assertStringContainsString("message || '".self::DELETED."'", $xhrHandler);
        $this->assertStringContainsString("', 'error');", $xhrHandler);
        $this->assertStringNotContainsString('terminalDeleted', $xhrHandler);
        $this->assertStringContainsString("type: 'brbi:residence-deleted'", $xhrHandler);
        $this->assertStringContainsString('residenceCheckId: payload.residence_check_id', $xhrHandler);

        $this->assertStringContainsString('dialog.dataset.checkDeletedReturnUrl = returnUrl.href;', $parentHandler);
        $this->assertStringNotContainsString('showToast(', $parentHandler);
        $this->assertStringNotContainsString('4000', $parentHandler);
        $this->assertStringNotContainsString('dialog.close();', $parentHandler);
        $this->assertStringNotContainsString('refreshChecksListing();', $parentHandler);

        $this->assertStringContainsString("new Event('editing-presence-release')", $closeLifecycle);
        $this->assertStringContainsString("new Event('unsaved-form-reset')", $closeLifecycle);
        $this->assertStringContainsString("frame.src = 'about:blank';", $closeLifecycle);
        $this->assertStringContainsString('refreshChecksListing();', $closeLifecycle);
        $this->assertStringContainsString('refreshReportsWorkspace();', $closeLifecycle);
        $this->assertStringContainsString('window.location.assign(returnUrl.href);', $closeLifecycle);
        $this->assertLessThan(strpos($closeLifecycle, 'refreshChecksListing();'), strpos($closeLifecycle, "frame.src = 'about:blank';"));
    }

    public function test_cancel_x_or_escape_without_saving_still_uses_the_authoritative_list_refresh_target(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));
        $closeLifecycle = substr($javascript, strpos($javascript, "const residenceForm = frameDocument?.querySelector('[data-residence-check-form]')"), 6000);

        $this->assertStringContainsString('residenceForm?.dataset.residenceListReturnUrl', $closeLifecycle);
        $this->assertStringContainsString("dialog.dataset.checkCloseNeedsRefresh = saveConfirmed ? 'false' : 'true';", $closeLifecycle);
        $this->assertStringContainsString("dialog.dataset.checkCloseNeedsRefresh === 'true'", $closeLifecycle);

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

    public function test_deleted_toast_uses_the_same_default_auto_dismiss_helper_as_business_check(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));
        $helper = substr($javascript, strpos($javascript, 'function showToast('), 2600);

        $this->assertStringContainsString("function showToast(message, type = 'success', duration = 4500)", $helper);
        $this->assertStringContainsString('window.setTimeout(() => toast.remove(), duration);', $helper);
    }

    private function folder(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }
}
