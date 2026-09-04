<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Settings\EvidenceStorageSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Focused coverage for the administrator-controlled Evidence Storage setting itself: its pilot
 * default, both switch directions, the audit trail, and the server-side administrator gate. Upload
 * behavior for the three covered features lives in EvidenceStorageUploadTest.
 */
class EvidenceStorageSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_storage_defaults_to_local_for_a_fresh_installation(): void
    {
        $this->assertSame(EvidenceStorageSetting::LOCAL, app(EvidenceStorageSetting::class)->provider());
        $this->assertFalse(app(EvidenceStorageSetting::class)->usesCloud());
        $this->assertDatabaseMissing('system_settings', ['key' => EvidenceStorageSetting::KEY]);

        // The setting is presented as "File Storage"; the older "Evidence Storage" wording is gone
        // from the page even though every internal identifier deliberately keeps that name.
        $this->actingAs($this->administrator())->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('File Storage')
            ->assertDontSee('Evidence Storage')
            ->assertSee('Current: Local Storage')
            ->assertSee('Switch to Cloud Storage')
            ->assertSee('Switch File Storage to Cloud Storage?')
            ->assertSee('Existing local files will remain in Local Storage.');

        $this->assertSame('evidence_storage_provider', EvidenceStorageSetting::KEY);
    }

    public function test_administrator_can_switch_local_to_cloud_storage_and_the_change_is_audited(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('admin.settings.evidence-storage.update'), ['provider' => EvidenceStorageSetting::CLOUDINARY])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('status', 'File Storage changed from Local Storage to Cloud Storage. Existing files stay where they were saved.');

        $this->assertSame(EvidenceStorageSetting::CLOUDINARY, app(EvidenceStorageSetting::class)->provider());
        $this->assertDatabaseHas('system_settings', ['key' => EvidenceStorageSetting::KEY, 'updated_by' => $admin->id]);

        // The action key is internal and deliberately unchanged; only its readable description
        // follows the new user-facing terminology.
        $audit = AuditLog::query()->where('action', 'system_setting.evidence_storage_changed')->sole();
        $this->assertSame('The file storage provider was changed.', $audit->description);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertNull($audit->client_folder_id);
        $this->assertSame(['setting_key' => EvidenceStorageSetting::KEY, 'from' => 'local', 'to' => 'cloudinary'], (array) $audit->metadata);
    }

    public function test_administrator_can_switch_cloud_storage_back_to_local_and_the_change_is_audited(): void
    {
        $admin = $this->administrator();
        app(EvidenceStorageSetting::class)->update($admin, EvidenceStorageSetting::CLOUDINARY);

        $this->actingAs($admin)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Current: Cloud Storage')
            ->assertSee('Switch File Storage to Local Storage?')
            ->assertSee('Existing Cloud Storage files will remain in Cloud Storage.');

        $this->actingAs($admin)
            ->post(route('admin.settings.evidence-storage.update'), ['provider' => EvidenceStorageSetting::LOCAL])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('status', 'File Storage changed from Cloud Storage to Local Storage. Existing files stay where they were saved.');

        $this->assertSame(EvidenceStorageSetting::LOCAL, app(EvidenceStorageSetting::class)->provider());
        $this->assertSame(
            [['from' => 'local', 'to' => 'cloudinary'], ['from' => 'cloudinary', 'to' => 'local']],
            AuditLog::query()->where('action', 'system_setting.evidence_storage_changed')->orderBy('id')
                ->get()->map(fn ($row) => ['from' => $row->metadata['from'], 'to' => $row->metadata['to']])->all(),
        );
    }

    public function test_a_non_administrator_can_neither_view_nor_change_the_setting_even_by_posting_directly(): void
    {
        $ci = User::factory()->create();

        $this->actingAs($ci)->get(route('admin.settings.index'))->assertForbidden();
        $this->actingAs($ci)
            ->post(route('admin.settings.evidence-storage.update'), ['provider' => EvidenceStorageSetting::CLOUDINARY])
            ->assertForbidden();

        $this->assertSame(EvidenceStorageSetting::LOCAL, app(EvidenceStorageSetting::class)->provider());
        $this->assertDatabaseMissing('system_settings', ['key' => EvidenceStorageSetting::KEY]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'system_setting.evidence_storage_changed']);
    }

    public function test_an_unsupported_provider_is_rejected_and_a_repeated_choice_writes_no_audit_entry(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->from(route('admin.settings.index'))
            ->post(route('admin.settings.evidence-storage.update'), ['provider' => 's3'])
            ->assertSessionHasErrors('provider');
        $this->assertDatabaseMissing('system_settings', ['key' => EvidenceStorageSetting::KEY]);

        $this->actingAs($admin)
            ->post(route('admin.settings.evidence-storage.update'), ['provider' => EvidenceStorageSetting::LOCAL])
            ->assertSessionHas('status', 'File Storage is already set to Local Storage.');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'system_setting.evidence_storage_changed']);
    }

    public function test_an_unrecognized_stored_value_safely_resolves_to_local(): void
    {
        SystemSetting::query()->create(['key' => EvidenceStorageSetting::KEY, 'value' => ['provider' => 'dropbox']]);

        $this->assertSame(EvidenceStorageSetting::LOCAL, app(EvidenceStorageSetting::class)->provider());
    }

    /**
     * The two options are real, individually clickable radio inputs wrapped by their own <label>,
     * which is what makes the control itself (not just the card) selectable. The action button is
     * a passive "already selected" state until the other option is picked, and each provider has
     * its own confirmation dialog so the confirm-before-switch step survives either choice.
     */
    public function test_each_option_is_a_real_labelled_radio_with_its_own_confirmation_dialog(): void
    {
        $page = $this->actingAs($this->administrator())->get(route('admin.settings.index'))->assertOk();
        $html = $page->getContent();

        foreach ([EvidenceStorageSetting::LOCAL, EvidenceStorageSetting::CLOUDINARY] as $provider) {
            $this->assertSame(1, substr_count($html, 'id="file-storage-'.$provider.'"'), "One radio for {$provider}.");
            $this->assertSame(1, substr_count($html, 'for="file-storage-'.$provider.'"'), "One label bound to the {$provider} radio.");
            $this->assertStringContainsString('name="provider" value="'.$provider.'"', $html);
            $this->assertSame(1, substr_count($html, 'id="switch-file-storage-'.$provider.'"'), "One dialog for {$provider}.");
        }

        // The pilot default is Local, so that radio is pre-selected and the button offers nothing
        // to do until the administrator actually picks the other card.
        $this->assertMatchesRegularExpression('/id="file-storage-local"[^>]*\schecked\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="file-storage-cloudinary"[^>]*\schecked\b/', $html);
        $page->assertSee('Current setting selected')->assertSee('data-file-storage-submit', false);

        // Selecting the other card is what turns the button into a real switch action, and both
        // targets are already rendered for it.
        $page->assertSee('Switch to Cloud Storage')->assertSee('Switch to Local Storage');
        $page->assertSee('Switch File Storage to Cloud Storage?')->assertSee('Switch File Storage to Local Storage?');
    }

    public function test_the_active_option_follows_the_persisted_setting(): void
    {
        $admin = $this->administrator();
        app(EvidenceStorageSetting::class)->update($admin, EvidenceStorageSetting::CLOUDINARY);

        $html = $this->actingAs($admin)->get(route('admin.settings.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="file-storage-cloudinary"[^>]*\schecked\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="file-storage-local"[^>]*\schecked\b/', $html);
    }

    private function administrator(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }
}
