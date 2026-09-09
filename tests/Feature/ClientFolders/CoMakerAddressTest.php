<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoMakerAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_new_co_maker_can_be_created_with_an_address_and_it_is_stored(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'address' => 'Zone 3, Barangay Puerto, Cagayan de Oro City, Misamis Oriental',
        ])->assertRedirect();

        $coMaker = $folder->coMakers()->firstOrFail();
        $this->assertSame('Zone 3, Barangay Puerto, Cagayan de Oro City, Misamis Oriental', $coMaker->address);
    }

    /**
     * Every co-maker action carries an icon beside its label, and the Add/Edit label swap is done
     * on its own element so the icon survives it — writing the whole button's text used to wipe
     * the icon the moment the modal opened.
     */
    public function test_the_co_maker_actions_render_their_icons_and_keep_their_labels(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $folder->coMakers()->create(['full_name' => 'Juan Dela Cruz', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz']);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();

        // Save / Update Co-Maker: one icon, and a label element the script can rewrite on its own.
        $submit = $this->tagFor($content, 'data-co-maker-submit', '</button>');
        $this->assertStringContainsString('<svg', $submit, 'Save Co-Maker must show an icon.');
        $this->assertStringContainsString('Save Co-Maker', $submit);
        $this->assertStringContainsString('data-co-maker-submit-label', $submit);
        $this->assertStringContainsString('<span data-co-maker-submit-label>Save Co-Maker</span>', $submit);

        // Remove confirmation: Cancel and the destructive action both carry an icon.
        $removeDialog = substr($content, strpos($content, 'id="co-maker-remove-dialog"'));
        $removeDialog = substr($removeDialog, 0, strpos($removeDialog, '</dialog>'));

        $removeSubmit = $this->tagFor($removeDialog, 'class="ui-button-danger"', '</button>');
        $this->assertStringContainsString('<svg', $removeSubmit, 'Remove Co-Maker must show an icon.');
        $this->assertStringContainsString('Remove Co-Maker', $removeSubmit);
        $this->assertStringContainsString('data-co-maker-remove-submit', $removeSubmit, 'The destructive action keeps its hook.');

        $cancel = substr($removeDialog, strpos($removeDialog, 'data-modal-close class="ui-button-secondary"'), 600);
        $this->assertStringContainsString('<svg', $cancel, 'Remove Co-Maker Cancel must show an icon.');
        $this->assertStringContainsString('Cancel', $cancel);

        // The script rewrites only the label element, never the button itself.
        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringNotContainsString("submit.textContent = 'Update Co-Maker'", $script);
        $this->assertStringNotContainsString("submit.textContent = 'Save Co-Maker'", $script);
        $this->assertStringContainsString('[data-co-maker-submit-label]', $script);
    }

    /** Closing any co-maker dialog leaves the folder page alone; only its own errors reopen it. */
    public function test_the_co_maker_dialogs_stay_isolated_from_one_another(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        // A rejected co-maker submission reopens the co-maker dialog and nothing else.
        $page = $this->actingAs($ci)
            ->from(route('client-folders.show', $folder))
            ->followingRedirects()
            ->post(route('client-folders.co-maker.store', $folder), ['first_name' => '', 'last_name' => ''])
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-open-on-error="true"', $this->tagFor($page, 'id="co-maker-dialog"', '>'));
        $this->assertStringNotContainsString('data-open-on-error="true"', $this->tagFor($page, 'id="co-maker-remove-dialog"', '>'));

        // A clean page auto-opens nothing, and Add Co-Maker still has its own explicit trigger.
        $clean = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('data-open-on-error="false"', $this->tagFor($clean, 'id="co-maker-dialog"', '>'));
        $this->assertStringContainsString('data-modal-open="co-maker-dialog" data-co-maker-add-trigger', $clean);
    }

    /** The markup from a marker up to the given terminator. */
    private function tagFor(string $html, string $marker, string $until): string
    {
        $start = strpos($html, $marker);
        $this->assertNotFalse($start, $marker.' must be rendered.');

        return substr($html, $start, strpos($html, $until, $start) - $start);
    }

    /**
     * The Edit Co-Maker modal no longer carries an address field, so editing a co-maker can only
     * change their name. The stored address still travels with the trigger's data attributes and
     * stays on the record — it is simply not editable from this form.
     */
    public function test_the_edit_modal_no_longer_offers_an_address_but_the_stored_one_survives(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Juan Dela Cruz', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'Purok 5, Bulua']);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $this->assertStringContainsString('data-co-maker-address="Purok 5, Bulua"', $content, 'The record keeps its address.');

        $form = substr($content, strpos($content, 'id="co-maker-form"'), strpos($content, '</form>', strpos($content, 'id="co-maker-form"')) - strpos($content, 'id="co-maker-form"'));
        $this->assertStringNotContainsString('name="address"', $form);

        // Editing the name the way the modal now posts it leaves the address exactly as it was.
        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'first_name' => 'Juan', 'middle_name' => '', 'last_name' => 'Dela Cruz Jr.', 'suffix' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Dela Cruz Jr.', $coMaker->fresh()->last_name);
        $this->assertSame('Purok 5, Bulua', $coMaker->fresh()->address, 'An edit that never sends an address must not blank it.');
    }

    public function test_address_only_update_is_saved(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Juan Dela Cruz', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'Old Address']);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'New Corrected Address',
        ])->assertRedirect();

        $this->assertSame('New Corrected Address', $coMaker->fresh()->address);
    }

    public function test_name_only_update_does_not_clear_the_existing_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Juan Dela Cruz', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'Keep This Address']);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz Jr.', 'address' => 'Keep This Address',
        ])->assertRedirect();

        $this->assertSame('Dela Cruz Jr.', $coMaker->fresh()->last_name);
        $this->assertSame('Keep This Address', $coMaker->fresh()->address);
    }

    /**
     * Address is no longer part of adding a co-maker: creation collects identity only and the
     * address is captured afterwards through Edit Co-Maker, which is where Residence Check reads
     * it from. An edit that does not carry the field must leave whatever is stored alone.
     */
    public function test_address_is_optional_on_create_and_is_not_cleared_by_an_edit_that_omits_it(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $created = $folder->coMakers()->sole();
        $this->assertNull($created->address, 'A new co-maker starts without an address rather than a placeholder.');

        // Adding the address later, through the same modal in edit mode.
        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $created->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'address' => 'Purok 5, Bulua, Cagayan de Oro City',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Purok 5, Bulua, Cagayan de Oro City', $created->fresh()->address);

        // A later name-only edit that never sends the field keeps that address.
        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $created->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz Jr.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Dela Cruz Jr.', $created->fresh()->last_name);
        $this->assertSame('Purok 5, Bulua, Cagayan de Oro City', $created->fresh()->address);
    }

    public function test_the_add_co_maker_form_collects_identity_only_and_middle_name_is_optional(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $content = $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()->getContent();
        $start = strpos($content, 'id="co-maker-form"');
        $form = substr($content, $start, strpos($content, '</form>', $start) - $start);

        // Address is not part of this form at all any more — neither when adding nor when editing.
        $this->assertStringNotContainsString('name="address"', $form, 'The co-maker form must not collect an address.');
        $this->assertStringNotContainsString('data-co-maker-address-field', $form);
        $this->assertStringNotContainsString('co-maker-address', $form);

        // Middle name carries the optional hint, no required marker and no required attribute.
        $middleStart = strpos($form, 'for="co-maker-middle-name"');
        $middleBlock = substr($form, $middleStart, 400);
        $this->assertStringContainsString('(optional)', $middleBlock);
        $this->assertStringNotContainsString('text-danger" aria-hidden="true">*', $middleBlock);
        $this->assertStringNotContainsString('name="middle_name" class="ui-control" required', $form);

        // First and last name keep their required markers.
        foreach (['co-maker-first-name', 'co-maker-last-name'] as $requiredField) {
            $block = substr($form, strpos($form, 'for="'.$requiredField.'"'), 400);
            $this->assertStringContainsString('text-danger" aria-hidden="true">*', $block);
        }
    }

    public function test_a_co_maker_saves_without_a_middle_name_and_keeps_a_clean_full_name(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Juan', 'middle_name' => '', 'last_name' => 'Dela Cruz',
        ])->assertSessionHasNoErrors();

        $coMaker = $folder->coMakers()->sole();
        $this->assertNull($coMaker->middle_name, 'A blank middle name is stored as null, never "N/A".');
        $this->assertSame('Juan Dela Cruz', $coMaker->full_name);

        // First and last name stay required.
        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), ['first_name' => '', 'last_name' => ''])
            ->assertSessionHasErrors(['first_name', 'last_name']);
    }

    public function test_residence_check_resolves_the_newly_saved_co_maker_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'Resolved Co-Maker Address',
        ])->assertRedirect();
        $coMaker = $folder->coMakers()->firstOrFail();
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id, 'ci_date' => now()->toDateString(),
            'location' => 'Resolved Co-Maker Address',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertRedirect();

        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();
        $this->assertSame('Resolved Co-Maker Address', $check->location);
    }

    public function test_co_maker_residence_location_is_editable_and_remains_a_saved_report_snapshot(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $coMaker = $folder->coMakers()->create(['full_name' => 'Juan Dela Cruz', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'address' => 'Master Co-Maker Address']);
        $folder->cibiReports()->create(['co_maker_id' => $coMaker->id, 'ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);
        $personParams = ['person' => 'co-maker', 'co_maker_id' => $coMaker->id];

        $this->actingAs($ci)->get(route('client-folders.residence-checks.create', [$folder] + $personParams))
            ->assertOk()
            ->assertSee('name="location"', false)
            ->assertSee('value="Master Co-Maker Address"', false);

        $this->actingAs($ci)->post(route('client-folders.residence-checks.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'location' => 'Verified Residence Location',
            'photos' => [UploadedFile::fake()->image('Front.jpg', 900, 700)->size(500)],
        ])->assertSessionHasNoErrors();

        $check = $folder->residenceChecks()->where('co_maker_id', $coMaker->id)->firstOrFail();
        $this->assertSame('Verified Residence Location', $check->location);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'co_maker_id' => $coMaker->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'address' => 'Later Master Address',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Verified Residence Location', $check->fresh()->location);
    }

    public function test_multiple_co_makers_can_each_keep_their_own_address(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'First', 'last_name' => 'Maker', 'address' => 'First Maker Address',
        ])->assertRedirect();
        $this->actingAs($ci)->post(route('client-folders.co-maker.store', $folder), [
            'first_name' => 'Second', 'last_name' => 'Maker', 'address' => 'Second Maker Address',
        ])->assertRedirect();

        $this->assertSame(2, $folder->coMakers()->count());
        $addresses = $folder->coMakers()->orderBy('id')->pluck('address')->all();
        $this->assertSame(['First Maker Address', 'Second Maker Address'], $addresses);
    }
}
