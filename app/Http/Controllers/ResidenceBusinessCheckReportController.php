<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientFolders\BatchResidenceBusinessCheckRequest;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\Reports\OfficialReportDataBuilder;
use App\Services\Reports\ReportDownloadName;
use App\Services\Reports\ResidenceBusinessCheckBatchDocxExporter;
use App\Services\Reports\ResidenceBusinessCheckBatchPdfExporter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResidenceBusinessCheckReportController extends Controller
{
    public function batchPreview(BatchResidenceBusinessCheckRequest $request, ClientFolder $clientFolder, OfficialReportDataBuilder $builder): View
    {
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $personName = $activePerson?->full_name ?? $clientFolder->display_name;
        $photoSections = $this->buildPhotoSections($request, $builder, $personName);
        abort_if($photoSections === [], 404);

        return view('reports.official.residence-business-check-batch', [
            'photoSections' => $photoSections,
            'pdfMode' => false,
            'title' => $this->reportTitle($photoSections, $personName),
            'clientFolder' => $clientFolder,
            'personParams' => ActivePersonResolver::queryParams($activePerson),
            // Carried through so the preview's own Download PDF / Download Word actions can re-post
            // the exact same selection to the existing export endpoints — no new routes, and the
            // person scope travels with it so a Co-Maker preview can only export that Co-Maker.
            'exportSelection' => [
                'co_maker_id' => $activePerson?->id,
                'residence_check_ids' => array_map('intval', $request->validated('residence_check_ids') ?? []),
                'business_check_ids' => array_map('intval', $request->validated('business_check_ids') ?? []),
            ],
        ]);
    }

    public function batchExportPdf(BatchResidenceBusinessCheckRequest $request, ClientFolder $clientFolder, OfficialReportDataBuilder $builder, ResidenceBusinessCheckBatchPdfExporter $exporter): StreamedResponse
    {
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $personName = $activePerson?->full_name ?? $clientFolder->display_name;
        $photoSections = $this->buildPhotoSections($request, $builder, $personName);
        abort_if($photoSections === [], 404);

        // Dompdf itself already degrades unreadable/unsupported images to a blank box rather than
        // throwing, but this still guards against any other unexpected renderer failure — the same
        // "report it, fail cleanly" contract GenerateOfficialReport uses for the versioned
        // single-report download, rather than surfacing a raw framework error page for this
        // disposable one.
        $bytes = $this->generateOrAbort(fn () => $exporter->generate($clientFolder, $photoSections, $this->reportTitle($photoSections, $personName)));
        $filename = $this->batchFilename($photoSections, $personName, 'pdf');
        $this->logBatchExport($request, $clientFolder, $activePerson, count($photoSections), 'pdf');

        return response()->streamDownload(
            static fn () => print $bytes,
            $filename,
            ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0'],
        );
    }

    public function batchExportDocx(BatchResidenceBusinessCheckRequest $request, ClientFolder $clientFolder, OfficialReportDataBuilder $builder, ResidenceBusinessCheckBatchDocxExporter $exporter): StreamedResponse
    {
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $personName = $activePerson?->full_name ?? $clientFolder->display_name;
        $photoSections = $this->buildPhotoSections($request, $builder, $personName);
        abort_if($photoSections === [], 404);

        // Only ever used as the DOCX file's own invisible document-properties metadata — never
        // rendered onto the page itself (see ResidenceBusinessCheckBatchDocxExporter::generate()'s
        // own docblock).
        $title = $this->reportTitle($photoSections, $personName);
        // BuildsOfficialReportDocx::embedImage() already recovers from an unembeddable/corrupt
        // photo on its own (falls back to "Image unavailable" text, converts WebP to PNG first),
        // so this is only a last-resort net against anything else unexpected in PhpWord's writer.
        $bytes = $this->generateOrAbort(fn () => $exporter->generate($photoSections, $title));
        $filename = $this->batchFilename($photoSections, $personName, 'docx');
        $this->logBatchExport($request, $clientFolder, $activePerson, count($photoSections), 'docx');

        return response()->streamDownload(
            static fn () => print $bytes,
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0'],
        );
    }

    /** @param  \Closure(): string  $generate */
    private function generateOrAbort(\Closure $generate): string
    {
        try {
            return $generate();
        } catch (\Throwable $exception) {
            report($exception);
            abort(500, 'The report could not be generated. Please retry or contact an administrator.');
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function buildPhotoSections(BatchResidenceBusinessCheckRequest $request, OfficialReportDataBuilder $builder, string $personName): array
    {
        $residenceSections = $request->resolveResidenceChecks()->map(fn ($check) => $builder->residenceCheckSection($check, $personName));
        $businessSections = $request->resolveBusinessChecks()->map(fn ($check) => $builder->businessCheckSection($check, $personName));

        return $residenceSections->concat($businessSections)->all();
    }

    /** @param  array<int, array<string, mixed>>  $photoSections */
    private function batchFilename(array $photoSections, string $personName, string $extension): string
    {
        $reportName = match ($this->selectedReportType($photoSections)) {
            'residence' => 'Residence',
            'business' => 'BusinessCheck',
            default => 'Checks',
        };

        return ReportDownloadName::make($reportName, $this->shortName($personName), $extension);
    }

    /** @param  array<int, array<string, mixed>>  $photoSections */
    private function reportTitle(array $photoSections, string $personName): string
    {
        $prefix = match ($this->selectedReportType($photoSections)) {
            'residence' => 'Residence Check',
            'business' => 'Business Checks',
            default => 'Residence & Business Checks',
        };

        return $prefix.' - '.$personName;
    }

    /** @param  array<int, array<string, mixed>>  $photoSections */
    private function selectedReportType(array $photoSections): string
    {
        $categories = collect($photoSections)->pluck('category')->unique()->values();

        return match (true) {
            $categories->all() === ['Residence'] => 'residence',
            $categories->all() === ['Business'] => 'business',
            default => 'mixed',
        };
    }

    private function shortName(string $personName): string
    {
        $asciiName = Str::ascii(trim($personName));
        $candidate = str_contains($asciiName, ',')
            ? Str::before($asciiName, ',')
            : collect(preg_split('/\s+/', $asciiName) ?: [])->filter()->take(2)->implode(' ');
        $safeName = Str::of($candidate)
            ->replaceMatches('/[^A-Za-z0-9]+/', '-')
            ->trim('-')
            ->limit(50, '')
            ->title()
            ->toString();

        return $safeName !== '' ? $safeName : 'Person';
    }

    private function logBatchExport(BatchResidenceBusinessCheckRequest $request, ClientFolder $clientFolder, ?CoMaker $activePerson, int $count, string $format): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'client_folder_id' => $clientFolder->id,
            'action' => 'residence_business_checks.batch_exported',
            'module' => 'residence_business_report',
            'description' => "A batch of {$count} Residence/Business Checks was exported to ".strtoupper($format).'.',
            'metadata' => ['co_maker_id' => $activePerson?->id, 'count' => $count, 'format' => $format],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
