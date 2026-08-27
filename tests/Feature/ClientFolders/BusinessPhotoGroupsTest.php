<?php

namespace Tests\Feature\ClientFolders;

use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Business Check "Photo Groups" — caption + multiple photos per group, independent per Business Check. */
class BusinessPhotoGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
    }

    public function test_a_new_business_check_without_any_photo_is_rejected(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
        ])->assertSessionHasErrors('photo_groups');

        $this->assertSame('At least one business photo is required.', session('errors')->first('photo_groups'));
        $this->assertDatabaseCount('business_checks', 0);
    }

    public function test_a_new_business_check_with_one_photo_in_the_default_area_succeeds(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('business_checks', 1);
    }

    public function test_editing_with_an_existing_remaining_photo_succeeds_without_a_new_upload(): void
    {
        [$ci, $folder, , $check, $group] = $this->createCheckWithOneGroup();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $this->basePayload($check) + [
            'remarks' => 'Updated remarks, no new photo.',
            'photo_groups' => [['id' => $group->id, 'caption' => $group->caption]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Updated remarks, no new photo.', $check->fresh()->remarks);
    }

    public function test_removing_the_last_photo_without_a_replacement_is_rejected(): void
    {
        [$ci, $folder, , $check, $group] = $this->createCheckWithOneGroup();
        $onlyPhoto = $group->photos()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $this->basePayload($check) + [
            'photo_groups' => [['id' => $group->id, 'caption' => $group->caption, 'removed_photo_ids' => [$onlyPhoto->id]]],
        ])->assertSessionHasErrors('photo_groups');

        $this->assertSame('At least one business photo is required.', session('errors')->first('photo_groups'));
        $this->assertDatabaseHas('business_check_photos', ['id' => $onlyPhoto->id]);
    }

    public function test_saving_a_new_group_with_a_caption_and_photos_persists_both(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [
                ['caption' => 'Newleaf monthly rent is 5,000 per month.', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $check = $folder->businessChecks()->firstOrFail();
        $this->assertSame(1, $check->photoGroups()->count());
        $group = $check->photoGroups()->firstOrFail();
        $this->assertSame('Newleaf monthly rent is 5,000 per month.', $group->caption);
        $this->assertSame(1, $group->photos()->count());
    }

    public function test_a_group_can_be_saved_with_photos_and_no_caption(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [
                ['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]],
            ],
        ])->assertSessionHasNoErrors();

        $group = $folder->businessChecks()->firstOrFail()->photoGroups()->firstOrFail();
        $this->assertNull($group->caption);
        $this->assertSame(1, $group->photos()->count());
    }

    public function test_multiple_groups_save_in_submitted_order(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [
                ['caption' => 'First group', 'photos' => [UploadedFile::fake()->image('a.jpg')->size(500)]],
                ['caption' => 'Second group', 'photos' => [UploadedFile::fake()->image('b.jpg')->size(500)]],
                ['caption' => 'Third group', 'photos' => [UploadedFile::fake()->image('c.jpg')->size(500)]],
            ],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->firstOrFail();
        $this->assertSame(['First group', 'Second group', 'Third group'], $check->photoGroups()->pluck('caption')->all());
        $this->assertSame([0, 1, 2], $check->photoGroups()->pluck('sort_order')->all());
    }

    public function test_a_group_can_hold_multiple_photos(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [
                ['caption' => 'Storefront', 'photos' => [
                    UploadedFile::fake()->image('a.jpg')->size(500),
                    UploadedFile::fake()->image('b.jpg')->size(500),
                    UploadedFile::fake()->image('c.jpg')->size(500),
                ]],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3, $folder->businessChecks()->firstOrFail()->photoGroups()->firstOrFail()->photos()->count());
    }

    public function test_a_fully_empty_new_group_is_never_persisted(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [
                ['caption' => '', 'photos' => []],
                ['caption' => 'Second group', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $folder->businessChecks()->firstOrFail()->photoGroups()->count());
    }

    public function test_adding_a_photo_to_an_existing_group_on_update(): void
    {
        [$ci, $folder, , $check, $group] = $this->createCheckWithOneGroup();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $this->basePayload($check) + [
            'photo_groups' => [
                ['id' => $group->id, 'caption' => $group->caption, 'photos' => [UploadedFile::fake()->image('new.jpg')->size(500)]],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $group->fresh()->photos()->count());
    }

    public function test_removing_one_photo_from_a_group_leaves_the_rest(): void
    {
        [$ci, $folder, , $check, $group] = $this->createCheckWithOneGroup();
        $secondPhoto = $check->photos()->create($this->fakePhotoRow($ci->id) + ['business_check_photo_group_id' => $group->id]);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $this->basePayload($check) + [
            'photo_groups' => [
                ['id' => $group->id, 'caption' => $group->caption, 'removed_photo_ids' => [$secondPhoto->id]],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $group->fresh()->photos()->count());
    }

    public function test_removing_an_unsaved_group_never_creates_it(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [
                ['caption' => 'Kept', 'photos' => [UploadedFile::fake()->image('a.jpg')->size(500)]],
                ['_delete' => '1', 'caption' => 'Discarded before ever saved'],
            ],
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->firstOrFail();
        $this->assertSame(1, $check->photoGroups()->count());
        $this->assertSame('Kept', $check->photoGroups()->firstOrFail()->caption);
    }

    public function test_deleting_a_saved_group_removes_it_and_its_photos(): void
    {
        // A second group with its own photo keeps the check satisfying the required-photo rule
        // once $group is deleted below — otherwise the delete itself would be the one thing that
        // drops the check to zero photos, which the request now blocks.
        [$ci, $folder, , $check, $group] = $this->createCheckWithOneGroup();
        $secondGroup = $check->photoGroups()->create(['caption' => 'Second group', 'sort_order' => 1]);
        $check->photos()->create($this->fakePhotoRow($ci->id) + ['business_check_photo_group_id' => $secondGroup->id]);
        $photoId = $group->photos()->firstOrFail()->id;

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $this->basePayload($check) + [
            'photo_groups' => [
                ['id' => $group->id, '_delete' => '1'],
                ['id' => $secondGroup->id, 'caption' => $secondGroup->caption],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('business_check_photo_groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('business_check_photos', ['id' => $photoId]);
    }

    public function test_a_group_that_ends_up_with_no_photos_and_no_caption_is_cleaned_up(): void
    {
        // Same reasoning as test_deleting_a_saved_group_removes_it_and_its_photos above — a second
        // group's photo keeps the check above the required minimum once $group's own only photo is
        // removed, isolating this test to its actual concern (empty-group cleanup).
        [$ci, $folder, , $check, $group] = $this->createCheckWithOneGroup();
        $group->update(['caption' => null]);
        $onlyPhoto = $group->photos()->firstOrFail();
        $secondGroup = $check->photoGroups()->create(['caption' => 'Second group', 'sort_order' => 1]);
        $check->photos()->create($this->fakePhotoRow($ci->id) + ['business_check_photo_group_id' => $secondGroup->id]);

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $this->basePayload($check) + [
            'photo_groups' => [
                ['id' => $group->id, 'caption' => '', 'removed_photo_ids' => [$onlyPhoto->id]],
                ['id' => $secondGroup->id, 'caption' => $secondGroup->caption],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('business_check_photo_groups', ['id' => $group->id]);
    }

    public function test_historical_ungrouped_photos_display_under_a_legacy_group_and_convert_on_next_save(): void
    {
        [$ci, $folder, , $check] = $this->createCheckWithLegacyPhoto();

        $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))
            ->assertOk()
            ->assertSee('data-photo-group-row', false);

        $legacyPhotoId = $check->photos()->firstOrFail()->id;
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $this->basePayload($check) + [
            'photo_groups' => [['id' => '', 'caption' => 'Now captioned', 'legacy_photo_ids' => [$legacyPhotoId]]],
        ])->assertSessionHasNoErrors();

        $check->refresh();
        $this->assertSame(1, $check->photoGroups()->count());
        $this->assertSame($legacyPhotoId, $check->photoGroups()->firstOrFail()->photos()->firstOrFail()->id);
    }

    public function test_add_business_check_form_shows_the_first_group_as_a_plain_business_photos_area(): void
    {
        [$ci, $folder] = $this->setUpBusiness();

        $response = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk();

        // The "+ Add Photo Group" <template> (cloned client-side for a genuinely new group) always
        // contains its own Remove Group button in the raw page source — that's expected and not
        // what this asserts against; what matters is the one *rendered* first-group card has
        // neither a "Photo Group 1" heading nor its own Remove Group button.
        $response->assertSee('data-photo-group-first', false);
        $response->assertDontSee('Photo Group 1', false);
    }

    public function test_legacy_photos_render_in_the_first_group_without_a_photo_group_heading(): void
    {
        [$ci, $folder, , $check] = $this->createCheckWithLegacyPhoto();

        $response = $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))->assertOk();

        $response->assertSee('data-photo-group-first', false);
        $response->assertDontSee('Photo Group 1', false);
    }

    public function test_a_second_group_shows_a_numbered_heading_and_a_remove_button(): void
    {
        [$ci, $folder, , $check, $group] = $this->createCheckWithOneGroup();
        $check->photoGroups()->create(['caption' => 'Second group', 'sort_order' => 1]);

        $response = $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))->assertOk();

        $response->assertSee('Photo Group <span data-photo-group-number>2</span>', false);
        $response->assertSee('data-photo-group-remove', false);
        // The first group still renders without the heading/remove button even though a second
        // group now exists alongside it.
        $response->assertSee('data-photo-group-first', false);
        $response->assertDontSee('Photo Group 1', false);
    }

    public function test_default_business_photos_area_has_no_caption_field(): void
    {
        [$ci, $folder] = $this->setUpBusiness();

        $content = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();
        $firstGroupMarkup = $this->firstGroupMarkup($content);

        $this->assertStringNotContainsString('Caption / Remarks', $firstGroupMarkup);
        $this->assertStringContainsString('data-photo-group-first', $firstGroupMarkup);
        $this->assertStringContainsString('Add Photos', $firstGroupMarkup);
    }

    public function test_a_second_groups_caption_field_is_still_present(): void
    {
        [$ci, $folder, , $check] = $this->createCheckWithOneGroup();
        $check->photoGroups()->create(['caption' => 'Second group', 'sort_order' => 1]);

        $content = $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))->assertOk()->getContent();
        $firstGroupMarkup = $this->firstGroupMarkup($content);
        $firstGroupStart = strpos($content, 'data-photo-group-first');
        $afterFirstGroup = substr($content, $firstGroupStart + strlen($firstGroupMarkup));

        $this->assertStringNotContainsString('Caption / Remarks', $firstGroupMarkup);
        $this->assertStringContainsString('Caption / Remarks', $afterFirstGroup);
    }

    public function test_helper_text_appears_immediately_above_the_add_photo_group_button(): void
    {
        [$ci, $folder] = $this->setUpBusiness();

        $content = $this->actingAs($ci)->get(route('client-folders.business-checks.create', $folder))->assertOk()->getContent();

        $helperText = 'Use "+ Add Photo Group" when you need a separate set of photos with its own caption/remarks.';
        $helperPos = strpos($content, $helperText);
        $buttonPos = strpos($content, 'data-photo-group-add');

        $this->assertNotFalse($helperPos, 'Helper text not found.');
        $this->assertNotFalse($buttonPos, 'Add Photo Group button not found.');
        $this->assertLessThan($buttonPos, $helperPos, 'Helper text must appear before the Add Photo Group button.');
    }

    public function test_first_groups_pre_existing_caption_survives_a_save_that_never_shows_the_field(): void
    {
        [$ci, $folder, , $check, $group] = $this->createCheckWithOneGroup();
        $group->update(['caption' => 'Historical caption from before this change']);

        $content = $this->actingAs($ci)->get(route('client-folders.business-checks.edit', [$folder, $check]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="photo_groups\[0\]\[caption\]" value="Historical caption from before this change"/', $content);
        $this->assertStringNotContainsString('Caption / Remarks', $this->firstGroupMarkup($content));

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), $this->basePayload($check) + [
            'photo_groups' => [['id' => $group->id, 'caption' => 'Historical caption from before this change']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Historical caption from before this change', $group->fresh()->caption);
    }

    /**
     * Slice of the response body covering only the first (default) group's own rendered card —
     * bounded by its unique `data-photo-group-first` marker and whichever comes next: a second
     * group's row (if any) or the "+ Add Photo Group" button. Never spills into the inert
     * <template> (which always includes its own Caption/Remarks markup for a genuinely new group)
     * or, when more than one group is saved, into a later group's own card.
     */
    private function firstGroupMarkup(string $content): string
    {
        $start = strpos($content, 'data-photo-group-first');
        $this->assertNotFalse($start, 'data-photo-group-first marker not found.');
        $nextRow = strpos($content, 'data-photo-group-row', $start + 1);
        $addButton = strpos($content, 'data-photo-group-add');
        $this->assertNotFalse($addButton, 'Add Photo Group button not found.');
        $end = $nextRow !== false ? min($nextRow, $addButton) : $addButton;

        return substr($content, $start, $end - $start);
    }

    public function test_saving_the_first_group_with_a_blank_caption_stores_no_caption(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [
                ['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]],
            ],
        ])->assertSessionHasNoErrors();

        $group = $folder->businessChecks()->firstOrFail()->photoGroups()->firstOrFail();
        $this->assertNull($group->caption);
        $this->assertSame(1, $group->photos()->count());
    }

    public function test_report_output_does_not_render_photo_group_numbers(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [
                ['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg')->size(500)]],
                ['caption' => 'Second group caption', 'photos' => [UploadedFile::fake()->image('b.jpg')->size(500)]],
            ],
        ])->assertSessionHasNoErrors();

        $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))
            ->assertOk()
            ->assertSee('Second group caption')
            ->assertDontSee('Photo Group 1', false)
            ->assertDontSee('Photo Group 2', false);
    }

    public function test_two_businesses_photo_groups_never_leak_into_each_other(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $sourceA = $this->businessSource($folder, 'Business A', 'Address A');
        $sourceB = $this->businessSource($folder, 'Business B', 'Address B');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $sourceA->id, 'ci_date' => now()->toDateString(), 'location' => 'Address A',
            'photo_groups' => [['caption' => 'A caption', 'photos' => [UploadedFile::fake()->image('a.jpg')->size(500)]]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $sourceB->id, 'ci_date' => now()->toDateString(), 'location' => 'Address B',
            'photo_groups' => [['caption' => 'B caption', 'photos' => [UploadedFile::fake()->image('b.jpg')->size(500)]]],
        ])->assertSessionHasNoErrors();

        $checkA = $folder->businessChecks()->where('income_source_id', $sourceA->id)->firstOrFail();
        $checkB = $folder->businessChecks()->where('income_source_id', $sourceB->id)->firstOrFail();

        $this->assertSame(['A caption'], $checkA->photoGroups()->pluck('caption')->all());
        $this->assertSame(['B caption'], $checkB->photoGroups()->pluck('caption')->all());
    }

    public function test_adding_a_photo_group_counts_as_a_genuine_change(): void
    {
        [$ci, $folder, , $check] = $this->createCheck();

        $this->actingAs($ci)
            ->post(route('client-folders.business-checks.store', $folder), $this->basePayload($check) + [
                'photo_groups' => [['caption' => 'New group', 'photos' => [UploadedFile::fake()->image('a.jpg')->size(500)]]],
            ])
            ->assertSessionHas('statusType', 'success');
    }

    public function test_saving_with_no_group_changes_still_reports_nothing_changed(): void
    {
        [$ci, $folder, , $check, $group] = $this->createCheckWithOneGroup();

        $this->actingAs($ci)
            ->post(route('client-folders.business-checks.store', $folder), $this->basePayload($check) + [
                'photo_groups' => [['id' => $group->id, 'caption' => $group->caption]],
            ])
            ->assertSessionHas('statusType', 'info')
            ->assertSessionHas('status', 'Nothing changed. No updates were saved to the database.');
    }

    public function test_competitor_photos_and_map_screenshot_are_unaffected_by_photo_groups(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => 'Group', 'photos' => [UploadedFile::fake()->image('a.jpg')->size(500)]]],
            'competitor_photos' => [UploadedFile::fake()->image('rival.jpg')->size(500)],
            'map_screenshot' => UploadedFile::fake()->image('map.png')->size(500),
        ])->assertSessionHasNoErrors();

        $check = $folder->businessChecks()->firstOrFail();
        $this->assertSame(1, $check->competitorPhotos()->count());
        $this->assertTrue($check->hasMapScreenshot());
        $this->assertSame(1, $check->photoGroups()->count());
    }

    public function test_official_preview_shows_each_photo_groups_caption(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [
                ['caption' => 'Newleaf monthly rent is 5,000 per month.', 'photos' => [UploadedFile::fake()->image('a.jpg')->size(500)]],
                ['caption' => 'Bombay Minimart monthly rent is 4k per month.', 'photos' => [UploadedFile::fake()->image('b.jpg')->size(500)]],
            ],
        ])->assertSessionHasNoErrors();

        $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))
            ->assertOk()
            ->assertSee('Newleaf monthly rent is 5,000 per month.')
            ->assertSee('Bombay Minimart monthly rent is 4k per month.');
    }

    public function test_official_preview_shows_the_competitor_caption_separately_from_photo_groups(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => 'Storefront', 'photos' => [UploadedFile::fake()->image('a.jpg')->size(500)]]],
            'competitor_photos' => [UploadedFile::fake()->image('rival.jpg')->size(500)],
            'competitor_remarks' => 'Competitors - about 20 meters away from client business',
        ])->assertSessionHasNoErrors();

        $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))
            ->assertOk()
            ->assertSee('Storefront')
            ->assertSee('Competitors - about 20 meters away from client business');
    }

    public function test_batch_pdf_and_docx_export_succeed_with_multiple_photo_groups_and_competitors(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [
                ['caption' => 'Group one', 'photos' => [UploadedFile::fake()->image('a.jpg')->size(500)]],
                ['caption' => '', 'photos' => [UploadedFile::fake()->image('b.jpg')->size(500), UploadedFile::fake()->image('c.jpg')->size(500)]],
            ],
            'competitor_photos' => [UploadedFile::fake()->image('rival.jpg')->size(500)],
            'competitor_remarks' => 'Competitors nearby',
            'map_screenshot' => UploadedFile::fake()->image('map.png')->size(500),
        ])->assertSessionHasNoErrors();
        $check = $folder->businessChecks()->firstOrFail();

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-pdf', $folder), [
            'business_check_ids' => [$check->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'business_check_ids' => [$check->id],
        ])->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function test_a_mismatched_file_extension_is_rejected_inside_a_group(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $fakeImage = UploadedFile::fake()->create('not-really-an-image.jpg', 10, 'text/plain');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => 'Group', 'photos' => [$fakeImage]]],
        ])->assertSessionHasErrors();

        $this->assertDatabaseCount('business_checks', 0);
    }

    public function test_default_business_photos_area_renders_no_caption_or_filenames(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('storefront.jpg', 900, 700)->size(500)]]],
        ])->assertSessionHasNoErrors();

        $preview = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk();
        $preview->assertDontSee('storefront.jpg')
            ->assertDontSee('Photo Group 1', false)
            ->assertDontSee('Caption / Remarks', false);
    }

    public function test_report_output_never_shows_more_than_two_business_photos_on_one_page(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [[
                'caption' => '',
                'photos' => [
                    UploadedFile::fake()->image('a.jpg')->size(500),
                    UploadedFile::fake()->image('b.jpg')->size(500),
                    UploadedFile::fake()->image('c.jpg')->size(500),
                ],
            ]],
        ])->assertSessionHasNoErrors();

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk()->getContent();

        foreach (explode('photo-report-page', $content) as $pageChunk) {
            $this->assertLessThanOrEqual(2, substr_count($pageChunk, 'class="photo"'));
        }
    }

    public function test_a_photo_groups_caption_stays_above_its_own_photos_and_map_is_the_final_section(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [
                ['caption' => '', 'photos' => [UploadedFile::fake()->image('default.jpg')->size(500)]],
                ['caption' => 'Rented space at the back.', 'photos' => [UploadedFile::fake()->image('rear.jpg')->size(500)]],
            ],
            'competitor_photos' => [UploadedFile::fake()->image('rival.jpg')->size(500)],
            'competitor_remarks' => 'Competitor stall next door',
            'map_screenshot' => UploadedFile::fake()->image('map.png')->size(500),
        ])->assertSessionHasNoErrors();

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk()->getContent();

        $captionPos = strpos($content, 'Rented space at the back.');
        $competitorRemarksPos = strpos($content, 'Competitor stall next door');
        $mapPos = strpos($content, 'Google Map');
        $this->assertNotFalse($captionPos);
        $this->assertNotFalse($competitorRemarksPos);
        $this->assertNotFalse($mapPos);
        $this->assertLessThan($competitorRemarksPos, $captionPos, 'A group caption must render above its own photos, before Competitors.');
        $this->assertLessThan($mapPos, $competitorRemarksPos, 'Competitors must render before Google Map, which is the report\'s final section.');
    }

    /**
     * Compact header layout matching business1.docx/business1.pdf (the visual reference for this
     * report): no large "Business Check" title, no "Business DOCUMENTATION" subtitle — Applicant
     * Name and Date on the CI's own row, Location and CI on the next, Subject and Remarks below
     * that, using the check's own real saved data throughout (never the reference's own sample
     * values).
     */
    public function test_the_web_preview_uses_the_compact_reference_header_layout(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'New Leaf Bread', 'Ipil St., Zone 6 Bugo CDOC');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => '2026-07-03', 'location' => 'Ipil St., Zone 6 Bugo CDOC',
            'remarks' => 'Client business is doing good.',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]]],
        ])->assertSessionHasNoErrors();

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk()->getContent();

        $this->assertStringNotContainsString('<h1 class="report-title">Business Check</h1>', $content);
        $this->assertStringNotContainsString('Business DOCUMENTATION', $content);
        $this->assertStringContainsString('<strong>Applicant Name:</strong>', $content);
        $this->assertStringContainsString('<strong>Location:</strong> Ipil St., Zone 6 Bugo CDOC', $content);
        $this->assertStringContainsString('<strong>Date:</strong> July 3, 2026', $content);
        $this->assertStringContainsString('<strong>Subject:</strong> Business Check (New Leaf Bread)', $content);
        $this->assertStringContainsString('<strong>Remarks:</strong> Client business is doing good.', $content);
    }

    /** Business Photos get their own taller frame than Residence Check's (photo-frame-tall) so they actually fill the extra page space the compact header frees up — matching business1.docx/business1.pdf's large photos. */
    public function test_business_photos_render_with_the_taller_borderless_frame(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]]],
        ])->assertSessionHasNoErrors();

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('class="photo-frame photo-frame-plain photo-frame-tall"', $content);
    }

    /** The DOCX export must show the exact same compact header content as the Web preview — no separate title page, no old 4-row details table. */
    public function test_the_docx_export_uses_the_same_compact_header_layout_as_the_web_preview(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'New Leaf Bread', 'Ipil St., Zone 6 Bugo CDOC');

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => '2026-07-03', 'location' => 'Ipil St., Zone 6 Bugo CDOC',
            'remarks' => 'Client business is doing good.',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg', 900, 700)->size(500)]]],
        ])->assertSessionHasNoErrors();
        $check = $folder->businessChecks()->firstOrFail();

        $docxResponse = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'business_check_ids' => [$check->id],
        ])->assertOk();

        $docxPath = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        file_put_contents($docxPath, $docxResponse->streamedContent());
        $zip = new \ZipArchive();
        $zip->open($docxPath);
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($docxPath);

        preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $documentXml, $matches);
        $text = implode('', $matches[1]);

        $this->assertStringNotContainsString('Business DOCUMENTATION', $text);
        $this->assertStringContainsString('Applicant Name:', $text);
        $this->assertStringContainsString('New Leaf Bread', $text);
        $this->assertStringContainsString('Client business is doing good.', $text);
    }

    public function test_map_screenshot_renders_borderless_in_the_web_preview(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg')->size(500)]]],
            'map_screenshot' => UploadedFile::fake()->image('map.png')->size(500),
        ])->assertSessionHasNoErrors();

        $content = $this->actingAs($ci)->get(route('client-folders.residence-business.preview', $folder))->assertOk()->getContent();

        $this->assertStringContainsString('class="photo-frame map-photo-frame photo-frame-plain"', $content);
    }

    public function test_the_docx_export_renders_competitors_before_google_map(): void
    {
        [$ci, $folder, $source] = $this->setUpBusiness();

        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('a.jpg')->size(500)]]],
            'competitor_photos' => [UploadedFile::fake()->image('rival.jpg')->size(500)],
            'competitor_remarks' => 'Competitor stall next door',
            'map_screenshot' => UploadedFile::fake()->image('map.png')->size(500),
        ])->assertSessionHasNoErrors();
        $check = $folder->businessChecks()->firstOrFail();

        $docxResponse = $this->actingAs($ci)->post(route('client-folders.residence-business-checks.batch-export-docx', $folder), [
            'business_check_ids' => [$check->id],
        ])->assertOk();

        $docxPath = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        file_put_contents($docxPath, $docxResponse->streamedContent());
        $zip = new \ZipArchive();
        $zip->open($docxPath);
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($docxPath);

        preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $documentXml, $matches);
        $text = implode('', $matches[1]);

        $competitorPos = strpos($text, 'Competitor stall next door');
        $mapPos = strpos($text, 'Google Map');
        $this->assertNotFalse($competitorPos);
        $this->assertNotFalse($mapPos);
        $this->assertLessThan($mapPos, $competitorPos, 'Competitors must render before Google Map in the DOCX export too.');
    }

    /** @return array{0: User, 1: ClientFolder, 2: IncomeSource} */
    private function setUpBusiness(): array
    {
        $ci = User::factory()->create();
        $folder = $this->folderFor($ci);
        $source = $this->businessSource($folder, 'Sari-Sari Store', 'Poblacion, San Miguel, Bulacan');

        return [$ci, $folder, $source];
    }

    private function createCheck(): array
    {
        [$ci, $folder, $source] = $this->setUpBusiness();
        $this->actingAs($ci)->post(route('client-folders.business-checks.store', $folder), [
            'income_source_id' => $source->id, 'ci_date' => now()->toDateString(), 'location' => 'Poblacion, San Miguel, Bulacan',
            'photo_groups' => [['caption' => '', 'photos' => [UploadedFile::fake()->image('initial.jpg', 900, 700)->size(500)]]],
        ])->assertRedirect();
        $check = $folder->businessChecks()->firstOrFail();

        return [$ci, $folder, $source, $check];
    }

    private function createCheckWithOneGroup(): array
    {
        // createCheck() already satisfies the required-photo rule with one photo in the
        // default/first group — reusing that same group (renaming it "Storefront") instead of
        // creating a second one keeps this check at exactly one group/one photo, matching what
        // every caller of this helper assumes.
        [$ci, $folder, $source, $check] = $this->createCheck();
        $group = $check->photoGroups()->firstOrFail();
        $group->update(['caption' => 'Storefront']);

        return [$ci, $folder, $source, $check->fresh(), $group->fresh()];
    }

    private function createCheckWithLegacyPhoto(): array
    {
        // Simulates a check saved before Photo Groups existed — zero groups, one flat/ungrouped
        // photo. The required-photo rule makes this state impossible to reach through the normal
        // HTTP create flow now, so the check itself is created directly (bypassing validation),
        // exactly like a genuinely historical row would already exist in the database.
        [$ci, $folder, $source] = $this->setUpBusiness();
        $check = $folder->businessChecks()->create([
            'income_source_id' => $source->id,
            'ci_date' => now()->toDateString(),
            'location' => 'Poblacion, San Miguel, Bulacan',
            'ci_user_id' => $ci->id,
        ]);
        $check->photos()->create($this->fakePhotoRow($ci->id));

        return [$ci, $folder, $source, $check->fresh()];
    }

    private function fakePhotoRow(int $uploadedBy): array
    {
        return [
            'category' => 'business', 'file_name' => 'a.jpg', 'path' => 'business/photos/a.jpg',
            'thumbnail_path' => 'business/photos/a-thumb.jpg', 'mime_type' => 'image/jpeg',
            'byte_size' => 500, 'checksum' => md5(uniqid('', true)), 'sort_order' => 0, 'uploaded_by' => $uploadedBy,
        ];
    }

    private function basePayload(BusinessCheck $check): array
    {
        return [
            'check_id' => $check->id,
            'income_source_id' => $check->income_source_id,
            'ci_date' => $check->ci_date->toDateString(),
            'location' => $check->location,
        ];
    }

    private function folderFor(User $ci): ClientFolder
    {
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->addresses()->create(['address_type' => 'present', 'address_line_1' => 'Applicant Address']);
        $folder->cibiReports()->create(['ci_in_charge_id' => $ci->id, 'start_date' => now()->toDateString()]);

        return $folder;
    }

    private function businessSource(ClientFolder $folder, string $name, string $address, ?int $coMakerId = null): IncomeSource
    {
        $template = IncomeSourceTemplate::where('template_type', 'retail_grocery_water_refilling')->firstOrFail();
        $source = $folder->incomeSources()->create(['co_maker_id' => $coMakerId, 'income_source_template_id' => $template->id, 'template_type' => $template->template_type, 'template_version' => $template->version, 'source_name' => $name, 'business_name' => $name]);
        $source->businessReport()->create(['business_name' => $name, 'main_business_address' => $address, 'report_category' => 'retail_grocery_water_refilling']);

        return $source;
    }
}
