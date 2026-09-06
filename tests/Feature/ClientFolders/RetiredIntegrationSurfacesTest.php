<?php

namespace Tests\Feature\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Telegram History, Google Drive and the legacy Attachments / Documents surface were retired. Each
 * owned nothing but itself: four empty tables, four models, one policy and a set of unimplemented
 * integration contracts. This pins the removal so none of it creeps back, and — just as importantly —
 * that the active features which merely look related were left alone.
 */
class RetiredIntegrationSurfacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_the_retired_pages_are_gone_from_the_sidebar_and_unroutable(): void
    {
        $ci = User::factory()->create();

        $this->actingAs($ci)->get(route('home'))->assertOk()
            ->assertDontSee('Telegram History')
            ->assertDontSee('Google Drive')
            ->assertSee('Client Folders')
            ->assertSee('CI Activities')
            ->assertSee('Reports')
            ->assertSee('Recycle Bin');

        foreach (['telegram-history', 'google-drive'] as $path) {
            $this->actingAs($ci)->get('/'.$path)->assertNotFound();
        }

        $routes = collect(app('router')->getRoutes())->map(fn ($route): string => (string) $route->getName().' '.$route->uri());
        $this->assertFalse($routes->contains(fn (string $route): bool => str_contains($route, 'telegram') || str_contains($route, 'drive')));
    }

    public function test_the_retired_module_cards_no_longer_render_for_a_client_folder(): void
    {
        $ci = User::factory()->create();
        $folder = ClientFolder::factory()->create(['assigned_ci_id' => $ci->id]);

        $this->actingAs($ci)->get(route('client-folders.show', $folder))->assertOk()
            ->assertDontSee('Attachments / Documents')
            ->assertDontSee('No documents available.')
            ->assertDontSee('Google Drive')
            ->assertDontSee('No Drive references available.')
            ->assertDontSee('Telegram History')
            // The surviving modules are untouched.
            ->assertSee('CI Activities')
            ->assertSee('Generated Reports');

        // Their module-placeholder routes were removed with them.
        foreach (['attachments', 'google-drive', 'telegram-history'] as $module) {
            $this->actingAs($ci)->get('/client-folders/'.$folder->id.'/modules/'.$module)->assertNotFound();
        }
    }

    public function test_only_the_exclusively_owned_tables_were_dropped(): void
    {
        foreach (['attachments', 'google_drive_references', 'telegram_messages', 'telegram_message_media'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} belonged only to a retired surface.");
        }

        // The tables active features depend on stay, whole.
        foreach (['media_references', 'generated_reports', 'audit_logs', 'residence_checks', 'business_checks', 'ci_activities', 'co_makers'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is shared with an active feature.");
        }

        // The per-row Drive/Telegram mirror columns went with the features that wrote them.
        foreach (['google_drive_file_id', 'google_drive_link', 'telegram_message_id', 'telegram_link', 'backup_status', 'send_status'] as $column) {
            $this->assertFalse(Schema::hasColumn('media_references', $column), "media_references.{$column} belonged only to a retired integration.");
        }
        $this->assertFalse(Schema::hasColumn('generated_reports', 'google_drive_file_id'));

        // Columns that merely look related are still here: Google MAPS evidence, the File Storage
        // columns behind Supporting Proof, and the local report artifact pointer.
        $this->assertTrue(Schema::hasColumn('media_references', 'google_maps_link'));
        foreach (['storage_provider', 'temporary_local_path', 'thumbnail_path', 'cloudinary_public_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('media_references', $column), "media_references.{$column} backs CI Supporting Proof.");
        }
        $this->assertTrue(Schema::hasColumn('generated_reports', 'private_file_reference'));
        $this->assertTrue(Schema::hasColumn('generated_reports', 'status'));
    }

    public function test_the_removed_models_and_relations_are_gone_without_breaking_the_folder(): void
    {
        foreach (['App\Models\Attachment', 'App\Models\GoogleDriveReference', 'App\Models\TelegramMessage', 'App\Models\TelegramMessageMedia', 'App\Policies\TelegramMessagePolicy'] as $class) {
            $this->assertFalse(class_exists($class), "{$class} belonged only to a retired surface.");
        }

        $folder = ClientFolder::factory()->create();
        foreach (['attachments', 'driveReferences', 'telegramMessages'] as $relation) {
            $this->assertFalse(method_exists($folder, $relation), "ClientFolder::{$relation}() was removed with its table.");
        }

        // The relations active features rely on are all still there.
        foreach (['activities', 'residenceChecks', 'businessChecks', 'generatedReports', 'coMakers', 'incomeSources', 'auditLogs'] as $relation) {
            $this->assertTrue(method_exists($folder, $relation), "ClientFolder::{$relation}() is used by an active feature.");
        }
    }
}
