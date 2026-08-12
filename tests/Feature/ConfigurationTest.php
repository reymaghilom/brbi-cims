<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfigurationTest extends TestCase
{
    public function test_the_phase_one_application_configuration_is_consistent(): void
    {
        $this->assertSame('BRBI CIMS', config('app.name'));
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('Asia/Manila', config('cims.display_timezone'));
        $this->assertStringContainsString('DB_CONNECTION=mysql', file_get_contents(base_path('.env.example')));
    }

    public function test_confirmed_architecture_decisions_are_exposed_as_configuration(): void
    {
        $this->assertSame(8.5, config('cims.reports.default_paper.width_inches'));
        $this->assertSame(13.0, config('cims.reports.default_paper.height_inches'));
        $this->assertSame('required_items', config('cims.progress.strategy'));
        $this->assertFalse(config('cims.progress.weighted'));
        $this->assertSame(1, config('cims.client_folder_ownership.primary_investigators'));
        $this->assertFalse(config('cims.client_folder_ownership.many_to_many_enabled'));
    }
}
