<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\RecordState;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Senior Credit Investigator role, and the one permission that separates it from a
 * Credit Investigator: reassigning a CIBI report's signatory.
 *
 * The role is otherwise a Credit Investigator — it must not pick up Administrator
 * privileges, and the signatory candidate list (active Credit Investigators) is
 * deliberately left exactly as it was.
 */
class SeniorCreditInvestigatorRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_role_exists_alongside_the_existing_roles(): void
    {
        $this->assertSame('senior_credit_investigator', UserRole::SeniorCreditInvestigator->value);
        $this->assertSame('Senior Credit Investigator', UserRole::SeniorCreditInvestigator->label());

        // Existing roles are untouched.
        $this->assertSame('administrator', UserRole::Administrator->value);
        $this->assertSame('Administrator', UserRole::Administrator->label());
        $this->assertSame('credit_investigator', UserRole::CreditInvestigator->value);
        $this->assertSame('Credit Investigator', UserRole::CreditInvestigator->label());
        $this->assertSame(
            ['administrator', 'credit_investigator', 'senior_credit_investigator'],
            array_map(fn (UserRole $role): string => $role->value, UserRole::cases()),
        );
    }

    public function test_an_administrator_can_create_and_promote_a_user_to_the_new_role(): void
    {
        $admin = User::factory()->administrator()->create();

        $this->actingAs($admin)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('senior_credit_investigator');

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'employee_id' => 'EMP-9001',
            'full_name' => 'SENIOR CI USER',
            'username' => 'senior.ci',
            'role' => UserRole::SeniorCreditInvestigator->value,
            'status' => UserStatus::Active->value,
            'password' => 'Sr#Investigator2026',
            'password_confirmation' => 'Sr#Investigator2026',
        ])->assertSessionHasNoErrors();

        $created = User::where('username', 'senior.ci')->sole();
        $this->assertSame(UserRole::SeniorCreditInvestigator, $created->role);

        // An existing Credit Investigator can be promoted through the same form.
        $investigator = User::factory()->create();
        $this->actingAs($admin)->put(route('admin.users.update', $investigator), [
            'employee_id' => $investigator->employee_id,
            'full_name' => $investigator->full_name,
            'username' => $investigator->username,
            'role' => UserRole::SeniorCreditInvestigator->value,
            'status' => UserStatus::Active->value,
        ])->assertSessionHasNoErrors();

        $this->assertSame(UserRole::SeniorCreditInvestigator, $investigator->fresh()->role);
    }

    public function test_a_senior_credit_investigator_may_reassign_the_signatory_and_a_regular_one_may_not(): void
    {
        [$senior, $investigator, $folder, $report] = $this->context();

        $this->assertTrue($senior->can('reassignSignatory', $report));
        $this->assertFalse($investigator->can('reassignSignatory', $report));
        $this->assertTrue(User::factory()->administrator()->create()->can('reassignSignatory', $report));
    }

    public function test_the_senior_credit_investigator_sees_the_same_signatory_control_as_an_administrator(): void
    {
        [$senior, $investigator, $folder, $report] = $this->context();
        $admin = User::factory()->administrator()->create();

        $adminContent = $this->actingAs($admin)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $seniorContent = $this->actingAs($senior)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        foreach (['CI/BI signatory management', 'Reassign CI/BI Signatory', 'id="cibi-reassign-signatory-dialog"'] as $marker) {
            $this->assertStringContainsString($marker, $adminContent);
            $this->assertStringContainsString($marker, $seniorContent, 'A Senior CI must get the same signatory control as an Administrator.');
        }

        // The regular Credit Investigator's restricted view is unchanged.
        $this->actingAs($investigator)->get(route('client-folders.show', $folder))
            ->assertOk()
            ->assertDontSee('CI/BI signatory management');
    }

    public function test_a_senior_credit_investigator_reassignment_persists_and_is_attributed_to_that_user(): void
    {
        [$senior, $investigator, $folder, $report] = $this->context();
        $newSignatory = User::factory()->create(['full_name' => 'NEW SIGNATORY']);

        $this->actingAs($senior)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Senior CI workload reassignment.',
        ])->assertRedirect(route('client-folders.show', $folder));

        $report->refresh();
        $this->assertSame($newSignatory->id, $report->ci_in_charge_id);
        // Ownership and the report's own state are untouched by a signatory change.
        $this->assertSame($investigator->id, $report->created_by);
        $this->assertNull($report->co_maker_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cibi_report.signatory_reassigned',
            'user_id' => $senior->id,
            'client_folder_id' => $folder->id,
        ]);
    }

    public function test_a_crafted_request_from_a_regular_credit_investigator_is_forbidden(): void
    {
        [$senior, $investigator, $folder, $report] = $this->context();
        $newSignatory = User::factory()->create();

        $this->actingAs($investigator)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Bypassing the interface entirely.',
        ])->assertForbidden();

        $this->assertSame($investigator->id, $report->fresh()->ci_in_charge_id);
    }

    public function test_an_ineligible_signatory_target_is_rejected_server_side(): void
    {
        [$senior, $investigator, $folder, $report] = $this->context();
        $rejected = [
            'inactive Credit Investigator' => User::factory()->create(['status' => UserStatus::Disabled])->id,
            'inactive Senior Credit Investigator' => User::factory()->seniorCreditInvestigator()->create(['status' => UserStatus::Disabled])->id,
            'Administrator' => User::factory()->administrator()->create()->id,
            'current signatory' => $report->ci_in_charge_id,
        ];

        foreach ($rejected as $description => $targetId) {
            $this->actingAs($senior)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
                'new_signatory_id' => $targetId,
                'reason' => 'Attempting to assign an '.$description.'.',
            ])->assertSessionHasErrors('new_signatory_id');
        }

        $this->assertSame($investigator->id, $report->fresh()->ci_in_charge_id);
    }

    /**
     * The full allowed matrix: either role that may reassign may name either Credit Investigator
     * grade, and the report's creator and prior audit history survive every one of them.
     */
    public function test_either_authorized_role_may_assign_either_credit_investigator_grade(): void
    {
        foreach (['administrator', 'senior_credit_investigator'] as $actorRole) {
            foreach (['credit_investigator', 'senior_credit_investigator'] as $targetRole) {
                [$senior, $investigator, $folder, $report] = $this->context();
                $actor = $actorRole === 'administrator'
                    ? User::factory()->administrator()->create()
                    : $senior;
                $target = $targetRole === 'credit_investigator'
                    ? User::factory()->create()
                    : User::factory()->seniorCreditInvestigator()->create();

                $this->actingAs($actor)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
                    'new_signatory_id' => $target->id,
                    'reason' => 'Reassigning a '.$actorRole.' choice of '.$targetRole.'.',
                ])->assertSessionHasNoErrors()->assertRedirect(route('client-folders.show', $folder));

                $report->refresh();
                $this->assertSame($target->id, $report->ci_in_charge_id, $actorRole.' must be able to assign a '.$targetRole.'.');
                $this->assertSame($investigator->id, $report->created_by);
                $this->assertDatabaseHas('audit_logs', [
                    'action' => 'cibi_report.signatory_reassigned',
                    'user_id' => $actor->id,
                    'client_folder_id' => $folder->id,
                ]);
            }
        }
    }

    /** A Senior Credit Investigator may name themselves — no self-selection rule exists. */
    public function test_an_authorized_senior_credit_investigator_may_name_themselves(): void
    {
        [$senior, $investigator, $folder, $report] = $this->context();

        $this->actingAs($senior)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $senior->id,
            'reason' => 'Taking over this report personally.',
        ])->assertSessionHasNoErrors();

        $this->assertSame($senior->id, $report->fresh()->ci_in_charge_id);
    }

    public function test_a_co_maker_report_keeps_its_own_signatory_when_the_applicant_report_is_reassigned(): void
    {
        [$senior, $investigator, $folder, $report] = $this->context();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'CO MAKER PERSON']);
        $coMakerReport = CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => $coMaker->id,
            'ci_in_charge_id' => $investigator->id, 'created_by' => $investigator->id, 'state' => RecordState::Draft,
        ]);
        $newSignatory = User::factory()->create();

        $this->actingAs($senior)->post(route('client-folders.cibi-report.reassign-signatory', [$folder, $report]), [
            'new_signatory_id' => $newSignatory->id,
            'reason' => 'Applicant-only reassignment.',
        ])->assertRedirect(route('client-folders.show', $folder));

        $this->assertSame($newSignatory->id, $report->fresh()->ci_in_charge_id);
        $this->assertSame($investigator->id, $coMakerReport->fresh()->ci_in_charge_id);
    }

    public function test_the_role_gains_no_administrator_privileges(): void
    {
        [$senior, $investigator, $folder, $report] = $this->context();

        // User management stays behind the administrator-only middleware.
        $this->actingAs($senior)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($senior)->get(route('admin.users.create'))->assertForbidden();

        // Folder assignment on create stays administrator-only, exactly as for a regular CI.
        foreach ([$senior, $investigator] as $actor) {
            $this->actingAs($actor)->post(route('client-folders.store'), [
                'last_name' => 'DELA CRUZ', 'first_name' => 'JUAN', 'middle_name' => null, 'suffix' => null,
                'assigned_ci_id' => $investigator->id,
            ])->assertSessionHasErrors('assigned_ci_id');
        }
    }

    public function test_the_role_keeps_ordinary_credit_investigator_access(): void
    {
        [$senior, $investigator, $folder, $report] = $this->context();

        $this->actingAs($senior)->get(route('client-folders.show', $folder))->assertOk();
        $this->actingAs($senior)->get(route('client-folders.index'))->assertOk();
        $this->assertTrue($folder->isAccessibleBy($senior));
        $this->assertTrue($senior->can('view', $report));
        $this->assertTrue($senior->can('viewHistory', $report));
    }

    /** @return array{0: User, 1: User, 2: ClientFolder, 3: CibiReport} */
    private function context(): array
    {
        $senior = User::factory()->seniorCreditInvestigator()->create(['full_name' => 'SENIOR CI']);
        $investigator = User::factory()->create(['full_name' => 'REASAN MARK Q. GURA']);
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $investigator->id]);
        $report = CibiReport::factory()->create([
            'client_folder_id' => $folder->id, 'co_maker_id' => null,
            'ci_in_charge_id' => $investigator->id, 'created_by' => $investigator->id, 'state' => RecordState::Draft,
        ]);

        return [$senior, $investigator, $folder, $report];
    }
}
