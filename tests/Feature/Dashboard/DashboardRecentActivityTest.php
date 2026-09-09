<?php

namespace Tests\Feature\Dashboard;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * DASHBOARD RECENT ACTIVITY — the panel shows the three newest audit events on the same timeline the
 * Client Folder's own Recent Activity uses (client-folders/partials/recent-activity-body.blade.php):
 * a dot node per entry, a dashed connector that stops at the last one, and each entry carrying its
 * action, its actor and its own persisted date and time.
 *
 * The data source is unchanged — the existing AuditLog read through ClientFolderOverview's audit
 * vocabulary. Only how much is fetched, and how it is presented, changed here.
 */
class DashboardRecentActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    /** TESTS 1, 2, 4 — at most three, the newest three, newest first. */
    public function test_only_the_three_newest_activities_are_listed_newest_first(): void
    {
        $ci = User::factory()->create(['full_name' => 'Rey C. Maghilom']);
        $folder = $this->folder($ci, 'ALPHA, CLIENT');

        // Eight events, oldest to newest, one hour apart.
        foreach (range(1, 8) as $offset) {
            $this->audit($ci, $folder, 'residence_check.updated', Carbon::parse('2026-09-08 06:00:00')->addHours($offset));
        }

        $events = collect($this->viewData($ci, 'recentActivity'));

        $this->assertCount(3, $events, 'The dashboard lists at most three.');

        // Newest first, and the five oldest are not listed at all. Expectations are converted
        // through the same display timezone the panel renders in, so this asserts the ordering
        // rather than re-encoding whichever zone the app happens to be configured for.
        $expected = collect([8, 7, 6])
            ->map(fn (int $offset): string => Carbon::parse('2026-09-08 06:00:00')
                ->addHours($offset)
                ->timezone(config('cims.display_timezone'))
                ->format('Y-m-d H:i:s'))
            ->all();

        $this->assertSame($expected, $events->map(fn (array $event): string => $event['at']->format('Y-m-d H:i:s'))->all());
    }

    /** TEST 3 — every listed entry carries its action, actor, date and time. */
    public function test_each_entry_shows_its_action_actor_date_and_time(): void
    {
        $ci = User::factory()->create(['full_name' => 'Rey C. Maghilom']);
        $folder = $this->folder($ci, 'BRAVO, CLIENT');
        $at = Carbon::parse('2026-09-08 13:15:00');
        $this->audit($ci, $folder, 'business_check.updated', $at);

        $panel = $this->panel($this->html($ci));
        $shown = $at->copy()->timezone(config('cims.display_timezone'))->format('M j, Y · g:i A');

        // Action title, actor and the date · time line, in the project's existing
        // "M j, Y · g:i A" format — the same one the folder timeline and its modal use.
        $this->assertStringContainsString('Business Check updated', $panel);
        $this->assertStringContainsString('Rey C. Maghilom', $panel);
        $this->assertStringContainsString($shown, $panel);
        $this->assertMatchesRegularExpression('/\d{1,2}:\d{2} (AM|PM)/', $shown, 'A wall-clock time is part of the line.');

        // The timestamp is the activity's own persisted one, not "now" and not a relative phrase.
        $this->assertStringNotContainsString('ago', $panel);

        $event = collect($this->viewData($ci, 'recentActivity'))->firstOrFail();
        $this->assertSame('Business Check updated', $event['label']);
        $this->assertSame('Rey C. Maghilom', $event['user']);
        // The activity's own persisted instant, simply presented in the display timezone.
        $this->assertTrue($at->equalTo($event['at']));
    }

    /** TEST 5 — more than three activities earns a View All, placed below the timeline. */
    public function test_view_all_appears_below_the_timeline_when_more_than_three_exist(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'CHARLIE, CLIENT');
        foreach (range(1, 4) as $offset) {
            $this->audit($ci, $folder, 'residence_check.updated', Carbon::parse('2026-09-08 06:00:00')->addMinutes($offset));
        }

        $this->assertTrue($this->viewData($ci, 'recentActivityHasMore'));

        $panel = $this->panel($this->html($ci));
        $this->assertStringContainsString('View All', $panel);

        // Below the list, never above it or inside an entry.
        $this->assertGreaterThan(strpos($panel, '</ol>'), strpos($panel, 'View All'));
    }

    /** TEST 6 — exactly three (or fewer) must not offer a pointless View All. */
    public function test_view_all_is_absent_when_three_or_fewer_activities_exist(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'DELTA, CLIENT');
        foreach (range(1, 3) as $offset) {
            $this->audit($ci, $folder, 'residence_check.updated', Carbon::parse('2026-09-08 06:00:00')->addMinutes($offset));
        }

        $this->assertFalse($this->viewData($ci, 'recentActivityHasMore'));
        $this->assertCount(3, $this->viewData($ci, 'recentActivity'));
        $this->assertStringNotContainsString('View All', $this->panel($this->html($ci)));
    }

    /** TESTS 7, 8 — the markup is the Folder Contents timeline, and the connector stops cleanly. */
    public function test_the_timeline_markup_matches_the_folder_contents_recent_activity_pattern(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'ECHO, CLIENT');
        // More than the panel shows, so this covers the real case: a list truncated at three.
        foreach (range(1, 7) as $offset) {
            $this->audit($ci, $folder, 'residence_check.updated', Carbon::parse('2026-09-08 06:00:00')->addMinutes($offset));
        }

        $panel = $this->panel($this->html($ci));
        $reference = file_get_contents(resource_path('views/client-folders/partials/recent-activity-body.blade.php'));

        // The same list, row, node and connector classes the folder partial defines.
        foreach ([
            'relative mt-6 space-y-0',
            'relative grid grid-cols-[1rem_1fr] gap-3 pb-6 last:pb-0',
            'relative z-10 mt-1 size-3.5 rounded-full border-2 border-white bg-brand-primary shadow-sm',
            'absolute bottom-0 left-[0.4375rem] top-4 border-l border-dashed border-ui-border-strong',
        ] as $pattern) {
            $this->assertStringContainsString($pattern, $reference, 'The folder partial is the source of this pattern.');
            $this->assertStringContainsString($pattern, $panel, 'The dashboard reuses it verbatim.');
        }

        // Three entries, but only two connectors: the line terminates on the third (final) visible
        // activity rather than trailing past it into the View All below.
        $this->assertSame(3, substr_count($panel, 'bg-brand-primary shadow-sm'));
        $this->assertSame(2, substr_count($panel, 'border-l border-dashed border-ui-border-strong'));
        $this->assertSame(3, substr_count($panel, '<li class='), 'Only three rows are rendered at all — the rest are never fetched.');
    }

    /** TEST 9 — rendering the dashboard writes no audit rows of its own. */
    public function test_rendering_the_dashboard_creates_no_activity_records(): void
    {
        $ci = User::factory()->create();
        $folder = $this->folder($ci, 'FOXTROT, CLIENT');
        $this->audit($ci, $folder, 'residence_check.updated', Carbon::parse('2026-09-08 06:00:00'));
        $before = AuditLog::query()->count();

        $this->html($ci);
        $this->html($ci);

        $this->assertSame($before, AuditLog::query()->count());
    }

    /** TEST 10 — labels, actor attribution and stored timestamps are all untouched. */
    public function test_labels_actor_attribution_and_timestamps_are_unchanged(): void
    {
        $ci = User::factory()->create(['full_name' => 'Recorded Actor']);
        $colleague = User::factory()->create(['full_name' => 'Team Mate']);
        $folder = $this->folder($ci, 'GOLF, CLIENT');
        $log = $this->audit($colleague, $folder, 'business_check.updated', Carbon::parse('2026-09-08 13:15:00'));
        $stored = $log->fresh()->getRawOriginal();

        $event = collect($this->viewData($ci, 'recentActivity'))->firstOrFail();

        // The existing audit vocabulary, and the colleague who actually performed the action.
        $this->assertSame('Business Check updated', $event['label']);
        $this->assertSame('Team Mate', $event['user']);
        $this->assertSame('GOLF, CLIENT', $event['client']);
        $this->assertSame('business_check.updated', $log->fresh()->action);

        // Not one column of the audit row moved.
        $this->assertSame($stored, $log->fresh()->getRawOriginal());
    }

    private function folder(User $ci, string $name): ClientFolder
    {
        return ClientFolder::factory()->create([
            'assigned_ci_id' => $ci->id, 'created_by' => $ci->id, 'display_name' => $name,
        ]);
    }

    private function audit(User $actor, ClientFolder $folder, string $action, Carbon $at): AuditLog
    {
        $log = AuditLog::create([
            'user_id' => $actor->id,
            'client_folder_id' => $folder->id,
            'action' => $action,
            'module' => 'residence_business_report',
            'description' => 'Saved.',
        ]);

        // AuditLog stamps created_at itself; these tests need exact, ordered instants.
        $log->forceFill(['created_at' => $at])->saveQuietly();

        return $log;
    }

    private function html(User $ci): string
    {
        return $this->actingAs($ci)->get(route('home'))->assertOk()->getContent();
    }

    private function viewData(User $ci, string $key): mixed
    {
        return $this->actingAs($ci)->get(route('home'))->assertOk()->viewData($key);
    }

    /** Just the Recent Activity card, so a "View All" elsewhere on the page cannot be mistaken for it. */
    private function panel(string $html): string
    {
        $start = strpos($html, 'aria-labelledby="recent-activity-title"');
        $this->assertNotFalse($start, 'The Recent Activity panel must be on the dashboard.');

        $end = strpos($html, '</article>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}
