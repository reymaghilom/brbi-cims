<?php

namespace Tests\Feature\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adding or editing a Co-Maker whose first and last name (ignoring case and extra spaces) match
 * ANOTHER Co-Maker in the same Client Folder returns an advisory instead of saving. It is never a
 * block or a merge: Continue Anyway (duplicate_confirmed=1) saves a separate record, and it never
 * waives validation or the Co-Maker revision / stale-save protection.
 */
class CoMakerDuplicateNameWarningTest extends TestCase
{
    use RefreshDatabase;

    private const WARNING = 'A Co-Maker with the same first and last name already exists in this Client Folder. Please verify the details before continuing.';

    private const STALE = 'Your changes were not saved because this Co-Maker was updated by another user. Please reload the latest information before trying again.';

    private User $ci;

    private ClientFolder $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->ci = User::factory()->create();
        $this->folder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id, 'display_name' => 'APPLICANT NAME']);
        $this->coMaker($this->folder, 'Juan', 'Dela Cruz', 'Santos', null, 'Address 1');
    }

    // ---------------------------------------------------------------- Add

    /** @return array<string, array{0: array<string, string>, 1: bool}> */
    public static function addCases(): array
    {
        return [
            'unique first and last' => [['first_name' => 'Pedro', 'last_name' => 'Reyes'], false],
            'same first and last' => [['first_name' => 'Juan', 'last_name' => 'Dela Cruz'], true],
            'different case' => [['first_name' => 'JUAN', 'last_name' => 'dela cruz'], true],
            'surrounding spaces' => [['first_name' => '  Juan ', 'last_name' => ' Dela Cruz  '], true],
            'repeated inner spaces' => [['first_name' => 'Juan', 'last_name' => 'Dela   Cruz'], true],
            'same first, different last' => [['first_name' => 'Juan', 'last_name' => 'Santos'], false],
            'same last, different first' => [['first_name' => 'Pedro', 'last_name' => 'Dela Cruz'], false],
            'different middle name' => [['first_name' => 'Juan', 'middle_name' => 'Reyes', 'last_name' => 'Dela Cruz'], true],
            'different suffix' => [['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'suffix' => 'Jr.'], true],
        ];
    }

    #[DataProvider('addCases')]
    public function test_add_warns_only_for_the_same_first_and_last_name_in_the_same_folder(array $name, bool $warns): void
    {
        $response = $this->actingAs($this->ci)->postJson(route('client-folders.co-maker.store', $this->folder), $name);

        if ($warns) {
            $response->assertStatus(409)->assertExactJson(['result' => 'duplicate_warning', 'duplicate_warning' => true, 'message' => self::WARNING, 'status_type' => 'warning']);
            $this->assertSame(1, CoMaker::query()->where('client_folder_id', $this->folder->id)->count(), 'Nothing is created.');
            $this->assertSame(0, AuditLog::query()->where('action', 'co_maker.added')->count(), 'No success audit.');
        } else {
            $response->assertOk();
            $this->assertSame(2, CoMaker::query()->where('client_folder_id', $this->folder->id)->count());
        }
    }

    public function test_a_same_name_co_maker_in_another_folder_never_triggers_the_warning(): void
    {
        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $this->ci->id]);
        $elsewhere = $this->coMaker($otherFolder, 'Maria', 'Santos');

        $this->actingAs($this->ci)->postJson(route('client-folders.co-maker.store', $this->folder), ['first_name' => 'Maria', 'last_name' => 'Santos'])->assertOk();

        $this->assertSame(['Maria', 'Santos', 1], [$elsewhere->fresh()->first_name, $elsewhere->fresh()->last_name, $elsewhere->fresh()->revision]);
    }

    public function test_continue_anyway_adds_a_separate_co_maker_and_cancel_saves_nothing(): void
    {
        $existing = CoMaker::query()->where('client_folder_id', $this->folder->id)->sole();
        $payload = ['first_name' => 'Juan', 'last_name' => 'Dela Cruz'];

        // Cancel = the advisory was shown and the user did not resubmit.
        $this->actingAs($this->ci)->postJson(route('client-folders.co-maker.store', $this->folder), $payload)->assertStatus(409);
        $this->assertSame(1, CoMaker::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'co_maker.added')->count());

        // Continue Anyway.
        $this->actingAs($this->ci)->postJson(route('client-folders.co-maker.store', $this->folder), $payload + ['duplicate_confirmed' => '1'])->assertOk();

        $coMakers = CoMaker::query()->where('client_folder_id', $this->folder->id)->orderBy('id')->get();
        $this->assertCount(2, $coMakers);
        $this->assertNotSame($coMakers[0]->id, $coMakers[1]->id);
        $this->assertSame($existing->id, $coMakers[0]->id, 'The existing record is untouched — no merge, no reuse.');
        $this->assertSame(['Santos', 'Address 1', 1], [$coMakers[0]->middle_name, $coMakers[0]->address, $coMakers[0]->revision]);
        $this->assertSame(1, AuditLog::query()->where('action', 'co_maker.added')->count());
        $this->assertSame('APPLICANT NAME', $this->folder->fresh()->display_name);
    }

    public function test_validation_errors_come_before_the_duplicate_advisory_and_confirmation_waives_nothing_else(): void
    {
        $this->actingAs($this->ci)->postJson(route('client-folders.co-maker.store', $this->folder), ['first_name' => 'Juan', 'last_name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('last_name')->assertJsonMissingPath('duplicate_warning');
        $this->actingAs($this->ci)->postJson(route('client-folders.co-maker.store', $this->folder), ['first_name' => '', 'last_name' => 'Dela Cruz', 'duplicate_confirmed' => '1'])
            ->assertStatus(422)->assertJsonValidationErrors('first_name');
        $this->assertSame(1, CoMaker::query()->count());

        // Confirmation never gets past authorization or folder ownership.
        $otherFolder = ClientFolder::factory()->create();
        $foreign = $this->coMaker($otherFolder, 'Foreign', 'Maker');
        $this->actingAs($this->ci)->postJson(route('client-folders.co-maker.store', $this->folder), [
            'co_maker_id' => $foreign->id, 'expected_revision' => 1, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'duplicate_confirmed' => '1',
        ])->assertStatus(404);
        $this->assertSame('Maker', $foreign->fresh()->last_name);
        $this->get('/logout');
        auth()->logout();
        $this->postJson(route('client-folders.co-maker.store', $this->folder), ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'duplicate_confirmed' => '1'])->assertUnauthorized();
        $this->assertSame(1, CoMaker::query()->where('client_folder_id', $this->folder->id)->count());
    }

    // ---------------------------------------------------------------- Edit

    public function test_editing_never_matches_the_co_maker_against_itself(): void
    {
        $self = CoMaker::query()->where('client_folder_id', $this->folder->id)->sole();

        // Keep Juan Dela Cruz, change only the middle name / suffix / address.
        $this->edit($self, ['first_name' => 'JUAN', 'last_name' => 'Dela Cruz', 'middle_name' => 'Reyes', 'suffix' => 'Jr.', 'address' => 'Address 2'])->assertOk();

        $this->assertSame(['Reyes', 'Jr.', 'Address 2', 2], [$self->fresh()->middle_name, $self->fresh()->suffix, $self->fresh()->address, $self->fresh()->revision]);
        $this->assertSame(1, AuditLog::query()->where('action', 'co_maker.updated')->count());
    }

    public function test_editing_to_another_co_makers_name_warns_then_continue_anyway_or_cancel(): void
    {
        $maria = $this->coMaker($this->folder, 'Maria', 'Santos');
        $ana = $this->coMaker($this->folder, 'Ana', 'Reyes');

        // Cancel: the advisory is shown and Ana stays exactly as she was.
        $this->edit($ana, ['first_name' => 'maria', 'last_name' => ' Santos'])
            ->assertStatus(409)->assertJsonPath('duplicate_warning', true)->assertJsonPath('message', self::WARNING);
        $this->assertSame(['Ana', 'Reyes', 1], [$ana->fresh()->first_name, $ana->fresh()->last_name, $ana->fresh()->revision]);
        $this->assertSame(0, AuditLog::query()->where('action', 'co_maker.updated')->count());

        // Continue Anyway: Ana is edited; Maria is a separate, untouched record.
        $this->edit($ana, ['first_name' => 'Maria', 'last_name' => 'Santos', 'duplicate_confirmed' => '1'])->assertOk();
        $this->assertSame(['Maria', 'Santos', 2], [$ana->fresh()->first_name, $ana->fresh()->last_name, $ana->fresh()->revision]);
        $this->assertSame(['Maria', 'Santos', 1], [$maria->fresh()->first_name, $maria->fresh()->last_name, $maria->fresh()->revision]);
        $this->assertNotSame($maria->id, $ana->id);
        $this->assertSame(1, AuditLog::query()->where('action', 'co_maker.updated')->count());
    }

    public function test_keeping_a_name_that_another_co_maker_also_has_still_warns(): void
    {
        $twin = $this->coMaker($this->folder, 'Juan', 'Dela Cruz');

        $this->edit($twin, ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'middle_name' => 'Garcia'])
            ->assertStatus(409)->assertJsonPath('duplicate_warning', true);
        $this->assertNull($twin->fresh()->middle_name);
    }

    // ---------------------------------------------------------------- Concurrency

    public function test_continue_anyway_never_bypasses_the_stale_revision_check(): void
    {
        $maria = $this->coMaker($this->folder, 'Maria', 'Santos');
        $ana = $this->coMaker($this->folder, 'Ana', 'Reyes', null, null, 'Ana Address');
        $opened = $ana->revision;

        // User A saves Ana first.
        $this->actingAs($this->ci)->postJson(route('client-folders.co-maker.store', $this->folder), [
            'co_maker_id' => $ana->id, 'expected_revision' => $opened, 'first_name' => 'Ana', 'last_name' => 'Reyes', 'address' => 'Saved By A',
        ])->assertOk();
        $auditsAfterA = AuditLog::query()->count();

        // User B, on the stale form, renames Ana to Maria Santos and confirms the advisory.
        $userB = User::factory()->seniorCreditInvestigator()->create();
        foreach ([[], ['duplicate_confirmed' => '1']] as $confirmation) {
            $this->actingAs($userB)->postJson(route('client-folders.co-maker.store', $this->folder), [
                'co_maker_id' => $ana->id, 'expected_revision' => $opened, 'first_name' => 'Maria', 'last_name' => 'Santos',
            ] + $confirmation)->assertStatus(409)->assertExactJson(['result' => 'conflict', 'message' => self::STALE, 'status_type' => 'error']);
        }

        $this->assertSame(['Ana', 'Reyes', 'Saved By A', $opened + 1, $this->ci->id], [$ana->fresh()->first_name, $ana->fresh()->last_name, $ana->fresh()->address, $ana->fresh()->revision, $ana->fresh()->last_edited_by]);
        $this->assertSame(['Maria', 'Santos', 1], [$maria->fresh()->first_name, $maria->fresh()->last_name, $maria->fresh()->revision]);
        $this->assertSame($auditsAfterA, AuditLog::query()->count(), 'No success audit for the stale confirmed request.');
    }

    // ---------------------------------------------------------------- UI / schema

    public function test_the_advisory_dialog_and_continue_anyway_wiring(): void
    {
        $html = $this->actingAs($this->ci)->get(route('client-folders.show', $this->folder))->assertOk()->getContent();
        $dialog = substr($html, strpos($html, 'id="co-maker-duplicate-dialog"'));
        $dialog = substr($dialog, 0, strpos($dialog, '</dialog>'));

        $this->assertStringContainsString('Possible Duplicate Co-Maker', $dialog);
        $this->assertStringContainsString(self::WARNING, $dialog);
        $this->assertMatchesRegularExpression('/Cancel\s*<\/button>/', $dialog);
        $this->assertMatchesRegularExpression('/Continue Anyway\s*<\/button>/', $dialog);
        $this->assertStringContainsString('<div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:justify-end" data-co-maker-duplicate-actions>', $dialog);
        $this->assertStringContainsString('name="duplicate_confirmed" value="" data-co-maker-duplicate-confirmed', $html);

        $js = file_get_contents(resource_path('js/app.js'));
        $continue = substr($js, strpos($js, "const continueButton = event.target.closest('[data-co-maker-duplicate-continue]');"), 700);
        $this->assertMatchesRegularExpression("/confirmed\.value = '1';\s*form\.requestSubmit\(\);\s*confirmed\.value = '';/", $continue);
        $this->assertStringContainsString('if (response.status === 409 && payload.duplicate_warning) {', $js);
    }

    public function test_no_unique_name_constraint_exists(): void
    {
        $indexes = collect(Schema::getIndexes('co_makers'))->filter(fn (array $index): bool => $index['unique'] && ! $index['primary']);
        foreach ($indexes as $index) {
            $this->assertEmpty(array_intersect($index['columns'], ['first_name', 'last_name', 'full_name']), 'No unique index on Co-Maker names.');
        }

        DB::table('co_makers')->insert(['client_folder_id' => $this->folder->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'full_name' => 'Juan Dela Cruz', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(2, CoMaker::query()->where('client_folder_id', $this->folder->id)->where('first_name', 'Juan')->where('last_name', 'Dela Cruz')->count());
    }

    // ---------------------------------------------------------------- Helpers

    private function edit(CoMaker $coMaker, array $fields)
    {
        return $this->actingAs($this->ci)->postJson(route('client-folders.co-maker.store', $this->folder), [
            'co_maker_id' => $coMaker->id, 'expected_revision' => $coMaker->fresh()->revision,
        ] + $fields);
    }

    private function coMaker(ClientFolder $folder, string $first, string $last, ?string $middle = null, ?string $suffix = null, ?string $address = null): CoMaker
    {
        return CoMaker::create([
            'client_folder_id' => $folder->id, 'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'suffix' => $suffix,
            'full_name' => trim(implode(' ', array_filter([$first, $middle, $last, $suffix]))), 'address' => $address,
        ])->fresh();
    }
}
