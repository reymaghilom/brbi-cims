<?php

namespace Tests\Feature\Ui;

use App\Models\User;
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
            ->assertSee('Photos &amp; Videos', false)
            ->assertSee('Telegram History')
            ->assertSee('Google Drive')
            ->assertSee('Recycle Bin');
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
                $this->actingAs($user)->get(route('home'))->assertOk()->assertSee($greeting);
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
        $this->assertStringContainsString("'[data-folder-create-form], [data-folder-rename-form], [data-folder-recycle-form]'", $javascript);
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
            ->assertSee('Audit Trail');

        $this->actingAs($ci)->get(route('home'))
            ->assertDontSee(route('admin.users.index'), false)
            ->assertDontSee(route('admin.settings.index'), false)
            ->assertDontSee(route('admin.audit-logs.index'), false);
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
            'ui/integration-status-badge', 'ui/missing-items-summary', 'ui/report-preview-toolbar', 'ui/recycle-bin-item',
        ];

        foreach ($components as $component) {
            $this->assertFileExists(resource_path("views/components/$component.blade.php"));
        }
    }
}
