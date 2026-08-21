<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientFolders\BatchResidenceBusinessCheckRequest;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\Reports\OfficialReportDataBuilder;
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
            'title' => 'Residence & Business Checks - '.$clientFolder->display_name,
            'clientFolder' => $clientFolder,
            'personParams' => ActivePersonResolver::queryParams($activePerson),
        ]);
    }

    public function batchExportPdf(BatchResidenceBusinessCheckRequest $request, ClientFolder $clientFolder, OfficialReportDataBuilder $builder, ResidenceBusinessCheckBatchPdfExporter $exporter): StreamedResponse
    {
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $personName = $activePerson?->full_name ?? $clientFolder->display_name;
        $photoSections = $this->buildPhotoSections($request, $builder, $personName);
        abort_if($photoSections === [], 404);

        $bytes = $exporter->generate($clientFolder, $photoSections, 'Residence & Business Checks - '.$clientFolder->display_name);
        $filename = $this->batchFilename($clientFolder, $activePerson, count($photoSections), 'pdf');
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

        $title = 'RESIDENCE & BUSINESS CHECKS';
        $bytes = $exporter->generate($photoSections, $title, 'BRBI Credit Investigation Management System');
        $filename = $this->batchFilename($clientFolder, $activePerson, count($photoSections), 'docx');
        $this->logBatchExport($request, $clientFolder, $activePerson, count($photoSections), 'docx');

        return response()->streamDownload(
            static fn () => print $bytes,
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0'],
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function buildPhotoSections(BatchResidenceBusinessCheckRequest $request, OfficialReportDataBuilder $builder, string $personName): array
    {
        $residenceSections = $request->resolveResidenceChecks()->map(fn ($check) => $builder->residenceCheckSection($check, $personName));
        $businessSections = $request->resolveBusinessChecks()->map(fn ($check) => $builder->businessCheckSection($check, $personName));

        return $residenceSections->concat($businessSections)->all();
    }

    private function batchFilename(ClientFolder $clientFolder, ?CoMaker $activePerson, int $count, string $extension): string
    {
        $client = Str::of($activePerson?->full_name ?? $clientFolder->display_name)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-');

        return Str::limit("BRBI_{$clientFolder->folder_number}_{$client}_Residence-Business-Checks-Batch-{$count}", 180, '').'.'.$extension;
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
