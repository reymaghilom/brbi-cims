<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ActivityDefinition;
use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: marking a Barangay/Neighbor Check complete answered 419 "CSRF token mismatch."
 *
 * The tap-to-complete paths post with fetch() and no rendered <form>, so they build their own body
 * and read the token with `document.querySelector('meta[name="csrf-token"]')`. layouts/app.blade.php
 * never emitted that meta tag, so the lookup returned null, `_token` was posted as an EMPTY string
 * and VerifyCsrfToken rejected the request. Anything submitted through a real form carried @csrf and
 * kept working, which is why only the quick-complete affordances failed.
 */
class QuickCompleteCsrfTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_pages_hosting_the_quick_complete_handlers_render_a_usable_csrf_meta_token(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id, 'created_by' => $ci->id]);
        $folder->activities()->create([
            'activity_definition_id' => ActivityDefinition::query()->where('code', ActivityDefinition::BARANGAY_CHECK_CODE)->value('id'),
            'name' => 'Barangay Check',
            'creator_id' => $ci->id,
        ]);

        foreach ([route('home'), route('client-folders.activities.index', $folder)] as $url) {
            $content = $this->actingAs($ci)->get($url)->assertOk()->getContent();

            preg_match('/<meta name="csrf-token" content="([^"]*)">/', $content, $matches);
            $this->assertNotEmpty($matches, 'The csrf-token meta tag must be present on '.$url);
            $this->assertNotSame('', $matches[1], 'The csrf-token meta tag must carry a real token, not an empty value.');
            $this->assertSame(csrf_token(), $matches[1]);
        }
    }

    /** The handlers must keep reading the token from that exact tag, never from a hardcoded value. */
    public function test_the_quick_complete_handlers_still_source_their_token_from_the_meta_tag(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));
        $activitiesBlade = file_get_contents(resource_path('views/client-folders/activities/index.blade.php'));

        foreach ([$appJs, $activitiesBlade] as $source) {
            $this->assertStringContainsString("formData.set('_token', document.querySelector('meta[name=\"csrf-token\"]')?.content ?? '');", $source);
        }
    }
}
