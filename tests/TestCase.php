<?php

namespace Tests;

use App\Models\SystemSetting;
use App\Services\Settings\EvidenceStorageSetting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    private ?string $ciTeamTestRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ciTeamTestRoot = rtrim(sys_get_temp_dir(), '\\/').DIRECTORY_SEPARATOR.'cims-ci-team-tests'.DIRECTORY_SEPARATOR.Str::uuid();
        config(['cims.documents_root' => $this->ciTeamTestRoot]);
    }

    /**
     * Puts the system-wide Evidence Storage setting into Cloud mode. Tests that assert Cloudinary
     * behavior need this because the pilot default is Local — the administrator setting, not
     * whether credentials happen to be configured, is what selects the provider for a new upload.
     */
    protected function useCloudEvidenceStorage(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => EvidenceStorageSetting::KEY],
            ['value' => ['provider' => EvidenceStorageSetting::CLOUDINARY]],
        );
    }

    protected function tearDown(): void
    {
        if ($this->ciTeamTestRoot !== null && is_dir($this->ciTeamTestRoot)) {
            File::deleteDirectory($this->ciTeamTestRoot);
        }
        parent::tearDown();
    }
}
