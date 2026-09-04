<?php

namespace Tests\Feature\ClientFolders;

use App\Enums\OfficialReportType;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Models\ResidenceBusinessDocumentation;
use App\Models\User;
use App\Services\Media\PrivateMediaStorage;
use App\Services\Storage\CiTeamDocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class CiTeamDocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_root_resolves_from_user_profile_without_writing_to_it(): void
    {
        $testRoot = config('cims.documents_root');
        config(['cims.documents_root' => null]);

        $expected = rtrim((string) getenv('USERPROFILE'), '\\/').DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'CI Team';
        $this->assertSame($expected, app(CiTeamDocumentStorage::class)->root());
        $this->assertStringNotContainsString(DIRECTORY_SEPARATOR.'Clients', $expected);

        config(['cims.documents_root' => $testRoot]);
    }

    public function test_default_root_uses_server_profile_when_getenv_userprofile_is_unavailable(): void
    {
        $testRoot = config('cims.documents_root');
        $environmentProfile = getenv('USERPROFILE');
        $serverProfile = $_SERVER['USERPROFILE'] ?? null;

        try {
            config(['cims.documents_root' => null]);
            putenv('USERPROFILE');
            $_SERVER['USERPROFILE'] = 'C:\\Users\\WebProcess';

            $this->assertSame(
                'C:\\Users\\WebProcess'.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'CI Team',
                app(CiTeamDocumentStorage::class)->root(),
            );
        } finally {
            $environmentProfile === false ? putenv('USERPROFILE') : putenv('USERPROFILE='.$environmentProfile);
            if ($serverProfile === null) {
                unset($_SERVER['USERPROFILE']);
            } else {
                $_SERVER['USERPROFILE'] = $serverProfile;
            }
            config(['cims.documents_root' => $testRoot]);
        }
    }

    public function test_default_root_uses_the_standard_windows_temp_directory_when_profile_environment_values_are_unavailable(): void
    {
        $normalizedTemp = str_replace('\\', '/', sys_get_temp_dir());
        if (! preg_match('#^([A-Za-z]:/Users/[^/]+)/AppData/Local/Temp(?:/|$)#i', $normalizedTemp, $matches)) {
            $this->markTestSkipped('This fallback applies only to the standard per-user Windows temp directory.');
        }

        $testRoot = config('cims.documents_root');
        $keys = ['USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'LOCALAPPDATA', 'APPDATA', 'HOME'];
        $original = [];

        try {
            config(['cims.documents_root' => null]);
            foreach ($keys as $key) {
                $original[$key] = [getenv($key), $_SERVER[$key] ?? null, $_ENV[$key] ?? null];
                putenv($key);
                unset($_SERVER[$key], $_ENV[$key]);
            }

            $profile = str_replace('/', DIRECTORY_SEPARATOR, $matches[1]);
            $this->assertSame(
                $profile.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'CI Team',
                app(CiTeamDocumentStorage::class)->root(),
            );
        } finally {
            foreach ($original as $key => [$environment, $server, $env]) {
                $environment === false ? putenv($key) : putenv($key.'='.$environment);
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
                if ($env === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $env;
                }
            }
            config(['cims.documents_root' => $testRoot]);
        }
    }

    public function test_configured_root_is_the_directory_that_directly_contains_client_folders(): void
    {
        $configured = 'D:\\CI Team';
        config(['cims.documents_root' => $configured]);

        $this->assertSame($configured, app(CiTeamDocumentStorage::class)->root());
        $this->assertStringNotContainsString('Clients', app(CiTeamDocumentStorage::class)->root());
    }

    public function test_legacy_configured_clients_suffix_is_normalized_for_new_writes(): void
    {
        config(['cims.documents_root' => 'D:\\CI Team\\Clients']);

        $this->assertSame('D:\\CI Team', app(CiTeamDocumentStorage::class)->root());
    }

    public function test_client_directory_simplifies_expected_folder_numbers_without_changing_the_database_value(): void
    {
        $client = ClientFolder::factory()->create(['display_name' => 'TEST, TEST']);
        $storage = app(CiTeamDocumentStorage::class);
        $cases = [
            'BRBI-CI-2026-00001' => 'CI-2026-001 - TEST, TEST',
            'BRBI-CI-2026-00007' => 'CI-2026-007 - TEST, TEST',
            'BRBI-CI-2026-00025' => 'CI-2026-025 - TEST, TEST',
            'BRBI-CI-2026-00125' => 'CI-2026-125 - TEST, TEST',
            'BRBI-CI-2026-01000' => 'CI-2026-1000 - TEST, TEST',
        ];

        foreach ($cases as $authoritative => $filesystem) {
            $client->update(['folder_number' => $authoritative]);

            $this->assertSame($filesystem, $storage->clientDirectory($client));
            $this->assertSame($authoritative, $client->fresh()->folder_number);
        }
    }

    public function test_unexpected_folder_number_uses_a_stable_id_based_fallback(): void
    {
        $client = ClientFolder::factory()->create([
            'folder_number' => 'HISTORICAL/FOLDER',
            'display_name' => 'Fallback Client',
        ]);
        $expected = 'CI-'.str_pad((string) $client->id, 3, '0', STR_PAD_LEFT).' - Fallback Client';

        $this->assertSame($expected, app(CiTeamDocumentStorage::class)->clientDirectory($client));
        $this->assertSame('HISTORICAL/FOLDER', $client->fresh()->folder_number);
    }

    public function test_it_builds_the_required_applicant_and_co_maker_directories(): void
    {
        $client = ClientFolder::factory()->create(['folder_number' => 'BRBI-CI-2026-00001', 'display_name' => 'Jane / Doe']);
        $coMaker = CoMaker::create(['client_folder_id' => $client->id, 'full_name' => 'John: Smith?']);
        $storage = app(CiTeamDocumentStorage::class);

        $this->assertSame('CI-2026-001 - Jane Doe/Residence Pictures/Google Map', $storage->residenceGoogleMapDirectory($client));
        $this->assertSame('CI-2026-001 - Jane Doe/Residence Pictures/Pictures', $storage->residencePicturesDirectory($client));
        $this->assertSame('CI-2026-001 - Jane Doe/Residence Pictures/Videos', $storage->residenceVideosDirectory($client));
        $this->assertSame('CI-2026-001 - Jane Doe/Business Pictures/Google Map', $storage->businessGoogleMapDirectory($client));
        $this->assertSame('CI-2026-001 - Jane Doe/Business Pictures/Pictures', $storage->businessPicturesDirectory($client));
        $this->assertSame('CI-2026-001 - Jane Doe/Business Pictures/Videos', $storage->businessVideosDirectory($client));
        $this->assertSame('CI-2026-001 - Jane Doe/CIBI Report', $storage->cibiReportDirectory($client));
        $this->assertSame('CI-2026-001 - Jane Doe/Business Report', $storage->businessReportDirectory($client));
        $this->assertSame('CI-2026-001 - Jane Doe/CI Activities', $storage->ciActivitiesDirectory($client));
        $this->assertSame('CI-2026-001 - Jane Doe/Residence Check Report', $storage->residenceCheckReportDirectory($client));
        $this->assertSame('CI-2026-001 - Jane Doe/Business Check Report', $storage->businessCheckReportDirectory($client));
        $this->assertSame('CI-2026-001 - Jane Doe/Co-Makers/CM-'.str_pad((string) $coMaker->id, 6, '0', STR_PAD_LEFT).' - John Smith/Business Pictures/Pictures', $storage->businessPicturesDirectory($client, $coMaker));
    }

    public function test_it_rejects_traversal_and_cross_client_co_makers(): void
    {
        $storage = app(CiTeamDocumentStorage::class);
        $client = ClientFolder::factory()->create();
        $other = ClientFolder::factory()->create();
        $coMaker = CoMaker::create(['client_folder_id' => $other->id, 'full_name' => 'Other']);

        try {
            $storage->relative('../outside');
            $this->fail('Traversal should be rejected.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->expectException(RuntimeException::class);
        $storage->personDirectory($client, $coMaker);
    }

    public function test_each_co_maker_resolves_to_an_isolated_folder_with_the_full_structure(): void
    {
        $client = ClientFolder::factory()->create(['folder_number' => 'BRBI-CI-2026-00001', 'display_name' => 'Client']);
        $first = CoMaker::create(['client_folder_id' => $client->id, 'full_name' => 'First']);
        $second = CoMaker::create(['client_folder_id' => $client->id, 'full_name' => 'Second']);
        $storage = app(CiTeamDocumentStorage::class);

        $this->assertNotSame($storage->personDirectory($client, $first), $storage->personDirectory($client, $second));
        $this->assertStringContainsString('/Co-Makers/CM-', $storage->residenceVideosDirectory($client, $first));
        $this->assertStringEndsWith('/Residence Pictures/Videos', $storage->residenceVideosDirectory($client, $first));
        $this->assertStringEndsWith('/Business Check Report', $storage->businessCheckReportDirectory($client, $first));
    }

    public function test_new_documentation_media_uses_the_ci_team_root_and_collision_safe_names(): void
    {
        $client = ClientFolder::factory()->create(['folder_number' => 'BRBI-CI-2026-00001', 'display_name' => 'Test Client']);
        $creator = User::factory()->create();
        $documentation = ResidenceBusinessDocumentation::create([
            'client_folder_id' => $client->id,
            'category' => ResidenceBusinessDocumentation::CATEGORY_RESIDENCE,
            'location' => 'Test location',
            'created_by' => $creator->id,
        ]);
        $storage = app(PrivateMediaStorage::class);

        $first = $storage->storeDocumentation($documentation, UploadedFile::fake()->image('same.jpg'), 'picture');
        $second = $storage->storeDocumentation($documentation, UploadedFile::fake()->image('same.jpg'), 'picture');

        $this->assertSame(MediaReference::STORAGE_PROVIDER_CI_TEAM, $first['storage_provider']);
        $this->assertStringStartsWith('CI-2026-001 - Test Client/Residence Pictures/Pictures/', $first['temporary_local_path']);
        $this->assertStringNotContainsString('Clients/', $first['temporary_local_path']);
        $this->assertNotSame($first['temporary_local_path'], $second['temporary_local_path']);
        $this->assertNull($first['thumbnail_path']);
        $this->assertNull($second['thumbnail_path']);
        $this->assertTrue(app(CiTeamDocumentStorage::class)->disk()->exists($first['temporary_local_path']));
        $this->assertCount(2, app(CiTeamDocumentStorage::class)->disk()->allFiles(app(CiTeamDocumentStorage::class)->residencePicturesDirectory($client)));
        $this->assertSame([], array_values(array_filter(
            app(CiTeamDocumentStorage::class)->disk()->allFiles(),
            fn (string $path): bool => str_contains($path, '.thumb.jpg'),
        )));
    }

    public function test_new_business_media_uses_business_folders_and_exact_deletion_keeps_its_sibling(): void
    {
        $creator = User::factory()->create();
        $client = ClientFolder::factory()->create(['folder_number' => 'BRBI-CI-2026-00025', 'display_name' => 'Business Client']);
        $documentation = ResidenceBusinessDocumentation::create([
            'client_folder_id' => $client->id,
            'category' => ResidenceBusinessDocumentation::CATEGORY_BUSINESS,
            'location' => 'Business location',
            'created_by' => $creator->id,
        ]);
        $mediaStorage = app(PrivateMediaStorage::class);
        $first = $mediaStorage->storeDocumentation($documentation, UploadedFile::fake()->image('store.jpg'), 'picture');
        $second = $mediaStorage->storeDocumentation($documentation, UploadedFile::fake()->image('store.jpg'), 'picture');
        $disk = app(CiTeamDocumentStorage::class)->disk();

        $this->assertStringContainsString('/Business Pictures/Pictures/', $first['temporary_local_path']);
        $this->assertStringStartsWith('CI-2026-025 - Business Client/', $first['temporary_local_path']);
        $this->assertStringNotContainsString('Clients/', $first['temporary_local_path']);
        $this->assertNull($first['thumbnail_path']);
        $this->assertNull($second['thumbnail_path']);
        $this->assertCount(2, $disk->allFiles(app(CiTeamDocumentStorage::class)->businessPicturesDirectory($client)));
        $mediaStorage->deleteStoredFiles([$first['temporary_local_path'], $first['thumbnail_path']], MediaReference::STORAGE_PROVIDER_CI_TEAM);
        $this->assertFalse($disk->exists($first['temporary_local_path']));
        $this->assertTrue($disk->exists($second['temporary_local_path']));
    }

    public function test_legacy_media_disk_remains_selectable_and_official_reports_use_category_paths(): void
    {
        Storage::fake('local');
        config(['cims.media_disk' => 'local', 'cims.report_disk' => 'local']);
        $client = ClientFolder::factory()->create(['folder_number' => 'BRBI-CI-2026-00300', 'display_name' => 'Legacy Test']);
        $documents = app(CiTeamDocumentStorage::class);
        $legacy = new MediaReference(['storage_provider' => MediaReference::STORAGE_PROVIDER_LOCAL]);

        Storage::disk('local')->put('client-media/legacy.jpg', 'legacy');
        $this->assertTrue($documents->diskForMedia($legacy)->exists('client-media/legacy.jpg'));
        $this->assertSame('CI-2026-300 - Legacy Test/CIBI Report/report.pdf', $documents->officialReportPath($client, OfficialReportType::Cibi, 'report.pdf'));
        $this->assertTrue($documents->isLegacyReportPath('generated-reports/C-300/report.pdf'));
        $this->assertFalse($documents->isLegacyReportPath('CI-2026-300 - Legacy Test/CIBI Report/report.pdf'));
    }

    public function test_ci_team_media_resolver_reads_both_new_and_legacy_clients_roots(): void
    {
        $documents = app(CiTeamDocumentStorage::class);
        $newPath = 'CI-2026-001 - New Client/Residence Pictures/Pictures/new.jpg';
        $legacyPath = 'BRBI-CI-2026-00001 - Legacy Client/Residence Pictures/Pictures/legacy.jpg';
        $legacyThumbnailPath = 'BRBI-CI-2026-00001 - Legacy Client/Residence Pictures/Pictures/legacy.thumb.jpg';
        $new = new MediaReference([
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CI_TEAM,
            'temporary_local_path' => $newPath,
        ]);
        $legacy = new MediaReference([
            'storage_provider' => MediaReference::STORAGE_PROVIDER_CI_TEAM,
            'temporary_local_path' => $legacyPath,
            'thumbnail_path' => $legacyThumbnailPath,
        ]);

        $documents->disk()->put($newPath, 'new');
        $legacyDisk = Storage::build([
            'driver' => 'local',
            'root' => $documents->root().DIRECTORY_SEPARATOR.'Clients',
            'throw' => true,
        ]);
        $legacyDisk->put($legacyPath, 'legacy');
        $legacyDisk->put($legacyThumbnailPath, 'legacy-thumbnail');

        $this->assertSame('new', $documents->diskForMedia($new)->get($newPath));
        $this->assertSame('legacy', $documents->diskForMedia($legacy)->get($legacyPath));
        $this->assertSame('legacy-thumbnail', $documents->diskForMedia($legacy, $legacyThumbnailPath)->get($legacyThumbnailPath));
    }
}
