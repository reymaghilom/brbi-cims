<?php

namespace App\Actions\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permanently clears the operational workspace - client and investigation records - while leaving
 * every account, role, permission, master definition and system setting exactly as it was.
 *
 * SAFETY MODEL
 *
 * The reset is driven by an EXPLICIT, hand-classified allowlist. It deliberately does NOT
 * enumerate the schema and delete "everything except users": a table added by a future migration
 * must be classified by a human before it can ever be cleared here, and until then it is simply
 * left alone. No schema-level bulk command is used either; every table is emptied with an ordinary
 * DELETE inside one transaction, so a failure anywhere rolls the whole reset back and leaves the
 * database untouched.
 *
 * Ordering is child-before-parent. Most of these tables do cascade from client_folders, but
 * relying on that would make the plan implicit and invisible; listing them makes the blast radius
 * reviewable, and the redundant deletes are harmless.
 *
 * DELIBERATELY OUT OF SCOPE
 *
 * - External file storage. Remotely hosted uploads and the shared network folder are untouched;
 *   only the database rows that reference them are removed. There is no transaction-safe bulk
 *   deletion architecture for those, and half-deleting remote files on a rolled-back transaction
 *   would be worse than leaving them.
 * - Auto-increment sequences. IDs are not reset; relational safety matters more than cosmetics.
 * - folder_number_sequences, which controls human-readable folder numbers and is a business
 *   decision rather than a technical one.
 */
class ResetOperationalData
{
    /**
     * The confirmation phrase an Administrator must type. Validated on the server as well as in
     * the browser, so a forged request without it cannot reach this action.
     */
    public const CONFIRMATION_PHRASE = 'RESET DATA';

    /**
     * Every table this reset is allowed to empty, in child-before-parent order.
     *
     * Each entry is operational because it holds records about a specific client investigation:
     * either it carries client_folder_id itself, or it is a child of a row that does.
     *
     * @var list<string>
     */
    public const OPERATIONAL_TABLES = [
        // --- Supporting proof and media rows (the files themselves are kept) ---
        //     The retired messaging / external-storage / attachment tables are deliberately absent:
        //     those features were removed and their tables dropped, so there is nothing to clear.
        'photo_report_media',
        'photo_report_sections',
        'business_check_photos',
        'business_check_photo_groups',
        'residence_check_photos',
        'activity_media',
        'media_references',

        // --- CI activity detail, then the activities themselves ---
        'ci_activity_asset_targets',
        'ci_activity_bank_targets',
        'activity_notes',
        'ci_activities',

        // --- CI / BI report detail, then the reports ---
        'cibi_bank_accounts',
        'cibi_credit_checks',
        'cibi_income_sources',
        'cibi_legal_findings',
        'cibi_loan_records',
        'cibi_reports',

        // --- Residence and Business checks ---
        'residence_check_contributors',
        'residence_checks',
        'business_check_contributors',
        'business_checks',

        // --- Business report detail, then income sources ---
        'business_branches',
        'business_competitors',
        'business_observations',
        'business_products',
        'business_properties',
        'business_suppliers',
        'business_tenants',
        'declared_income_source_items',
        'business_reports',
        'general_income_source_reports',
        'income_source_contributors',
        'income_sources',

        // --- Residence & Business report module (its retired documentation table is gone) ---
        'residence_business_reports',

        // --- Generated output records (report TEMPLATES are master data and are kept) ---
        'generated_reports',

        // --- Client folder detail, then the folders themselves ---
        'client_completion_results',
        'client_information',
        'client_addresses',
        'co_makers',
        'client_folders',
    ];

    /**
     * Domains reported back to the Administrator, mapped to the tables whose rows they summarise.
     * Table names are never shown in the UI.
     *
     * @var array<string, list<string>>
     */
    public const SUMMARY_DOMAINS = [
        'Client Folders' => ['client_folders'],
        'Co-Makers' => ['co_makers'],
        'CI Activities' => ['ci_activities'],
        'Investigation Records' => ['cibi_reports', 'residence_checks', 'business_checks', 'income_sources'],
        'Generated Reports' => ['generated_reports'],
    ];

    /**
     * Read-only counts for the confirmation summary. Five bounded COUNT(*) queries - never a
     * whole-database scan, and never a mutation.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $counts = [];

        foreach (self::SUMMARY_DOMAINS as $label => $tables) {
            $counts[$label] = collect($tables)->sum(fn (string $table): int => DB::table($table)->count());
        }

        return $counts;
    }

    /**
     * @return array<string, int> rows removed per domain, for the audit record
     */
    public function execute(User $actor): array
    {
        $summary = $this->summary();

        DB::transaction(function () use ($actor, $summary): void {
            foreach (self::OPERATIONAL_TABLES as $table) {
                // A listed table that a later migration retires is skipped rather than fataling.
                // A typo cannot hide here - the test suite asserts every listed table exists.
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            // Client-scoped history goes with the records it describes. System-level entries -
            // user management, settings changes, and the reset event written below - carry no
            // client_folder_id and are deliberately kept.
            DB::table('audit_logs')->whereNotNull('client_folder_id')->delete();

            // The scheduled-reminder notifications were about activities that no longer exist.
            // Only that one notification type is cleared, so nothing else a user was sent is lost.
            DB::table('notifications')->where('type', CiActivityScheduledReminder::class)->delete();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => null,
                'action' => 'system.operational_data_reset',
                'module' => 'system',
                'description' => 'Operational data was reset. User accounts and system master data were preserved.',
                'metadata' => ['removed' => $summary],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });

        return $summary;
    }
}
