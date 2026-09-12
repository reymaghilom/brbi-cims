<?php

namespace Tests\Feature\Dashboard;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardCiActivityHistoryLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_dashboard_uses_exact_saved_ci_activity_and_target_labels_without_fabricating_old_history(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id,
            'created_by' => $ci->id,
            'display_name' => 'EXACT LABEL CLIENT',
        ]);

        $this->audit($ci, $folder, 'ci_activity.completed', []);
        $this->audit($ci, $folder, 'ci_activity.completed', ['activity_title' => 'Barangay Check', 'co_maker_id' => null]);
        $this->audit($ci, $folder, 'ci_activity.completed', ['activity_title' => 'Neighbor Check', 'co_maker_id' => 41]);
        $this->audit($ci, $folder, 'ci_activity.asset_target_completed', [
            'activity_title' => 'Asset Check',
            'asset_target_id' => 51,
            'asset_target_label' => 'City Assessor — ROPA Department',
            'co_maker_id' => null,
        ]);
        $this->audit($ci, $folder, 'ci_activity.bank_target_completed', [
            'bank_target_id' => 61,
            'bank_target_label' => 'BPI – Puerto',
            'bank_target_type' => 'bank_coop_check',
            'bank_target_type_label' => 'Bank / Coop Check',
            'co_maker_id' => null,
        ]);
        $this->audit($ci, $folder, 'ci_activity.bank_target_completed', [
            'bank_target_id' => 62,
            'bank_target_label' => 'FICCO',
            'bank_target_type' => 'loan_inquiry',
            'bank_target_type_label' => 'Loan Inquiry',
            'co_maker_id' => null,
        ]);
        $before = AuditLog::query()->count();

        $response = $this->actingAs($ci)->get(route('home'))->assertOk();
        $labels = collect($response->viewData('recentActivityAll'))->pluck('label');

        $this->assertContains('Barangay Check completed', $labels);
        $this->assertContains('Neighbor Check completed', $labels);
        $this->assertContains('Asset Check — City Assessor — ROPA Department completed', $labels);
        $this->assertContains('Bank / Coop Check — BPI – Puerto completed', $labels);
        $this->assertContains('Loan Inquiry — FICCO completed', $labels);
        $this->assertContains('CI Activity completed', $labels);
        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_existing_global_and_folder_histories_keep_exact_applicant_and_co_maker_isolation(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $coMakerA = $this->coMaker($folder, 'Maria Dela Cruz');
        $coMakerB = $this->coMaker($folder, 'Ana Santos');

        $this->audit($ci, $folder, 'ci_activity.completed', [
            'activity_id' => 71,
            'activity_title' => 'Barangay Check',
            'co_maker_id' => null,
        ]);
        $this->audit($ci, $folder, 'ci_activity.completed', [
            'activity_id' => 72,
            'activity_title' => 'Neighbor Check',
            'co_maker_id' => $coMakerA->id,
        ]);
        $this->audit($ci, $folder, 'ci_activity.completed', [
            'activity_id' => 73,
            'activity_title' => 'Asset Check',
            'co_maker_id' => $coMakerB->id,
        ]);

        $applicantActivities = $this->actingAs($ci)
            ->get(route('client-folders.activities.index', $folder))
            ->assertOk();
        $this->assertSame(['Barangay Check completed'], $applicantActivities->viewData('allHistory')->pluck('label')->all());

        $coMakerActivities = $this->get(route('client-folders.activities.index', [
            $folder,
            'person' => 'co-maker',
            'co_maker_id' => $coMakerA->id,
        ]))->assertOk();
        $this->assertSame(['Neighbor Check completed'], $coMakerActivities->viewData('allHistory')->pluck('label')->all());

        $folderContents = $this->get(route('client-folders.show', [
            $folder,
            'person' => 'co-maker',
            'co_maker_id' => $coMakerA->id,
        ]))->assertOk();
        $history = $folderContents->viewData('recentPersonActivity');
        $this->assertSame(['Neighbor Check completed'], $history->pluck('label')->all());
        $this->assertSame(['Co-Maker: '.mb_strtoupper($coMakerA->full_name)], $history->pluck('personContext')->all());
        $this->assertNotContains('Asset Check completed', $history->pluck('label'));

        $applicantHistory = $this->get(route('client-folders.show', $folder))
            ->assertOk()
            ->viewData('recentPersonActivity');
        $this->assertSame(['Barangay Check completed'], $applicantHistory->pluck('label')->all());
        $this->assertSame(['Applicant'], $applicantHistory->pluck('personContext')->all());
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $actor, ClientFolder $folder, string $action, array $metadata): AuditLog
    {
        return AuditLog::create([
            'user_id' => $actor->id,
            'client_folder_id' => $folder->id,
            'action' => $action,
            'module' => 'ci_activities',
            'description' => 'Recorded CI activity event.',
            'metadata' => $metadata,
        ]);
    }

    private function coMaker(ClientFolder $folder, string $name): CoMaker
    {
        [$first, $last] = explode(' ', $name, 2);

        return CoMaker::create([
            'client_folder_id' => $folder->id,
            'full_name' => $name,
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }
}
