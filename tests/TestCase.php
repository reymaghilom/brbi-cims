<?php

namespace Tests;

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

    protected function tearDown(): void
    {
        if ($this->ciTeamTestRoot !== null && is_dir($this->ciTeamTestRoot)) {
            File::deleteDirectory($this->ciTeamTestRoot);
        }
        parent::tearDown();
    }
}
