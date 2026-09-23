<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPolicyPagesTest extends TestCase
{
    public function test_acceptable_use_policy_is_public_and_has_required_navigation(): void
    {
        $this->get(route('policies.acceptable-use'))
            ->assertOk()
            ->assertSee('Acceptable Use Policy')
            ->assertSee('Authorized Use Only')
            ->assertSee('Version 1.0')
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('Back to Sign in')
            ->assertSee('href="'.route('policies.privacy').'"', false)
            ->assertSee('Privacy Notice');
    }

    public function test_privacy_notice_is_public_and_has_required_navigation(): void
    {
        $this->get(route('policies.privacy'))
            ->assertOk()
            ->assertSee('Privacy Notice')
            ->assertSee('Personal Information We Process')
            ->assertSee('Version 1.0')
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('Back to Sign in')
            ->assertSee('href="'.route('policies.acceptable-use').'"', false)
            ->assertSee('Acceptable Use Policy');
    }

    public function test_sign_in_page_links_to_both_public_policy_pages(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('href="'.route('policies.acceptable-use').'"', false)
            ->assertSee('Acceptable Use Policy')
            ->assertSee('href="'.route('policies.privacy').'"', false)
            ->assertSee('Privacy Notice');
    }
}
