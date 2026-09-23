<?php

namespace Tests\Feature\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BusinessReportSaveAfterDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const DELETED = 'This Business Report was deleted by another user while you were working on it. Please return to the Business Report page.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    #[DataProvider('personContexts')]
    public function test_a_stale_save_after_another_ci_deletes_the_report_cannot_recreate_or_audit_it(string $context): void
    {
        [$first, $second, $folder, $source, $personParams] = $this->context($context);
        $openedRevision = $source->revision;
        $updatedAudits = AuditLog::query()->where('action', 'business_report.updated')->count();

        $this->actingAs($first)
            ->deleteJson(route('client-folders.income-sources.business-report.destroy', [$folder, $source] + $personParams), [
                'expected_revision' => $openedRevision,
            ])
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        $response = $this->actingAs($second)->put(
            route('client-folders.income-sources.business.update', [$folder, $source] + $personParams),
            $this->fields('Stale Replacement') + ['expected_revision' => $openedRevision, 'co_maker_id' => $source->co_maker_id],
        );

        $response->assertRedirect(route('client-folders.income-sources.edit', [$folder, $source] + $personParams));
        $response->assertSessionHas('business_report_deleted.message', self::DELETED);
        $response->assertSessionMissing('business_manage_refresh');
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('business_reports', ['income_source_id' => $source->id]);
        $this->assertNotNull($source->fresh()->business_report_deleted_at);
        $this->assertSame($openedRevision + 1, $source->fresh()->revision);
        $this->assertSame($updatedAudits, AuditLog::query()->where('action', 'business_report.updated')->count());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'income_source.updated',
            'user_id' => $second->id,
            'client_folder_id' => $folder->id,
        ]);

        $page = $this->actingAs($second)->get($response->headers->get('Location'))->assertOk()->getContent();
        $this->assertStringContainsString('data-business-deleted-notify', $page);
        $this->assertStringContainsString('data-business-deleted-message', $page);
        $this->assertStringContainsString('data-business-deleted-income-source-id="'.$source->id.'"', $page);
        $this->assertStringContainsString(self::DELETED, $page);
        $this->assertStringNotContainsString('<div class="mb-4 rounded-control', $page);
        $this->assertMatchesRegularExpression('/data-business-save[^>]*disabled/', $page);
        $this->assertStringContainsString(e(route('client-folders.income-sources.manage', [$folder] + $personParams)), $page);
    }

    public function test_deleted_response_keeps_the_stale_modal_open_until_manual_close_then_refreshes(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));
        $handlerStart = strpos($javascript, "event.data?.type !== 'brbi:business-deleted'");
        $handlerEnd = strpos($javascript, 'const businessSavedNotify = document.querySelector', $handlerStart);
        $handler = substr($javascript, $handlerStart, $handlerEnd - $handlerStart);

        $this->assertStringContainsString('dialog.dataset.businessDeletedReturnUrl = returnUrl.href;', $handler);
        $this->assertStringContainsString("staleForm?.addEventListener('submit'", $handler);
        $this->assertStringNotContainsString('showToast(', $handler);
        $this->assertStringNotContainsString('4000', $handler);
        $this->assertStringNotContainsString('dialog.close();', $handler);
        $this->assertStringNotContainsString('refreshBusinessManagePage(', $handler);
        $this->assertStringNotContainsString('refreshReportsWorkspace();', $handler);
        $this->assertStringNotContainsString('window.location.reload()', $handler);

        $notify = substr($javascript, strpos($javascript, "const businessDeletedNotify = document.querySelector('[data-business-deleted-notify]')"), 1200);
        $this->assertStringContainsString('showToast(', $notify);
        $this->assertStringContainsString("'".self::DELETED."'", $notify);
        $this->assertStringContainsString("', 'error');", $notify);
        $this->assertStringNotContainsString('terminalDeleted', $notify);
        $this->assertStringContainsString("type: 'brbi:business-deleted'", $notify);
        $this->assertStringContainsString('incomeSourceId: businessDeletedNotify.dataset.businessDeletedIncomeSourceId', $notify);
        $this->assertStringContainsString('businessDeletedReturnUrl', $javascript);

        $closeLifecycle = substr($javascript, strpos($javascript, "if (dialog.matches('[data-business-report-dialog]')) {", strpos($javascript, "document.querySelectorAll('dialog').forEach")), 8000);
        $this->assertStringContainsString("new Event('editing-presence-release')", $closeLifecycle);
        $this->assertStringContainsString("frame.src = 'about:blank';", $closeLifecycle);
        $this->assertStringContainsString('refreshBusinessManagePage(returnUrl.href);', $closeLifecycle);
        $this->assertStringContainsString('refreshReportsWorkspace();', $closeLifecycle);
        $this->assertStringContainsString('window.location.assign(returnUrl.href);', $closeLifecycle);
        $this->assertLessThan(strpos($closeLifecycle, 'refreshBusinessManagePage(returnUrl.href);'), strpos($closeLifecycle, "frame.src = 'about:blank';"));
    }

    public function test_cancel_or_close_without_saving_has_an_authoritative_list_return_target(): void
    {
        [$first, , $folder, $source, $personParams] = $this->context('co-maker');

        $page = $this->actingAs($first)
            ->get(route('client-folders.income-sources.edit', [$folder, $source] + $personParams))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-business-list-return-url', $page);
        $this->assertStringContainsString(e(route('client-folders.income-sources.manage', [$folder] + $personParams)), $page);
    }

    public function test_normal_business_report_save_still_persists_without_the_deleted_signal(): void
    {
        [$first, , $folder, $source] = $this->context();

        $response = $this->actingAs($first)->put(
            route('client-folders.income-sources.business.update', [$folder, $source]),
            $this->fields('Normal Update') + ['expected_revision' => $source->revision],
        );

        $response->assertSessionHasNoErrors();
        $response->assertSessionMissing('business_report_deleted');
        $this->assertSame('Normal Update', $source->fresh()->businessReport->business_name);
    }

    public static function personContexts(): array
    {
        return [
            'Applicant' => ['applicant'],
            'Co-Maker' => ['co-maker'],
        ];
    }

    /** @return array{0: User, 1: User, 2: ClientFolder, 3: IncomeSource, 4: array<string, int|string>} */
    private function context(string $context = 'applicant'): array
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $first->id]);
        $activePerson = $context === 'co-maker'
            ? CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Maria Santos', 'first_name' => 'Maria', 'last_name' => 'Santos'])
            : null;
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $template = IncomeSourceTemplate::query()->where('template_type', 'retail_grocery_water_refilling')->firstOrFail();

        $this->actingAs($first)->post(route('client-folders.income-sources.store', [$folder] + $personParams), [
            'income_source_template_id' => $template->id,
            'co_maker_id' => $activePerson?->id,
        ] + $this->fields('Original Store'))->assertSessionHasNoErrors();

        return [$first, $second, $folder, $folder->incomeSources()->sole()->fresh(), $personParams];
    }

    private function fields(string $name): array
    {
        return [
            'intent' => 'stay',
            'source_name' => $name,
            'business_name' => $name,
            'report_category' => 'Retail',
            'main_business_address' => $name.' Address',
            'start_date' => '2026-09-01',
            'registered_owner' => 'Registered Owner',
            'year_established' => 2020,
            'properties' => [],
            'tenants' => [],
        ];
    }
}
