<?php

namespace App\Services\Reports;

use App\Enums\OfficialReportType;
use App\Models\ClientFolder;
use App\Models\GeneratedReport;
use Illuminate\Support\Str;

/**
 * The one place a user-facing download filename is built.
 *
 * This is deliberately separate from the artifact's stored identity. A stored path still carries
 * the folder number, the report type, the exact person, the source and the version so two
 * generations can never overwrite one another (see GenerateOfficialReport::filename()) — but none
 * of that belongs in the name the browser saves. The download name only has to tell the user what
 * the file is and whose it is:
 *
 *     BRBI_CIBI_Juan-Dela-Cruz.pdf
 *     BRBI_Business_Juan-Dela-Cruz_Sari-Sari.xlsx
 *
 * "BRBI" is added exactly once, here. Every other part has a leading BRBI stripped, because the
 * folder number itself reads "BRBI-CI-2026-00032" and pasting it in produced "BRBI_BRBI-CI-…".
 */
class ReportDownloadName
{
    /** Short, human names for the official report types — never the full title. */
    private const SHORT_NAMES = [
        OfficialReportType::Cibi->value => 'CIBI',
        OfficialReportType::BusinessIncomeSource->value => 'Business',
        OfficialReportType::GeneralIncomeSource->value => 'Business',
        OfficialReportType::ResidenceBusinessPhoto->value => 'Residence',
    ];

    /**
     * @param  string  $reportName  short report name, e.g. "CIBI", "Business", "Residence"
     * @param  string|null  $qualifier  optional short distinguisher, e.g. a business name
     */
    public static function make(string $reportName, ?string $personName, string $extension, ?string $qualifier = null): string
    {
        $parts = ['BRBI', self::part($reportName, 40), self::part((string) $personName, 60)];
        if (filled($qualifier)) {
            $parts[] = self::part($qualifier, 24);
        }

        $stem = collect($parts)->filter()->implode('_');

        return Str::limit($stem, 120, '').'.'.ltrim($extension, '.');
    }

    /** The download name for an already-generated artifact, derived from the row itself. */
    public static function forGeneratedReport(ClientFolder $folder, GeneratedReport $report): string
    {
        $type = OfficialReportType::tryFrom((string) $report->report_type);
        $reportName = self::SHORT_NAMES[$report->report_type] ?? ($type?->shortLabel() ?? 'Report');

        // The exact person the artifact belongs to — a co-maker's own name, or the applicant's.
        $person = $report->co_maker_id
            ? $folder->coMakers()->whereKey($report->co_maker_id)->value('full_name')
            : $folder->display_name;

        // Only a business-style report carries a business distinguisher.
        $qualifier = $report->income_source_id
            ? $folder->incomeSources()->whereKey($report->income_source_id)->value('source_name')
            : null;

        return self::make($reportName, $person, $report->format->value, $qualifier);
    }

    /** Filesystem-safe, hyphenated, no repeated separators, no leading BRBI, no trailing dash. */
    private static function part(string $value, int $limit): string
    {
        $slug = Str::of($value)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9]+/', '-')
            ->replaceMatches('/^BRBI-/i', '')
            ->trim('-')
            ->toString();

        return trim(Str::limit($slug, $limit, ''), '-');
    }
}
