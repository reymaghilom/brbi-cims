<?php

namespace Tests\Feature\ClientFolders;

use App\Actions\ClientFolders\ManageCustomBusinessCategory;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\CustomBusinessCategory;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * CUSTOM "OTHER BUSINESS / SOURCE OF INCOME" CHECKBOX OPTIONS.
 *
 * A CI can add, rename and remove their own checkbox options alongside the default catalog. The
 * category's identity is its row id — the option key a Business Report stores is derived from it —
 * so a rename can never fork one category into two, and removal never destroys report history.
 */
class CustomBusinessCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    /** TEST 4, 5 — an authorized CI adds a category and it becomes a checkbox. */
    public function test_a_ci_can_add_a_custom_business_that_appears_as_a_checkbox(): void
    {
        [$ci, $folder] = $this->context();

        $this->actingAs($ci)->postJson(route('client-folders.custom-business-categories.store', $folder), [
            'name' => 'Vulcanizing Shop',
        ])->assertOk()->assertJsonPath('category.name', 'Vulcanizing Shop');

        $category = CustomBusinessCategory::query()->firstOrFail();
        $this->assertSame('custom_'.$category->id, $category->optionKey());
        $this->assertSame('vulcanizing shop', $category->normalized_name);
        $this->assertTrue($category->is_active);
        $this->assertSame($ci->id, $category->created_by);

        $catalog = $this->catalog($ci, $folder);
        $this->assertStringContainsString('value="'.$category->optionKey().'"', $catalog);
        $this->assertStringContainsString('Vulcanizing Shop', $catalog);
    }

    /** TEST 6 — the new option saves into a Business Report like any default one. */
    public function test_a_custom_business_can_be_selected_and_saved(): void
    {
        [$ci, $folder] = $this->context();
        $category = $this->category($ci, $folder, 'Vulcanizing Shop');

        $this->store($ci, $folder, 'Mixed Activity', ['still_lotto_outlet', $category->optionKey()])
            ->assertSessionHasNoErrors();

        $report = $folder->incomeSources()->with('businessReport')->firstOrFail()->businessReport;
        $this->assertSame(['still_lotto_outlet', $category->optionKey()], data_get($report->template_data, 'fields.income_sources'));
    }

    public function test_other_business_still_requires_at_least_one_selected_business(): void
    {
        [$ci, $folder] = $this->context();

        $this->store($ci, $folder, 'Nothing Selected', [])
            ->assertSessionHasErrors([
                'template_data.fields.income_sources' => 'Please select at least one business/income source.',
            ]);

        $this->assertSame(0, $folder->incomeSources()->count());
    }

    /** TESTS 7, 8 — renaming keeps the same row, the same key and the same saved selections. */
    public function test_renaming_preserves_the_categorys_stable_identity(): void
    {
        [$ci, $folder] = $this->context();
        $category = $this->category($ci, $folder, 'Vulcanizing Shop');
        $key = $category->optionKey();

        $this->store($ci, $folder, 'Uses The Category', [$key])->assertSessionHasNoErrors();
        $report = $folder->incomeSources()->with('businessReport')->firstOrFail()->businessReport;
        $reportId = $report->id;

        $this->actingAs($ci)->putJson(route('client-folders.custom-business-categories.update', [$folder, $category]), [
            'name' => 'Motorcycle & Vulcanizing Shop',
        ])->assertOk();

        $category->refresh();
        $this->assertSame('Motorcycle & Vulcanizing Shop', $category->name);
        $this->assertSame('motorcycle & vulcanizing shop', $category->normalized_name);
        // Same row, same derived key — no second category, and no duplicated report.
        $this->assertSame($key, $category->optionKey());
        $this->assertSame(1, CustomBusinessCategory::query()->count());
        $this->assertSame(1, $folder->incomeSources()->count());
        $this->assertSame($reportId, $report->fresh()->id);
        $this->assertSame([$key], data_get($report->fresh()->template_data, 'fields.income_sources'));

        // And the checkbox now carries the new label against the unchanged key.
        $catalog = $this->catalog($ci, $folder);
        $this->assertStringContainsString('value="'.$key.'"', $catalog);
        $this->assertStringContainsString('Motorcycle &amp; Vulcanizing Shop', $catalog);
    }

    /** TEST 9 — an unused category is genuinely deleted. */
    public function test_an_unused_custom_business_is_deleted_outright(): void
    {
        [$ci, $folder] = $this->context();
        $category = $this->category($ci, $folder, 'Never Used');

        $this->actingAs($ci)->deleteJson(route('client-folders.custom-business-categories.destroy', [$folder, $category]))
            ->assertOk()->assertJsonPath('deleted', true);

        $this->assertDatabaseCount('custom_business_categories', 0);
    }

    /** TESTS 10, 11, 12 — a used category is deactivated, never destructive. */
    public function test_a_used_custom_business_is_deactivated_and_its_history_survives(): void
    {
        [$ci, $folder] = $this->context();
        $category = $this->category($ci, $folder, 'Already Used');
        $key = $category->optionKey();

        $this->store($ci, $folder, 'Uses The Category', [$key])->assertSessionHasNoErrors();
        $source = $folder->incomeSources()->with('businessReport')->firstOrFail();
        $before = $source->businessReport->fresh()->getRawOriginal();

        $this->actingAs($ci)->deleteJson(route('client-folders.custom-business-categories.destroy', [$folder, $category]))
            ->assertOk()->assertJsonPath('deleted', false);

        // TEST 10 — the definition survives, deactivated; the report data is untouched.
        $this->assertFalse($category->fresh()->is_active);
        $this->assertSame(1, $folder->incomeSources()->count());
        $this->assertSame($before, $source->businessReport->fresh()->getRawOriginal());

        // TEST 11 — gone from a fresh report's selectable options.
        $freshCatalog = $this->catalog($ci, $folder);
        $this->assertStringNotContainsString('value="'.$key.'"', $freshCatalog);

        // TEST 12 — but the report that already uses it still shows it, still ticked.
        $editCatalog = $this->catalog($ci, $folder, $source);
        $this->assertStringContainsString('value="'.$key.'"', $editCatalog);
        $this->assertStringContainsString('Already Used', $editCatalog);
        $this->assertStringContainsString('Removed', $editCatalog);
    }

    /** TEST 13 — default options carry no management controls at all. */
    public function test_default_categories_expose_no_edit_or_remove_controls(): void
    {
        [$ci, $folder] = $this->context();
        $this->category($ci, $folder, 'Vulcanizing Shop');

        $catalog = $this->catalog($ci, $folder);

        // Management controls exist only on custom rows: one edit and one remove per custom row,
        // and never more than that however many times the catalog is rendered on the page.
        $customRows = substr_count($catalog, 'data-custom-business-category=');
        $this->assertGreaterThan(0, $customRows);
        $this->assertSame($customRows, substr_count($catalog, 'data-custom-business-edit'));
        $this->assertSame($customRows, substr_count($catalog, 'data-custom-business-remove'));

        // And a default row really is a bare checkbox — no row marker, no edit, no trash.
        $defaultRow = $this->choiceRow($catalog, 'still_lotto_outlet');
        $this->assertStringNotContainsString('data-custom-business-category', $defaultRow);
        $this->assertStringNotContainsString('data-custom-business-edit', $defaultRow);
        $this->assertStringNotContainsString('data-custom-business-remove', $defaultRow);
    }

    /**
     * UI PARITY — a custom row is the same row as a default one, plus compact icon actions.
     * The default rows are the authoritative reference and are asserted unchanged alongside it.
     */
    public function test_a_custom_row_matches_the_default_row_structure_with_compact_actions(): void
    {
        [$ci, $folder] = $this->context();
        $category = $this->category($ci, $folder, 'Vulcanizing Shop');

        $catalog = $this->catalog($ci, $folder);
        $customRow = $this->choiceRow($catalog, $category->optionKey());
        $defaultRow = $this->choiceRow($catalog, 'still_lotto_outlet');

        // Same row wrapper and same checkbox class as every default row.
        foreach ([$customRow, $defaultRow] as $row) {
            $this->assertStringContainsString('class="business-other-income-choice', $row);
            $this->assertStringContainsString('class="business-report-checkbox"', $row);
        }

        // The custom row opens the extra grid column instead of wrapping its actions onto a
        // second implicit row, which is what would make it taller than the default rows.
        $this->assertStringContainsString('has-row-actions', $customRow);
        $this->assertStringNotContainsString('has-row-actions', $defaultRow);

        // Compact catalog-scale action buttons, not the page-scale ui-action-icon-button box.
        $this->assertStringContainsString('business-other-income-choice-actions', $customRow);
        // Exactly the two management controls, each a compact catalog-scale button rather than the
        // page-scale ui-action-icon-button box (size-9 with a border) this row used to render.
        $this->assertSame(1, substr_count($customRow, 'data-custom-business-edit'));
        $this->assertSame(1, substr_count($customRow, 'data-custom-business-remove'));
        $this->assertSame(2, substr_count($customRow, '<button'));
        $this->assertStringNotContainsString('ui-action-icon-button', $customRow);

        // Both icons genuinely render as inline SVG, and both keep an accessible name.
        $this->assertSame(2, substr_count($customRow, '<svg'));
        $this->assertStringContainsString('aria-label="Edit Vulcanizing Shop"', $customRow);
        $this->assertStringContainsString('aria-label="Remove Vulcanizing Shop"', $customRow);

        // The Add action stays at catalog scale, never the page's primary button size.
        $this->assertStringContainsString('business-other-income-add', $catalog);
        $this->assertStringNotContainsString('ui-button-primary', $catalog);
    }

    /**
     * A row added through "Add Business" is cloned from the Blade-rendered template, so the markup
     * JS inserts is the same markup the server renders — including the icons, which used to be
     * copied from an existing row and so came out blank on the very first add.
     */
    public function test_the_js_row_template_is_the_same_markup_as_a_server_rendered_row(): void
    {
        [$ci, $folder] = $this->context();
        $this->category($ci, $folder, 'Vulcanizing Shop');

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertOk()->getContent();

        $start = strpos($html, '<template data-custom-business-row-template>');
        $this->assertNotFalse($start, 'The row template must be rendered for an authorized CI.');
        $template = substr($html, $start, strpos($html, '</template>', $start) - $start);

        // Same wrapper, same grid modifier, same compact actions, same two inline icons.
        $this->assertStringContainsString('class="business-other-income-choice has-row-actions', $template);
        $this->assertStringContainsString('class="business-report-checkbox"', $template);
        $this->assertStringContainsString('business-other-income-choice-actions', $template);
        $this->assertSame(2, substr_count($template, '<svg'));

        // The placeholders JS substitutes are all present.
        foreach (['__OPTION_KEY__', '__CUSTOM_ID__', '__LABEL__'] as $placeholder) {
            $this->assertStringContainsString($placeholder, $template);
        }

        // The nested <template> content is not visited when app.js assigns a form owner to the
        // initial preview controls. It therefore has to carry its own owner: otherwise a row added
        // and checked immediately is visible in the DOM but omitted from the native submission.
        $templateCheckbox = $this->inputTag($template, 'other_income___OPTION_KEY__');
        $this->assertStringContainsString('name="template_data[fields][income_sources][]"', $templateCheckbox);
        $this->assertStringContainsString('value="__OPTION_KEY__"', $templateCheckbox);
        $this->assertStringContainsString('form="business-template-form"', $templateCheckbox);

        // It lives outside the catalog, so it is never counted or submitted as a real option.
        $this->assertStringNotContainsString('__OPTION_KEY__', $this->catalog($ci, $folder));
    }

    public function test_other_business_checkboxes_keep_the_correct_form_owner_for_create_and_edit(): void
    {
        [$ci, $folder] = $this->context();
        $category = $this->category($ci, $folder, 'Vulcanizing Shop');

        $createCheckbox = $this->inputTag($this->catalog($ci, $folder), 'other_income_'.$category->optionKey());
        $this->assertStringContainsString('form="business-template-form"', $createCheckbox);

        $this->store($ci, $folder, 'Vulcanizing Shop', [$category->optionKey()])->assertSessionHasNoErrors();
        $source = $folder->incomeSources()->sole();
        $editCheckbox = $this->inputTag($this->catalog($ci, $folder, $source), 'other_income_'.$category->optionKey());
        $this->assertStringContainsString('form="business-report-form"', $editCheckbox);
        $this->assertStringContainsString('checked', $editCheckbox);
    }

    public function test_the_business_report_layout_exposes_a_non_empty_csrf_token(): void
    {
        [$ci, $folder] = $this->context();

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<meta name="csrf-token" content="[^\"]+">/', $html);
    }

    public function test_a_missing_custom_business_category_returns_a_safe_json_404(): void
    {
        [$ci, $folder] = $this->context();
        $missingId = 987654321;
        $message = 'This Custom Business Category is no longer available. It may have been deleted or changed by another user. Please refresh the page and try again.';

        $response = $this->actingAs($ci)->putJson(
            route('client-folders.custom-business-categories.update', [$folder, $missingId]),
            ['name' => 'Stale Category'],
        );

        $response->assertNotFound()->assertExactJson([
            'result' => 'not_available',
            'message' => $message,
            'status_type' => 'error',
        ]);
        $response->assertDontSee('App\\Models\\CustomBusinessCategory', false)
            ->assertDontSee('No query results for model', false)
            ->assertDontSee((string) $missingId, false);
    }

    public function test_a_missing_custom_business_category_returns_a_safe_browser_404(): void
    {
        [$ci, $folder] = $this->context();
        $missingId = 987654321;
        $message = 'This Custom Business Category is no longer available. It may have been deleted or changed by another user. Please refresh the page and try again.';

        $response = $this->actingAs($ci)->delete(
            route('client-folders.custom-business-categories.destroy', [$folder, $missingId]),
        );

        $response->assertNotFound()
            ->assertViewIs('client-folders.income-sources.custom-business-category-unavailable')
            ->assertSee($message)
            ->assertDontSee('App\\Models\\CustomBusinessCategory', false)
            ->assertDontSee('No query results for model', false)
            ->assertDontSee((string) $missingId, false);
    }

    public function test_custom_rows_and_add_action_follow_stl_in_the_first_business_column(): void
    {
        [$ci, $folder] = $this->context();
        $first = $this->category($ci, $folder, 'Computer Repair');
        $second = $this->category($ci, $folder, 'Vulcanizing Shop');
        $catalog = $this->catalog($ci, $folder);

        $stl = strpos($catalog, 'value="still_lotto_outlet"');
        $firstCustom = strpos($catalog, 'value="'.$first->optionKey().'"');
        $secondCustom = strpos($catalog, 'value="'.$second->optionKey().'"');
        $add = strpos($catalog, 'data-custom-business-add');
        $secondColumn = strpos($catalog, 'value="laundry_shop"');

        foreach ([$stl, $firstCustom, $secondCustom, $add, $secondColumn] as $position) {
            $this->assertNotFalse($position);
        }
        $this->assertTrue($stl < $firstCustom && $firstCustom < $secondCustom && $secondCustom < $add && $add < $secondColumn);
        $this->assertStringNotContainsString('<h3>Custom Business:</h3>', $catalog);
        $this->assertStringNotContainsString('data-custom-business-group', $catalog);

        // Dynamic Add targets this exact first-column list and appends after its existing rows.
        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("document.querySelector('[data-custom-business-list]')", $javascript);
        $this->assertStringContainsString('list.append(row);', $javascript);

        // The same placement exists even when the custom list is empty.
        $first->delete();
        $second->delete();
        $emptyCatalog = $this->catalog($ci, $folder);
        $this->assertLessThan(strpos($emptyCatalog, 'data-custom-business-add'), strpos($emptyCatalog, 'value="still_lotto_outlet"'));
        $this->assertLessThan(strpos($emptyCatalog, 'value="laundry_shop"'), strpos($emptyCatalog, 'data-custom-business-add'));
    }

    /**
     * The Add / Edit dialogs use the project's real bordered input class. `ui-input` is not a class
     * this application defines, so the field it used to carry rendered completely unstyled — no
     * border, no height, no focus ring. There is no placeholder either: create mode starts blank.
     */
    public function test_the_custom_business_dialogs_use_the_projects_bordered_input(): void
    {
        [$ci, $folder] = $this->context();
        $category = $this->category($ci, $folder, 'Vulcanizing Shop');

        $html = $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertOk()->getContent();

        foreach (['custom-business-add-name', 'custom-business-edit-name'] as $id) {
            $field = $this->inputTag($html, $id);
            $this->assertStringContainsString('class="ui-control"', $field, $id.' must use the real input class.');
            $this->assertStringNotContainsString('ui-input', $field);
            $this->assertStringNotContainsString('placeholder', $field);
            $this->assertStringContainsString('maxlength="120"', $field, 'The existing limit is kept.');
            $this->assertStringContainsString('autocomplete="off"', $field);
            $this->assertStringContainsString('<label class="ui-label" for="'.$id.'">Business Name</label>', $html);
        }

        $this->assertSame(2, substr_count($html, 'class="custom-business-dialog-field"'));
        foreach (['custom-business-add-dialog', 'custom-business-edit-dialog'] as $dialogId) {
            $dialogStart = strpos($html, '<dialog id="'.$dialogId.'"');
            $dialogTag = substr($html, $dialogStart, strpos($html, '>', $dialogStart) - $dialogStart);
            $this->assertStringContainsString('custom-business-dialog', $dialogTag);
            $this->assertStringContainsString('max-w-md', $dialogTag);
        }

        // Create mode has no value at all; edit mode is filled in by JS from the row it was opened
        // from, which is why the markup itself carries no value either.
        $this->assertStringNotContainsString('value=', $this->inputTag($html, 'custom-business-add-name'));
        $this->assertStringContainsString('data-custom-business-name="Vulcanizing Shop"', $html);
        $this->assertSame(1, substr_count($html, 'data-custom-business-category="'.$category->id.'"'));
    }

    /** TEST 14 — duplicate custom names are blocked, trimmed and case-insensitively. */
    public function test_duplicate_custom_names_are_blocked_case_insensitively(): void
    {
        [$ci, $folder] = $this->context();
        $this->category($ci, $folder, 'Vulcanizing Shop');

        foreach (['Vulcanizing Shop', 'vulcanizing shop', '   Vulcanizing Shop   '] as $attempt) {
            $this->actingAs($ci)->postJson(route('client-folders.custom-business-categories.store', $folder), ['name' => $attempt])
                ->assertStatus(422)->assertJsonValidationErrors('name');
        }

        $this->assertSame(1, CustomBusinessCategory::query()->count());
    }

    public function test_normalized_name_is_authoritative_and_database_unique(): void
    {
        [$ci, $folder] = $this->context();
        $category = $this->category($ci, $folder, 'Employment Verification');

        $this->assertTrue(Schema::hasColumn('custom_business_categories', 'normalized_name'));
        $this->assertSame('employment verification', $category->normalized_name);
        $this->assertSame('employment verification', CustomBusinessCategory::normalizeName(' employment verification '));
        $this->assertSame('employment verification', CustomBusinessCategory::normalizeName('EMPLOYMENT VERIFICATION'));

        $this->expectException(QueryException::class);
        DB::table('custom_business_categories')->insert([
            'name' => ' employment verification ',
            'normalized_name' => 'employment verification',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_late_unique_create_collision_is_returned_as_professional_validation(): void
    {
        [$ci, $folder] = $this->context();
        $this->category($ci, $folder, 'Employment Verification');

        try {
            $this->category($ci, $folder, ' EMPLOYMENT VERIFICATION ');
            $this->fail('The normalized duplicate was not rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('That business is already in the list.', $exception->errors()['name'][0]);
        }

        $this->assertSame(1, CustomBusinessCategory::query()->count());
    }

    public function test_rename_to_a_normalized_duplicate_is_blocked_cleanly(): void
    {
        [$ci, $folder] = $this->context();
        $first = $this->category($ci, $folder, 'Employment Verification');
        $second = $this->category($ci, $folder, 'Motorcycle Repair');
        $secondKey = $second->optionKey();

        $this->actingAs($ci)->putJson(route('client-folders.custom-business-categories.update', [$folder, $second]), [
            'name' => ' employment verification ',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('name')
            ->assertJsonPath('errors.name.0', 'That business is already in the list.');

        $this->assertSame('Motorcycle Repair', $second->fresh()->name);
        $this->assertSame('motorcycle repair', $second->fresh()->normalized_name);
        $this->assertSame($secondKey, $second->fresh()->optionKey());
        $this->assertSame('Employment Verification', $first->fresh()->name);
    }

    public function test_deleted_category_cannot_be_saved_as_a_dangling_custom_key(): void
    {
        [$ci, $folder] = $this->context();
        $category = $this->category($ci, $folder, 'Temporary Business');
        $key = $category->optionKey();

        $this->actingAs($ci)
            ->deleteJson(route('client-folders.custom-business-categories.destroy', [$folder, $category]))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->store($ci, $folder, 'Cannot Save Deleted', [$key])
            ->assertSessionHasErrors('template_data.fields.income_sources');

        $this->assertSame(0, $folder->incomeSources()->count());
    }

    public function test_inactive_category_is_rejected_for_new_reports_but_retained_by_its_existing_report(): void
    {
        [$ci, $folder] = $this->context();
        $category = $this->category($ci, $folder, 'Historical Business');
        $key = $category->optionKey();

        $this->store($ci, $folder, 'Original Report', [$key])->assertSessionHasNoErrors();
        $source = $folder->incomeSources()->with('businessReport')->sole();
        $this->actingAs($ci)
            ->deleteJson(route('client-folders.custom-business-categories.destroy', [$folder, $category]))
            ->assertOk()
            ->assertJsonPath('deleted', false);

        $this->actingAs($ci)->put(route('client-folders.income-sources.business.update', [$folder, $source]), [
            'co_maker_id' => null,
            'expected_revision' => $source->revision,
            'source_name' => $source->source_name,
            'business_name' => $source->business_name,
            'report_category' => $source->businessReport->report_category,
            'start_date' => $source->businessReport->start_date->format('Y-m-d'),
            'report_remarks' => 'Updated while retaining the historical category.',
            'template_data' => ['fields' => ['income_sources' => [$key]]],
            'properties' => [],
            'tenants' => [],
            'intent' => 'stay',
        ])->assertSessionHasNoErrors();

        $this->assertSame([$key], data_get($source->fresh()->businessReport->template_data, 'fields.income_sources'));
        $this->assertSame('Historical Business', CustomBusinessCategory::labelsForKeys([$key])[$key]);

        $otherFolder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
        $this->store($ci, $otherFolder, 'New Report', [$key])
            ->assertSessionHasErrors('template_data.fields.income_sources');
        $this->assertSame(0, $otherFolder->incomeSources()->count());
    }

    /** TEST 15 — a custom name may not duplicate a DEFAULT checkbox label. */
    public function test_a_custom_name_cannot_duplicate_a_default_checkbox(): void
    {
        [$ci, $folder] = $this->context();

        $this->actingAs($ci)->postJson(route('client-folders.custom-business-categories.store', $folder), ['name' => 'laundry shop'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('custom_business_categories', 0);

        // A dedicated Business Template's name is NOT a checkbox label, so it stays allowed —
        // templates and checkbox options are explicitly permitted to overlap.
        $this->actingAs($ci)->postJson(route('client-folders.custom-business-categories.store', $folder), ['name' => 'Taxi Operator'])
            ->assertOk();
    }

    /** TESTS 16, 17, 18 — custom keys join the existing combination-uniqueness rule unchanged. */
    public function test_custom_categories_participate_in_combination_uniqueness(): void
    {
        [$ci, $folder] = $this->context();
        $custom = $this->category($ci, $folder, 'Vulcanizing Shop');
        $key = $custom->optionKey();

        $this->store($ci, $folder, 'A plus custom', ['still_lotto_outlet', $key])->assertSessionHasNoErrors();

        // Exact same set, reversed — still a duplicate.
        $this->store($ci, $folder, 'Reversed', [$key, 'still_lotto_outlet'])->assertSessionHasErrors();

        // Expanded and subset combinations stay genuinely different.
        $this->store($ci, $folder, 'Expanded', ['still_lotto_outlet', $key, 'laundry_shop'])->assertSessionHasNoErrors();
        $this->store($ci, $folder, 'Subset', [$key])->assertSessionHasNoErrors();

        $this->assertSame(3, $folder->incomeSources()->count());
    }

    /** TESTS 19, 20 — the same custom combination is legitimate for a different exact person. */
    public function test_applicant_and_co_maker_remain_isolated(): void
    {
        [$ci, $folder] = $this->context();
        $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'Exact Co-Maker']);
        $key = $this->category($ci, $folder, 'Vulcanizing Shop')->optionKey();

        $this->store($ci, $folder, 'Applicant Activity', [$key])->assertSessionHasNoErrors();
        $this->store($ci, $folder, 'Co-Maker Activity', [$key], $coMaker)->assertSessionHasNoErrors();

        $this->assertSame(1, $folder->incomeSources()->whereNull('co_maker_id')->count());
        $this->assertSame(1, $folder->incomeSources()->where('co_maker_id', $coMaker->id)->count());

        // Still blocked within that one person.
        $this->store($ci, $folder, 'Co-Maker Duplicate', [$key], $coMaker)->assertSessionHasErrors();
        $this->assertSame(1, $folder->incomeSources()->where('co_maker_id', $coMaker->id)->count());
    }

    /** TEST 21 — submitting the edit form unchanged writes nothing and fabricates no history. */
    public function test_a_no_change_rename_writes_nothing(): void
    {
        [$ci, $folder] = $this->context();
        $category = $this->category($ci, $folder, 'Vulcanizing Shop');
        $before = $category->fresh()->getRawOriginal();
        AuditLog::query()->delete();

        $this->actingAs($ci)->putJson(route('client-folders.custom-business-categories.update', [$folder, $category]), [
            'name' => 'Vulcanizing Shop',
        ])->assertOk()->assertJsonPath('no_change', true)
            ->assertJsonPath('message', 'No changes detected. Nothing needs to be updated.');

        $this->assertSame($before, $category->fresh()->getRawOriginal());
        $this->assertSame(0, AuditLog::query()->count());
    }

    /** Managing a category records history through the existing audit trail, not a new one. */
    public function test_management_actions_are_recorded_in_the_existing_audit_log(): void
    {
        [$ci, $folder] = $this->context();
        $category = $this->category($ci, $folder, 'Vulcanizing Shop');

        $this->actingAs($ci)->putJson(route('client-folders.custom-business-categories.update', [$folder, $category]), ['name' => 'Renamed Shop'])->assertOk();
        $this->actingAs($ci)->deleteJson(route('client-folders.custom-business-categories.destroy', [$folder, $category]))->assertOk();

        $this->assertSame(
            ['custom_business_category.created', 'custom_business_category.updated', 'custom_business_category.deleted'],
            AuditLog::query()->where('module', 'income_sources')->orderBy('id')->pluck('action')->all(),
        );
    }

    /** TESTS 1, 2, 3 — the default catalog and the removed heading are unaffected by all of this. */
    public function test_the_default_catalog_and_removed_heading_are_unaffected(): void
    {
        [$ci, $folder] = $this->context();
        $this->category($ci, $folder, 'Vulcanizing Shop');

        $page = $this->actingAs($ci)->get(route('client-folders.income-sources.index', $folder))->assertOk();
        $page->assertDontSee('SELECT ALL APPLICABLE INCOME SOURCES');

        $catalog = $this->catalog($ci, $folder);
        foreach ($this->defaultCatalogKeys() as $key) {
            $this->assertStringContainsString('value="'.$key.'"', $catalog, $key.' is a default option.');
        }
        // Template-backed defaults still coexist with their dedicated templates.
        foreach (['meatshop', 'contractor', 'corn_production', 'trucking_services'] as $alsoATemplate) {
            $this->assertStringContainsString('value="'.$alsoATemplate.'"', $catalog);
        }
        $available = IncomeSourceTemplate::query()->activeBusiness()->pluck('template_type')->all();
        $this->assertContains('meatshop_store', $available);
        $this->assertContains('trucking_services', $available);
    }

    /** @return array{0: User, 1: ClientFolder} */
    private function context(): array
    {
        $ci = User::factory()->create();

        return [$ci, ClientFolder::factory()->create(['assigned_ci_id' => $ci->id])];
    }

    private function category(User $ci, ClientFolder $folder, string $name): CustomBusinessCategory
    {
        return $this->app->make(ManageCustomBusinessCategory::class)->create($ci, $folder, $name);
    }

    private function defaultCatalogKeys(): array
    {
        return collect(config('business-report-templates.other_business_source_of_income.schema.income_source_groups'))
            ->flatten(1)
            ->pluck('key')
            ->all();
    }

    private function store(User $ci, ClientFolder $folder, string $name, array $keys, ?CoMaker $person = null): TestResponse
    {
        $route = $person === null
            ? route('client-folders.income-sources.store', $folder)
            : route('client-folders.income-sources.store', [$folder, 'person' => 'co-maker', 'co_maker_id' => $person->id]);

        return $this->actingAs($ci)->post($route, array_filter([
            'income_source_template_id' => IncomeSourceTemplate::query()
                ->where('template_type', 'other_business_source_of_income')->value('id'),
            'co_maker_id' => $person?->id,
            'source_name' => $name,
            'start_date' => '2026-01-01',
            'report_remarks' => 'Client-entered fallback details.',
            'template_data' => ['fields' => ['income_sources' => $keys]],
        ]));
    }

    /** The single <input ...> tag carrying this id. */
    private function inputTag(string $html, string $id): string
    {
        $at = strpos($html, 'id="'.$id.'"');
        $this->assertNotFalse($at, $id.' must be rendered.');

        $start = strrpos(substr($html, 0, $at), '<input');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '>', $at) - $start + 1);
    }

    /** One choice row, from its wrapper to the end of that row's markup. */
    private function choiceRow(string $catalog, string $optionKey): string
    {
        $at = strpos($catalog, 'value="'.$optionKey.'"');
        $this->assertNotFalse($at, $optionKey.' must be rendered.');

        // The row wrapper specifically — 'business-other-income-choice' on its own would also match
        // the '-actions' / '-action' class names inside a preceding custom row.
        $marker = '<div class="business-other-income-choice';
        $start = strrpos(substr($catalog, 0, $at), $marker);
        $this->assertNotFalse($start);

        // Stop at the next row, or — for the last row in a group — at the Add action beneath it,
        // so a trailing control is never counted as part of the row.
        $stops = array_filter([
            strpos($catalog, $marker, $at),
            strpos($catalog, '<button type="button" class="business-other-income-add', $at),
        ], fn ($position): bool => $position !== false);

        return $stops === [] ? substr($catalog, $start) : substr($catalog, $start, min($stops) - $start);
    }

    /** The rendered checkbox catalog — for a new report, or for one exact existing income source. */
    private function catalog(User $ci, ClientFolder $folder, $source = null): string
    {
        $url = $source === null
            ? route('client-folders.income-sources.index', $folder)
            : route('client-folders.income-sources.edit', [$folder, $source]);

        $html = $this->actingAs($ci)->get($url)->assertOk()->getContent();

        $start = strpos($html, 'business-other-income-catalog');
        $this->assertNotFalse($start, 'The Other Business catalog must be on the page.');
        $end = strpos($html, 'template_data[fields][business_selected]', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
