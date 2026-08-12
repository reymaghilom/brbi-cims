<?php

namespace App\Http\Controllers;

use App\Actions\Reports\GenerateOfficialReport;
use App\Enums\GenerationStatus;
use App\Enums\OfficialReportType;
use App\Enums\ReportFormat;
use App\Http\Requests\ClientFolders\GenerateOfficialReportRequest;
use App\Http\Requests\ClientFolders\PreviewOfficialReportRequest;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Services\Reports\CibiExcelExporter;
use App\Services\Reports\OfficialReportDataBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GeneratedReportController extends Controller
{
    public function index(ClientFolder $clientFolder): View
    {
        Gate::authorize('view', $clientFolder);
        $clientFolder->load(['cibiReport:id,client_folder_id,state', 'residenceBusinessReport:id,client_folder_id', 'incomeSources' => fn ($query) => $query->with('template:id,name,is_fallback')->orderBy('sort_order')]);
        $reports = $clientFolder->generatedReports()->with(['incomeSource:id,source_name', 'generator:id,full_name'])->latest()->paginate(20);

        return view('client-folders.generated-reports.index', ['clientFolder' => $clientFolder, 'reports' => $reports, 'options' => $this->availableOptions($clientFolder)]);
    }

    public function preview(PreviewOfficialReportRequest $request, ClientFolder $clientFolder, OfficialReportDataBuilder $builder): View
    {
        $type = OfficialReportType::from($request->validated('report_type'));
        $source = $this->source($clientFolder, $request->validated('income_source_id'));
        $document = $builder->build($clientFolder, $type, $source);

        return view('reports.official.document', ['document' => $document, 'pdfMode' => false, 'clientFolder' => $clientFolder, 'type' => $type, 'source' => $source]);
    }

    public function store(GenerateOfficialReportRequest $request, ClientFolder $clientFolder, GenerateOfficialReport $generate): RedirectResponse
    {
        $type = OfficialReportType::from($request->validated('report_type'));
        $format = ReportFormat::from($request->validated('format'));
        $source = $this->source($clientFolder, $request->validated('income_source_id'));
        $report = $generate->execute($request->user(), $clientFolder, $type, $format, $source);

        return redirect()->route('client-folders.generated-reports.index', $clientFolder)->with(
            $report->status === GenerationStatus::Completed ? 'status' : 'error',
            $report->status === GenerationStatus::Completed ? 'Official report generated successfully.' : $report->failure_message,
        );
    }

    public function exportCibiPdf(ClientFolder $clientFolder, GenerateOfficialReport $generate): StreamedResponse
    {
        Gate::authorize('create', [GeneratedReport::class, $clientFolder]);
        abort_unless($clientFolder->cibiReport?->state?->value === 'complete', 422, 'Complete the CI / BI report before exporting it.');

        $report = $generate->execute(request()->user(), $clientFolder, OfficialReportType::Cibi, ReportFormat::Pdf);
        abort_unless($report->status === GenerationStatus::Completed, 500, $report->failure_message);

        return $this->downloadResponse($clientFolder, $report);
    }

    public function exportCibiExcel(ClientFolder $clientFolder, CibiExcelExporter $exporter): StreamedResponse
    {
        Gate::authorize('create', [GeneratedReport::class, $clientFolder]);
        abort_unless($clientFolder->cibiReport?->state?->value === 'complete', 422, 'Complete the CI / BI report before downloading Excel.');

        $bytes = $exporter->generate($clientFolder);
        $revision = $clientFolder->cibiReport->revision;
        $client = Str::of($clientFolder->display_name)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-');
        $filename = Str::limit("BRBI_{$clientFolder->folder_number}_{$client}_CI-BI-Report_v{$revision}", 180, '').'.xlsx';

        AuditLog::create([
            'user_id' => request()->user()->id,
            'client_folder_id' => $clientFolder->id,
            'action' => 'cibi_report.excel_downloaded',
            'module' => 'cibi_report',
            'description' => 'A saved CI / BI report was downloaded as Excel.',
            'metadata' => ['report_id' => $clientFolder->cibiReport->id, 'revision' => $revision],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->streamDownload(
            static fn () => print $bytes,
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }

    public function regenerate(ClientFolder $clientFolder, GeneratedReport $generatedReport, GenerateOfficialReport $generate): RedirectResponse
    {
        Gate::authorize('generate', $generatedReport);
        $source = $generatedReport->income_source_id ? $clientFolder->incomeSources()->findOrFail($generatedReport->income_source_id) : null;
        $report = $generate->execute(request()->user(), $clientFolder, OfficialReportType::from($generatedReport->report_type), $generatedReport->format, $source);

        return redirect()->route('client-folders.generated-reports.index', $clientFolder)->with(
            $report->status === GenerationStatus::Completed ? 'status' : 'error',
            $report->status === GenerationStatus::Completed ? 'A new report version was generated.' : $report->failure_message,
        );
    }

    public function download(ClientFolder $clientFolder, GeneratedReport $generatedReport): StreamedResponse
    {
        Gate::authorize('export', $generatedReport);

        return $this->downloadResponse($clientFolder, $generatedReport);
    }

    private function downloadResponse(ClientFolder $clientFolder, GeneratedReport $generatedReport): StreamedResponse
    {
        abort_unless($generatedReport->status === GenerationStatus::Completed && filled($generatedReport->private_file_reference), 404);
        $disk = Storage::disk(config('cims.report_disk'));
        abort_unless($disk->exists($generatedReport->private_file_reference), 404);
        AuditLog::create([
            'user_id' => request()->user()->id, 'client_folder_id' => $clientFolder->id, 'action' => 'generated_report.downloaded', 'module' => 'generated_reports',
            'description' => 'An official generated report was downloaded.',
            'metadata' => ['generated_report_id' => $generatedReport->id, 'report_type' => $generatedReport->report_type, 'format' => $generatedReport->format->value, 'version' => $generatedReport->version],
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
        ]);

        return $disk->download($generatedReport->private_file_reference, basename($generatedReport->private_file_reference), ['Content-Type' => $generatedReport->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function source(ClientFolder $folder, mixed $id): ?IncomeSource
    {
        return filled($id) ? $folder->incomeSources()->findOrFail((int) $id) : null;
    }

    private function availableOptions(ClientFolder $folder): array
    {
        $options = [];
        if ($folder->cibiReport?->state?->value === 'complete') {
            $options[] = ['type' => OfficialReportType::Cibi, 'source' => null, 'label' => OfficialReportType::Cibi->label()];
        }
        foreach ($folder->incomeSources as $source) {
            $type = $source->template->is_fallback ? OfficialReportType::GeneralIncomeSource : OfficialReportType::BusinessIncomeSource;
            $options[] = ['type' => $type, 'source' => $source, 'label' => $source->template->name.' — '.$source->source_name];
        }
        if ($folder->residenceBusinessReport) {
            $options[] = ['type' => OfficialReportType::ResidenceBusinessPhoto, 'source' => null, 'label' => OfficialReportType::ResidenceBusinessPhoto->label()];
        }

        return $options;
    }
}
