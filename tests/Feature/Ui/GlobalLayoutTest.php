<?php

namespace Tests\Feature\Ui;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GlobalLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_shell_contains_responsive_and_accessible_navigation_foundations(): void
    {
        $ci = User::factory()->create();

        $response = $this->actingAs($ci)->get(route('home'));

        $response->assertOk()
            ->assertSee('id="primary-sidebar"', false)
            ->assertSee('data-mobile-drawer', false)
            ->assertSee('w-64 -translate-x-full', false)
            ->assertSee('min-h-screen lg:pl-64', false)
            ->assertSee('data-drawer-toggle', false)
            ->assertSee('aria-controls="primary-sidebar"', false)
            ->assertSee('Skip to main content')
            ->assertSee('id="main-content"', false)
            ->assertSee('px-4 py-4 sm:px-6 sm:py-5 lg:px-8', false)
            ->assertSee('Dashboard')
            ->assertSee('Client Folders')
            ->assertSee('CI Activities')
            ->assertSee('Reports')
            ->assertDontSee('Photos &amp; Videos', false)
            ->assertDontSee('Telegram History')
            ->assertDontSee('Google Drive')
            ->assertDontSee('Recycle Bin')
            ->assertDontSee('Integrations &amp; records', false)
            ->assertDontSee('Integrations & records');
    }

    public function test_sidebar_restores_previous_branding_and_refines_copyright_footer_without_the_user_card(): void
    {
        $ci = User::factory()->create(['full_name' => 'Sidebar Identity Must Move']);

        $this->actingAs($ci)->get(route('home'))
            ->assertOk()
            ->assertSee(asset('assets/branding/binhi-rural-bank-wordmark-light.png'), false)
            ->assertSee('alt="Binhi Rural Bank Inc."', false)
            ->assertSee('&copy; 2026 <span class="font-medium text-white/65">Binhi Rural Bank Inc.</span> All Rights Reserved.', false)
            ->assertSee('All Rights Reserved.')
            ->assertSee('flex items-center px-4 py-3', false)
            ->assertDontSee('flex items-center border-t border-white/10 px-4 py-3', false)
            ->assertSee('whitespace-normal text-[0.7rem] font-normal leading-4 text-white/50', false)
            ->assertDontSee('<br', false)
            ->assertDontSee('Secure internal banking workspace');

        $this->assertFileExists(public_path('assets/branding/binhi-rural-bank-wordmark-light.png'));
        $this->assertFileExists(public_path('assets/branding/binhi-official-cloud-source.png'));
    }

    public function test_sidebar_hover_and_active_states_are_visually_distinct_and_accessible(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('hover:bg-white/10', $css);
        $this->assertStringContainsString('.ui-sidebar-link-active, .ui-sidebar-link-active:hover', $css);
        $this->assertStringContainsString('bg-brand-sidebar-hover', $css);
        $this->assertStringContainsString('.ui-sidebar-link-active::before', $css);
        $this->assertStringContainsString('background: var(--color-brand-soft)', $css);

        $ci = User::factory()->create();

        $this->actingAs($ci)->get(route('home'))
            ->assertOk()
            ->assertSee('ui-sidebar-link ui-sidebar-link-active', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_shared_modal_uses_a_centered_safe_viewport_overlay(): void
    {
        $component = file_get_contents(resource_path('views/components/ui/modal.blade.php'));

        $this->assertStringContainsString('ui-modal-dialog fixed inset-0 m-auto', $component);
        $this->assertStringContainsString('max-h-[calc(100dvh-2rem)]', $component);
        $this->assertStringContainsString('w-[calc(100%-2rem)]', $component);
        $this->assertStringContainsString('backdrop:bg-brand-sidebar/60', $component);
        $this->assertStringContainsString('backdrop:backdrop-blur-[1px]', $component);
    }

    public function test_favicon_assets_are_local_and_referenced_by_application_and_authentication_layouts(): void
    {
        $ci = User::factory()->create();
        $authResponse = $this->get(route('login'));
        $appResponse = $this->actingAs($ci)->get(route('home'));

        foreach ([$authResponse, $appResponse] as $response) {
            $response->assertOk()
                ->assertSee(asset('assets/branding/favicon-leaf-32x32.png'), false)
                ->assertSee(asset('assets/branding/favicon-leaf-16x16.png'), false)
                ->assertDontSee(asset('assets/branding/brbi-mark.svg'), false)
                ->assertSee(asset('favicon.ico'), false);
        }

        $this->assertFileExists(public_path('assets/branding/favicon-leaf-16x16.png'));
        $this->assertFileExists(public_path('assets/branding/favicon-leaf-32x32.png'));
        $this->assertFileExists(public_path('assets/branding/favicon-leaf-128x128.png'));
        $this->assertFileExists(public_path('favicon.ico'));
        $this->assertSame([16, 16], array_slice(getimagesize(public_path('assets/branding/favicon-leaf-16x16.png')), 0, 2));
        $this->assertSame([32, 32], array_slice(getimagesize(public_path('assets/branding/favicon-leaf-32x32.png')), 0, 2));
    }

    public function test_logout_trigger_is_isolated_from_the_dropdown_it_lives_inside(): void
    {
        $ci = User::factory()->create();

        $content = $this->actingAs($ci)->get(route('home'))->assertOk()->getContent();

        // Every other submit-type menuitem in the app (Download PDF/Excel, etc.) already
        // references an external form via the button's own form="" attribute rather than
        // nesting a <form> directly inside the closing dropdown — Logout must follow the same
        // proven pattern, since closing the <details> on menuitem click hides its own content
        // immediately and a <form> nested inside that content can have its submission silently
        // cancelled by the browser.
        $this->assertMatchesRegularExpression('/<button type="submit" form="logout-form"[^>]*role="menuitem"[^>]*>.*?Logout/s', $content);

        $menuStart = strpos($content, 'data-context-menu');
        $menuEnd = strpos($content, '</details>', $menuStart);
        $dropdownRegion = substr($content, $menuStart, $menuEnd - $menuStart);
        $this->assertStringNotContainsString('<form', $dropdownRegion, 'The logout form must live outside the dropdown, not nested inside it.');

        $this->assertStringContainsString('id="logout-form" method="POST" action="'.route('logout').'"', $content);
    }

    public function test_logout_form_does_not_become_a_third_flex_item_that_would_push_the_profile_control_off_the_right_edge(): void
    {
        $ci = User::factory()->create();

        $content = $this->actingAs($ci)->get(route('home'))->assertOk()->getContent();

        // The header row is `flex ... justify-between` with exactly two children (greeting,
        // account menu) so the account menu stays pinned to the far right — a bare, empty
        // logout <form> living inside that same row would silently become a third flex item and
        // shift the visible profile control away from the right edge, even though the form
        // itself renders nothing visible.
        $headerRowStart = strpos($content, 'class="flex min-h-16 items-center justify-between');
        $this->assertNotFalse($headerRowStart);
        $headerRowEnd = strpos($content, '</header>', $headerRowStart);
        $formPos = strpos($content, 'id="logout-form"', $headerRowStart);
        $this->assertNotFalse($formPos);
        $rowCloseTagPos = strpos($content, "</div>\n", $headerRowStart);

        $this->assertGreaterThan($rowCloseTagPos, $formPos, 'logout-form must be positioned after the flex row closes, not inside it.');
        $this->assertLessThan($headerRowEnd, $formPos);
        $this->assertStringContainsString('id="logout-form" method="POST" action="'.route('logout').'" class="hidden"', $content);
    }

    public function test_topbar_renders_professional_greeting_avatar_role_label_and_logout_only_account_menu(): void
    {
        $ci = User::factory()->create(['full_name' => 'Reasan Mark Q. Gura']);

        $this->actingAs($ci)->get(route('home'))
            ->assertOk()
            ->assertSee('Good ')
            ->assertSee('Reasan Mark Q. Gura')
            ->assertDontSee('Good Morning, Reasan Mark Q. Gura.')
            ->assertSee('Open account menu')
            ->assertSee('data-context-menu', false)
            ->assertSee('aria-haspopup="menu"', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('RM')
            ->assertSee('Credit Investigator')
            ->assertDontSee('credit_investigator')
            ->assertSee('Logout')
            ->assertSee('method="POST" action="'.route('logout').'"', false)
            ->assertSee('hidden min-w-0 text-left sm:block', false);

        $administrator = User::factory()->administrator()->create(['full_name' => 'Bank Administrator']);

        $this->actingAs($administrator)->get(route('home'))
            ->assertOk()
            ->assertSee('Bank Administrator')
            ->assertSee('Administrator')
            ->assertDontSee('credit_investigator');
    }

    public function test_ci_header_bell_shows_a_compact_empty_state_without_a_zero_badge(): void
    {
        $ci = User::factory()->create();

        $content = $this->actingAs($ci)->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-scheduled-today-bell', $content);
        $this->assertStringContainsString('Scheduled Today', $content);
        $this->assertStringContainsString('No scheduled CI activities today.', $content);
        $this->assertStringNotContainsString('data-scheduled-today-count', $content);
        $this->assertStringNotContainsString('0 activities', $content);
        $this->assertStringContainsString('size-9 place-items-center', $content);
        $this->assertStringContainsString('w-[min(23rem,calc(100vw-2.75rem))]', $content);
        $this->assertStringContainsString('View CI Activities', $content);
        $this->assertStringContainsString(route('ci-activities.index'), $content);
        $this->assertLessThan(strpos($content, 'Open account menu'), strpos($content, 'data-scheduled-today-bell'));
    }

    public function test_scheduled_today_bell_is_creator_only_current_state_and_exact_person_scoped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));

        try {
            $creator = User::factory()->create(['full_name' => 'Rey Creator']);
            $otherCi = User::factory()->create(['full_name' => 'Mark Other CI']);
            $folder = ClientFolder::factory()->create(['assigned_ci_id' => $creator->id, 'display_name' => 'MICABALO, RONILO CABIGAS']);
            $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'JUAN CO-MAKER', 'first_name' => 'Juan', 'last_name' => 'Co-Maker']);
            $definition = ActivityDefinition::factory()->create(['name' => 'Header Reminder Type']);
            $todayAt = Carbon::parse('2026-08-29 14:00:00', config('cims.display_timezone'))->utc();
            $laterToday = Carbon::parse('2026-08-29 15:30:00', config('cims.display_timezone'))->utc();
            $tomorrow = Carbon::parse('2026-08-30 09:00:00', config('cims.display_timezone'))->utc();

            $makeActivity = fn (array $attributes): CiActivity => CiActivity::create($attributes + [
                'client_folder_id' => $folder->id,
                'activity_definition_id' => $definition->id,
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => $todayAt,
                'creator_id' => $creator->id,
            ]);

            $applicantActivity = $makeActivity(['name' => 'Applicant Asset Check']);
            $coMakerActivity = $makeActivity(['name' => 'Co-Maker Bank Check', 'co_maker_id' => $coMaker->id, 'scheduled_at' => $laterToday]);
            $makeActivity(['name' => 'Tomorrow Activity', 'scheduled_at' => $tomorrow]);
            $makeActivity(['name' => 'Completed Activity', 'status' => ActivityStatus::Completed, 'completed_at' => now()]);
            $makeActivity(['name' => 'Reopened Pending Activity', 'status' => ActivityStatus::Pending, 'scheduled_at' => null]);
            $deletedActivity = $makeActivity(['name' => 'Deleted Activity']);
            $deletedActivity->delete();
            $otherCiActivity = $makeActivity(['name' => 'Other CI Only', 'creator_id' => $otherCi->id]);
            $creator->notify(new CiActivityScheduledReminder($applicantActivity));
            $creator->notify(new CiActivityScheduledReminder($coMakerActivity));
            $otherCi->notify(new CiActivityScheduledReminder($otherCiActivity));

            $creatorNotifications = $creator->notifications->keyBy(fn ($notification) => (int) data_get($notification->data, 'ci_activity_id'));

            $creatorResponse = $this->actingAs($creator)->get(route('client-folders.index'))->assertOk();
            $creatorResponse->assertSee('data-scheduled-today-count>2</span>', false)
                ->assertSee('rounded-full bg-danger', false)
                ->assertSee('2 activities')
                ->assertSee('Applicant Asset Check')
                ->assertSee('Co-Maker Bank Check')
                ->assertSee('MICABALO, RONILO CABIGAS')
                ->assertSee('title="MICABALO, RONILO CABIGAS · Applicant"', false)
                ->assertSee('title="MICABALO, RONILO CABIGAS · Co-Maker: JUAN CO-MAKER"', false)
                ->assertSee('2:00 PM')
                ->assertSee('3:30 PM')
                ->assertSee('View CI Activities')
                ->assertDontSee('Other CI Only')
                ->assertDontSee('Tomorrow Activity')
                ->assertDontSee('Completed Activity')
                ->assertDontSee('Reopened Pending Activity')
                ->assertDontSee('Deleted Activity')
                ->assertSee(route('notifications.ci-activities.read', $creatorNotifications[$applicantActivity->id]->id), false)
                ->assertSee(route('notifications.ci-activities.read', $creatorNotifications[$coMakerActivity->id]->id), false);

            $this->actingAs($otherCi)->get(route('client-folders.index'))
                ->assertOk()
                ->assertSee('data-scheduled-today-count>1</span>', false)
                ->assertSee('Other CI Only')
                ->assertDontSee('Applicant Asset Check')
                ->assertDontSee('Co-Maker Bank Check');

            $applicantActivity->update(['status' => ActivityStatus::Completed, 'completed_at' => now()]);
            $coMakerActivity->update(['scheduled_at' => $tomorrow]);
            $emptyResponse = $this->actingAs($creator)->get(route('client-folders.index'))->assertOk();
            $emptyResponse->assertDontSee('data-scheduled-today-count', false)
                ->assertSee('No scheduled CI activities today.');

            $reopenedActivity = CiActivity::query()->where('name', 'Reopened Pending Activity')->sole();
            $reopenedActivity->update(['status' => ActivityStatus::Scheduled, 'scheduled_at' => $todayAt]);
            $creator->notify(new CiActivityScheduledReminder($reopenedActivity));
            $this->get(route('home'))
                ->assertOk()
                ->assertSee('data-scheduled-today-count>1</span>', false)
                ->assertSee('Reopened Pending Activity');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_scheduled_today_dropdown_previews_only_the_first_five_activities(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));

        try {
            $ci = User::factory()->create();
            $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);
            $definition = ActivityDefinition::factory()->create();
            $firstSchedule = Carbon::parse('2026-08-29 09:00:00', config('cims.display_timezone'))->utc();

            foreach (range(1, 5) as $number) {
                $activity = CiActivity::create([
                    'client_folder_id' => $folder->id,
                    'activity_definition_id' => $definition->id,
                    'name' => "Preview Activity {$number}",
                    'status' => ActivityStatus::Scheduled,
                    'scheduled_at' => $firstSchedule->copy()->addMinutes($number),
                    'scheduled_has_time' => true,
                    'creator_id' => $ci->id,
                ]);
                $ci->notify(new CiActivityScheduledReminder($activity));
            }

            $content = $this->actingAs($ci)->get(route('client-folders.index'))->assertOk()->getContent();

            $this->assertStringContainsString('data-scheduled-today-count>5</span>', $content);
            $this->assertStringContainsString('5 activities', $content);
            $this->assertSame(5, substr_count($content, 'data-scheduled-today-item='));
            $this->assertStringContainsString('Preview Activity 1', $content);
            $this->assertStringContainsString('Preview Activity 5', $content);
            $this->assertStringContainsString('View CI Activities', $content);

            foreach (range(6, 10) as $number) {
                $activity = CiActivity::create([
                    'client_folder_id' => $folder->id,
                    'activity_definition_id' => $definition->id,
                    'name' => "Preview Activity {$number}",
                    'status' => ActivityStatus::Scheduled,
                    'scheduled_at' => $firstSchedule->copy()->addMinutes($number),
                    'scheduled_has_time' => true,
                    'creator_id' => $ci->id,
                ]);
                $ci->notify(new CiActivityScheduledReminder($activity));
            }

            $overflowContent = $this->get(route('client-folders.index'))->assertOk()->getContent();
            $this->assertStringContainsString('data-scheduled-today-count>9+</span>', $overflowContent);
            $this->assertStringContainsString('10 activities', $overflowContent);
            $this->assertSame(5, substr_count($overflowContent, 'data-scheduled-today-item='));
            $this->assertStringNotContainsString('Preview Activity 6', $overflowContent);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_clicking_a_scheduled_notification_marks_only_the_owners_notification_read_and_preserves_exact_context(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));

        try {
            $creator = User::factory()->create();
            $otherCi = User::factory()->create();
            $folder = ClientFolder::factory()->create(['assigned_ci_id' => $creator->id, 'display_name' => 'READ STATE CLIENT']);
            $coMaker = CoMaker::create(['client_folder_id' => $folder->id, 'full_name' => 'READ STATE CO-MAKER', 'first_name' => 'Read', 'last_name' => 'State']);
            $definition = ActivityDefinition::factory()->create();
            $schedule = Carbon::parse('2026-08-29 10:00:00', config('cims.display_timezone'))->utc();
            $applicantActivity = CiActivity::create([
                'client_folder_id' => $folder->id,
                'activity_definition_id' => $definition->id,
                'name' => 'Unread Applicant Check',
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => $schedule,
                'scheduled_has_time' => true,
                'creator_id' => $creator->id,
            ]);
            $coMakerActivity = CiActivity::create([
                'client_folder_id' => $folder->id,
                'co_maker_id' => $coMaker->id,
                'activity_definition_id' => $definition->id,
                'name' => 'Unread Co-Maker Check',
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => $schedule->copy()->addHour(),
                'scheduled_has_time' => true,
                'creator_id' => $creator->id,
            ]);
            $creator->notify(new CiActivityScheduledReminder($applicantActivity));
            $creator->notify(new CiActivityScheduledReminder($coMakerActivity));
            $notifications = $creator->notifications->keyBy(fn ($notification) => (int) data_get($notification->data, 'ci_activity_id'));
            $applicantNotification = $notifications[$applicantActivity->id];
            $coMakerNotification = $notifications[$coMakerActivity->id];

            $this->actingAs($creator)->get(route('home'))
                ->assertOk()
                ->assertSee('data-scheduled-today-count>2</span>', false)
                ->assertSee('data-scheduled-notification-state="unread"', false)
                ->assertSee(route('notifications.ci-activities.read', $applicantNotification->id), false)
                ->assertSee(route('notifications.ci-activities.read', $coMakerNotification->id), false);
            $this->assertNull($applicantNotification->fresh()->read_at);
            $this->assertNull($coMakerNotification->fresh()->read_at);

            $this->actingAs($otherCi)
                ->post(route('notifications.ci-activities.read', $applicantNotification->id))
                ->assertNotFound();
            $this->assertNull($applicantNotification->fresh()->read_at);

            $this->actingAs($creator)
                ->post(route('notifications.ci-activities.read', $applicantNotification->id))
                ->assertRedirect(route('client-folders.activities.edit', [$folder, $applicantActivity]));
            $this->assertNotNull($applicantNotification->fresh()->read_at);
            $this->assertNull($coMakerNotification->fresh()->read_at);

            $this->get(route('home'))
                ->assertOk()
                ->assertSee('data-scheduled-today-count>1</span>', false)
                ->assertSee('Unread Applicant Check')
                ->assertSee('Unread Co-Maker Check')
                ->assertSee('data-scheduled-notification-state="read"', false)
                ->assertSee('data-scheduled-notification-state="unread"', false);

            $this->post(route('notifications.ci-activities.read', $coMakerNotification->id))
                ->assertRedirect(route('client-folders.activities.edit', [$folder, $coMakerActivity, 'person' => 'co-maker', 'co_maker_id' => $coMaker->id]));
            $this->assertNotNull($coMakerNotification->fresh()->read_at);

            $this->get(route('home'))
                ->assertOk()
                ->assertDontSee('data-scheduled-today-count', false)
                ->assertSee('2 activities')
                ->assertSee('Unread Applicant Check')
                ->assertSee('Unread Co-Maker Check');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_date_only_scheduled_activity_uses_today_label_without_showing_a_fake_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:00:00', 'UTC'));

        try {
            $creator = User::factory()->create();
            $folder = ClientFolder::factory()->create(['assigned_ci_id' => $creator->id, 'display_name' => 'DATE ONLY CLIENT']);
            $definition = ActivityDefinition::factory()->create();
            $activity = CiActivity::create([
                'client_folder_id' => $folder->id,
                'activity_definition_id' => $definition->id,
                'name' => 'Date Only Asset Check',
                'status' => ActivityStatus::Scheduled,
                'scheduled_at' => Carbon::parse('2026-08-29 08:00:00', config('cims.display_timezone'))->utc(),
                'scheduled_has_time' => false,
                'creator_id' => $creator->id,
            ]);
            $creator->notify(new CiActivityScheduledReminder($activity));
            $notification = $creator->notifications()->sole();

            $response = $this->actingAs($creator)->get(route('home'))->assertOk();
            $response->assertSee('data-scheduled-today-count>1</span>', false)
                ->assertSee('Date Only Asset Check')
                ->assertSee('Today')
                ->assertDontSee('8:00 AM')
                ->assertSee(route('notifications.ci-activities.read', $notification->id), false);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_topbar_greeting_uses_asia_manila_local_time(): void
    {
        $user = User::factory()->create(['full_name' => 'Local Time User']);
        $expectations = [
            ['2026-08-08 20:59:00', 'Good Evening, Local Time User'],
            ['2026-08-08 21:00:00', 'Good Morning, Local Time User'],
            ['2026-08-09 03:59:00', 'Good Morning, Local Time User'],
            ['2026-08-09 04:00:00', 'Good Afternoon, Local Time User'],
            ['2026-08-09 09:59:00', 'Good Afternoon, Local Time User'],
            ['2026-08-09 10:00:00', 'Good Evening, Local Time User'],
        ];

        $this->assertSame('Asia/Manila', config('cims.display_timezone'));

        try {
            foreach ($expectations as [$utcTime, $greeting]) {
                Carbon::setTestNow(Carbon::parse($utcTime, 'UTC'));
                // The name is its own element now (it carries the stronger weight), so the
                // greeting label and the name are asserted separately.
                [$label, $name] = explode(', ', $greeting, 2);
                $this->actingAs($user)->get(route('home'))->assertOk()
                    ->assertSee($label.',')
                    ->assertSee($name);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_account_menu_javascript_supports_outside_click_escape_and_single_open_menu(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("event.target.closest('[data-context-menu]')", $javascript);
        $this->assertStringContainsString('details[data-context-menu][open]', $javascript);
        $this->assertStringContainsString('menu !== activeContextMenu', $javascript);
        $this->assertStringContainsString("event.key === 'Escape'", $javascript);
        $this->assertStringContainsString("removeAttribute('open')", $javascript);
        $this->assertStringContainsString("setAttribute('aria-expanded', String(menu.open))", $javascript);
        $this->assertStringContainsString("menu.querySelector(':scope > summary')?.focus()", $javascript);
    }

    public function test_client_search_javascript_supports_debounced_live_results_and_a_clear_action_without_autosuggest(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("document.querySelectorAll('[data-client-search]').forEach(initializeClientSearch)", $javascript);
        $this->assertStringContainsString('window.setTimeout(async () =>', $javascript);
        $this->assertStringContainsString('}, delay)', $javascript);
        $this->assertStringContainsString("search.querySelector('[data-client-search-clear]')", $javascript);
        $this->assertStringContainsString("input.value = ''", $javascript);
        $this->assertStringContainsString('resetFolderPreview', $javascript);
        $this->assertStringNotContainsString('requestSuggestions', $javascript);
        $this->assertStringNotContainsString('renderSuggestions', $javascript);
        $this->assertStringNotContainsString('data.clientSuggestionIndex', $javascript);
    }

    public function test_modal_javascript_focuses_autofocus_fields_and_reopens_validation_errors(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("dialog.querySelector('[autofocus]')?.focus()", $javascript);
        $this->assertStringContainsString("document.querySelectorAll('dialog[data-open-on-error=\"true\"]')", $javascript);
        $this->assertStringContainsString('dialog.showModal()', $javascript);
    }

    public function test_folder_browser_javascript_supports_live_results_and_no_navigation_actions(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('search.dataset.liveSearchUrl', $javascript);
        $this->assertStringContainsString('refreshFolderGrid', $javascript);
        $this->assertStringContainsString('event.preventDefault()', $javascript);
        $this->assertStringContainsString("'[data-folder-browser-layout]'", $javascript);
        $this->assertStringContainsString("'[data-folder-browser-artifacts]'", $javascript);
        $this->assertStringContainsString("'[data-folder-create-form], [data-folder-rename-form], [data-folder-delete-form]'", $javascript);
        $this->assertStringContainsString("Accept: 'application/json'", $javascript);
        $this->assertStringContainsString('resetFolderPreview(browser)', $javascript);
        $this->assertStringContainsString('window.history.replaceState', $javascript);
        $this->assertStringContainsString("['total', 'on_progress']", $javascript);
        $this->assertStringContainsString("browser?.dispatchEvent(new CustomEvent('folder-browser:refresh'))", $javascript);
        $this->assertStringContainsString('window.location.assign(tile.dataset.folderOpenUrl)', $javascript);
    }

    public function test_administrator_navigation_is_visible_only_to_administrators(): void
    {
        $administrator = User::factory()->administrator()->create();
        $ci = User::factory()->create();

        $this->actingAs($administrator)->get(route('home'))
            ->assertSee(route('admin.users.index'), false)
            ->assertSee(route('admin.settings.index'), false)
            ->assertSee(route('admin.audit-logs.index'), false)
            ->assertSee('Users')
            ->assertSee('Settings')
            ->assertSee('Audit Trail')
            ->assertDontSee('Integrations &amp; records', false)
            ->assertDontSee('Recycle Bin');

        $this->actingAs($ci)->get(route('home'))
            ->assertDontSee(route('admin.users.index'), false)
            ->assertDontSee(route('admin.settings.index'), false)
            ->assertDontSee(route('admin.audit-logs.index'), false)
            ->assertDontSee('Integrations &amp; records', false)
            ->assertDontSee('Recycle Bin');
    }

    public function test_authentication_screens_use_accessible_brbi_controls_without_changing_routes(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(asset('assets/branding/binhi-rural-bank-wordmark.png'), false)
            ->assertSee('alt="Binhi Rural Bank Inc."', false)
            ->assertSee('flex min-h-screen items-center justify-center', false)
            ->assertSee('min-h-screen bg-surface', false)
            ->assertDontSee('<body class="min-h-screen bg-brand-primary', false)
            ->assertSee('max-w-[27rem]', false)
            ->assertSee('Credit Investigation Management System')
            ->assertDontSee('<h1', false)
            ->assertSee('for="username"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('name="remember"', false)
            ->assertSee('Remember me')
            ->assertSee('>Login<', false)
            ->assertDontSee('Secure internal workspace')
            ->assertDontSee('Investigation records, organized with confidence.');

        $user = User::factory()->create(['must_change_password' => true]);
        $this->actingAs($user)->get(route('password.change-required.edit'))
            ->assertOk()
            ->assertSee('Create a new password')
            ->assertSee('minlength="12"', false)
            ->assertSee(route('logout'), false);
    }

    public function test_component_foundation_preview_renders_all_major_patterns(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)->get(route('admin.ui-foundation.show'))
            ->assertOk()
            ->assertSee('UI Foundation Preview')
            ->assertSee('role="progressbar"', false)
            ->assertSee('<dialog', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('role="tabpanel"', false)
            ->assertSee('data-toast', false)
            ->assertSee('Missing / Pending Items')
            ->assertSee('Report preview actions');
    }

    public function test_credit_investigator_cannot_access_component_preview(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.ui-foundation.show'))
            ->assertForbidden();
    }

    public function test_design_tokens_and_official_print_foundation_are_centralized(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $printCss = file_get_contents(resource_path('css/print/official-report.css'));

        foreach (['--color-brand-sidebar', '--color-brand-primary', '--color-folder', '--color-progress', '--color-danger', '--color-app-bg', '--radius-card', '--shadow-card'] as $token) {
            $this->assertStringContainsString($token, $css);
        }

        $this->assertStringContainsString('--color-brand-sidebar: #1e3a8a', $css);
        $this->assertStringContainsString('--color-brand-primary: #1e3a8a', $css);
        $this->assertStringContainsString('--color-brand-primary-hover: #172f70', $css);
        $this->assertStringContainsString('--font-sans: Inter, Arial, Helvetica', $css);
        $this->assertStringNotContainsString('--color-brand-primary: #17345c', $css);
        $this->assertStringNotContainsString('--color-brand-primary: #087443', $css);

        $this->assertStringContainsString('size: 8.5in 13in', $printCss);
        $this->assertStringContainsString('break-after: page', $printCss);
        $this->assertStringContainsString('border-collapse: collapse', $printCss);
    }

    public function test_requested_component_files_exist(): void
    {
        $components = [
            'ui/summary-card', 'ui/folder-card', 'ui/status-badge', 'ui/progress-bar', 'ui/client-header',
            'ui/breadcrumb', 'ui/module-card', 'ui/empty-state', 'ui/form-section', 'form/input', 'form/select',
            'form/textarea', 'form/choice-group', 'form/validation-message', 'ui/sticky-form-toolbar', 'ui/modal',
            'ui/confirmation-dialog', 'ui/toast', 'ui/context-menu', 'ui/tabs', 'ui/accordion', 'ui/loading-state',
            'ui/retry-state', 'ui/activity-checklist-item', 'ui/note-timeline', 'ui/media-card',
            'ui/integration-status-badge', 'ui/missing-items-summary', 'ui/report-preview-toolbar',
        ];

        foreach ($components as $component) {
            $this->assertFileExists(resource_path("views/components/$component.blade.php"));
        }
    }
}
