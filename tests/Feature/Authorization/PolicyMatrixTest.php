<?php

namespace Tests\Feature\Authorization;

use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\ClientInformation;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessReport;
use App\Models\SystemSetting;
use App\Models\TelegramMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PolicyMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_folder_backed_policies_follow_administrator_and_assigned_ci_access(): void
    {
        $administrator = User::factory()->administrator()->create();
        $assignedCi = User::factory()->create();
        $otherCi = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assignedCi->id, 'created_by' => $administrator->id]);

        $resources = $this->createFolderResources($folder, $assignedCi);

        foreach ($resources as $resource) {
            $this->assertTrue(Gate::forUser($administrator)->allows('view', $resource), $resource::class.' should be visible to Administrator.');
            $this->assertTrue(Gate::forUser($assignedCi)->allows('update', $resource), $resource::class.' should be editable by assigned CI.');
            $this->assertFalse(Gate::forUser($otherCi)->allows('view', $resource), $resource::class.' leaked to another CI.');
        }
    }

    public function test_folder_policy_allows_expected_operations_and_reserves_permanent_delete(): void
    {
        $administrator = User::factory()->administrator()->create();
        $assignedCi = User::factory()->create();
        $otherCi = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $assignedCi->id]);

        $this->assertTrue(Gate::forUser($administrator)->allows('view', $folder));
        $this->assertTrue(Gate::forUser($administrator)->allows('forceDelete', $folder));
        $this->assertTrue(Gate::forUser($assignedCi)->allows('update', $folder));
        $this->assertTrue(Gate::forUser($assignedCi)->allows('delete', $folder));
        $this->assertFalse(Gate::forUser($assignedCi)->allows('forceDelete', $folder));
        $this->assertFalse(Gate::forUser($assignedCi)->allows('restore', $folder));
        $this->assertFalse(Gate::forUser($otherCi)->allows('view', $folder));
        $this->assertTrue(Gate::forUser($assignedCi)->allows('create', ClientFolder::class));
    }

    public function test_user_settings_and_audit_policies_are_administrator_only(): void
    {
        $administrator = User::factory()->administrator()->create();
        $ci = User::factory()->create();
        $subject = User::factory()->create();
        $setting = SystemSetting::create(['key' => 'policy-test', 'value' => ['enabled' => true]]);
        $audit = AuditLog::create(['action' => 'policy.test', 'module' => 'testing', 'description' => 'Policy test.']);

        foreach ([[User::class, 'viewAny'], [SystemSetting::class, 'viewAny'], [AuditLog::class, 'viewAny']] as [$resource, $ability]) {
            $this->assertTrue(Gate::forUser($administrator)->allows($ability, $resource));
            $this->assertFalse(Gate::forUser($ci)->allows($ability, $resource));
        }

        $this->assertTrue(Gate::forUser($administrator)->allows('update', $subject));
        $this->assertFalse(Gate::forUser($ci)->allows('update', $subject));
        $this->assertTrue(Gate::forUser($administrator)->allows('update', $setting));
        $this->assertFalse(Gate::forUser($ci)->allows('view', $setting));
        $this->assertTrue(Gate::forUser($administrator)->allows('view', $audit));
        $this->assertFalse(Gate::forUser($ci)->allows('view', $audit));
        $this->assertFalse(Gate::forUser($administrator)->allows('delete', $subject));
    }

    private function createFolderResources(ClientFolder $folder, User $user): array
    {
        $information = ClientInformation::factory()->create(['client_folder_id' => $folder->id]);
        $cibi = CibiReport::factory()->create(['client_folder_id' => $folder->id, 'ci_in_charge_id' => $user->id]);
        $income = IncomeSource::factory()->create(['client_folder_id' => $folder->id]);
        $residence = ResidenceBusinessReport::factory()->create(['client_folder_id' => $folder->id, 'ci_user_id' => $user->id]);
        $definition = ActivityDefinition::factory()->create();
        $activity = CiActivity::create(['client_folder_id' => $folder->id, 'activity_definition_id' => $definition->id, 'name' => 'Policy activity']);
        $media = MediaReference::factory()->create(['client_folder_id' => $folder->id, 'income_source_id' => $income->id, 'uploaded_by' => $user->id]);
        $generated = GeneratedReport::factory()->create(['client_folder_id' => $folder->id, 'income_source_id' => $income->id, 'generated_by' => $user->id]);
        $telegram = TelegramMessage::create([
            'client_folder_id' => $folder->id,
            'category' => 'business',
            'message_type' => 'photos',
            'caption' => 'Policy test',
            'caption_hash' => hash('sha256', 'Policy test'),
            'idempotency_key' => 'policy-test-'.$folder->id,
            'sent_by' => $user->id,
        ]);

        return [$information, $cibi, $income, $residence, $activity, $media, $generated, $telegram];
    }
}
