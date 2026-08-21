<?php

namespace App\Http\Controllers;

use App\Actions\Reports\GenerateOfficialReport;
use App\Enums\GenerationStatus;
use App\Enums\OfficialReportType;
use App\Enums\ReportFormat;
use App\Http\Requests\ClientFolders\BatchBusinessReportRequest;
use App\Http\Requests\ClientFolders\GenerateOfficialReportRequest;
use App\Http\Requests\ClientFolders\PreviewOfficialReportRequest;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\Reports\BusinessBatchPdfExporter;
use App\Services\Reports\BusinessExcelExporter;
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
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $clientFolder->load([
            'cibiReport' => fn ($query) => $query->where('co_maker_id', $activePerson?->id)->select('id', 'client_folder_id', 'co_maker_id', 'state'),
            'incomeSources' => fn ($query) => $query->where('co_maker_id', $activePerson?->id)->with('template:id,name,is_fallback')->orderBy('sort_order'),
        ]);
        $clientFolder->loadCount([
            'residenceChecks' => fn ($query) => $query->where('co_maker_id', $activePerson?->id),
            'businessChecks' => fn ($query) => $query->where('co_maker_id', $activePerson?->id),
        ]);
        $reports = $clientFolder->generatedReports()->where('co_maker_id', $activePerson?->id)->with(['incomeSource:id,source_name', 'generator:id,full_name'])->latest()->paginate(20)->withQueryString();

        return view('client-folders.generated-reports.index', ['clientFolder' => $clientFolder, 'activePerson' => $activePerson, 'reports' => $reports, 'options' => $this->availableOptions($clientFolder)]);
    }

    public function preview(PreviewOfficialReportRequest $request, ClientFolder $clientFolder, OfficialReportDataBuilder $builder): View
    {
        $type = OfficialReportType::from($request->validated('report_type'));
        $source = $this->source($clientFolder, $request->validated('income_source_id'));
        $activePerson = $this->activePersonFor($clientFolder, $source, $request->validated('co_maker_id'));
        $document = $builder->build($clientFolder, $type, $source, $activePerson);

        return view('reports.official.document', ['document' => $document, 'pdfMode' => false, 'clientFolder' => $clientFolder, 'type' => $type, 'source' => $source]);
    }

    public function store(GenerateOfficialReportRequest $request, ClientFolder $clientFolder, GenerateOfficialReport $generate): RedirectResponse
    {
        $type = OfficialReportType::from($request->validated('report_type'));
        $format = ReportFormat::from($request->validated('format'));
        $source = $this->source($clientFolder, $request->validated('income_source_id'));
        $activePerson = $this->activePersonFor($clientFolder, $source, $request->validated('co_maker_id'));
        $report = $generate->execute($request->user(), $clientFolder, $type, $format, $source, $activePerson);
        $personParams = ActivePersonResolver::queryParams($activePerson);

        return redirect()->route('client-folders.generated-reports.index', [$clientFolder] + $personParams)->with(
            $report->status === GenerationStatus::Completed ? 'status' : 'error',
            $report->status === GenerationStatus::Completed ? 'Official report generated successfully.' : $report->failure_message,
        );
    }

    public function exportCibiPdf(ClientFolder $clientFolder, GenerateOfficialReport $generate): StreamedResponse
    {
        Gate::authorize('create', [GeneratedReport::class, $clientFolder]);
        $activePerson = ActivePersonResolver::resolve($clientFolder, request()->input('co_maker_id'));
        abort_unless($clientFolder->cibiReport()->where('co_maker_id', $activePerson?->id)->value('state') === 'complete', 422, 'Complete the CI / BI report before exporting it.');

        $report = $generate->execute(request()->user(), $clientFolder, OfficialReportType::Cibi, ReportFormat::Pdf, null, $activePerson);
        abort_unless($report->status === GenerationStatus::Completed, 500, $report->failure_message);

        return $this->downloadResponse($clientFolder, $report);
    }

    public function exportBusinessPdf(ClientFolder $clientFolder, IncomeSource $incomeSource, GenerateOfficialReport $generate): StreamedResponse
    {
        Gate::authorize('view', $incomeSource);
        Gate::authorize('create', [GeneratedReport::class, $clientFolder]);
        $activePerson = $incomeSource->co_maker_id ? $clientFolder->coMakers()->find($incomeSource->co_maker_id) : null;
        $incomeSource->loadMissing('template');
        $type = $incomeSource->template->is_fallback ? OfficialReportType::GeneralIncomeSource : OfficialReportType::BusinessIncomeSource;

        $report = $generate->execute(request()->user(), $clientFolder, $type, ReportFormat::Pdf, $incomeSource, $activePerson);
        abort_unless($report->status === GenerationStatus::Completed, 500, $report->failure_message);

        return $this->downloadResponse($clientFolder, $report);
    }

    public function exportBusinessExcel(ClientFolder $clientFolder, IncomeSource $incomeSource, BusinessExcelExporter $exporter): StreamedResponse
    {
        Gate::authorize('view', $incomeSource);
        Gate::authorize('create', [GeneratedReport::class, $clientFolder]);
        $incomeSource->loadMissing('template');
        abort_if($incomeSource->template->is_fallback, 404);

        $bytes = $exporter->generate($clientFolder, $incomeSource);
        $client = Str::of($incomeSource->applicant_name_snapshot ?: $clientFolder->display_name)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-');
        $business = Str::of($incomeSource->source_name ?: $incomeSource->template->name)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-');
        $filename = Str::limit("BRBI_{$clientFolder->folder_number}_{$client}_{$business}_Business-Report_r{$incomeSource->revision}", 180, '').'.xlsx';

        AuditLog::create([
            'user_id' => request()->user()->id,
            'client_folder_id' => $clientFolder->id,
            'action' => 'income_source.excel_downloaded',
            'module' => 'income_sources',
            'description' => 'A saved Business Report was downloaded as Excel.',
            'metadata' => ['income_source_id' => $incomeSource->id, 'template_type' => $incomeSource->template_type, 'revision' => $incomeSource->revision],
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

    /** "Print Selected" — a combined, print-ready HTML preview for however many businesses were checked, in the order they were checked. */
    public function batchPreview(BatchBusinessReportRequest $request, ClientFolder $clientFolder, OfficialReportDataBuilder $builder): View
    {
        $sources = $request->resolveSources();
        abort_if($sources->isEmpty(), 404);
        $sources->each(fn (IncomeSource $source) => Gate::authorize('view', $source));

        $documents = $sources->map(fn (IncomeSource $source) => $builder->build($clientFolder, OfficialReportType::BusinessIncomeSource, $source))->all();
        $personParams = ActivePersonResolver::queryParams(ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id')));

        return view('reports.official.business-batch', [
            'documents' => $documents,
            'pdfMode' => false,
            'title' => 'Business Reports - '.$clientFolder->display_name,
            'clientFolder' => $clientFolder,
            'personParams' => $personParams,
        ]);
    }

    /** "Download Selected" → PDF — same combined layout as batchPreview(), rendered to an actual file via Dompdf. Deliberately not routed through GenerateOfficialReport: see BusinessBatchPdfExporter's own docblock. */
    public function batchExportPdf(BatchBusinessReportRequest $request, ClientFolder $clientFolder, BusinessBatchPdfExporter $exporter): StreamedResponse
    {
        Gate::authorize('create', [GeneratedReport::class, $clientFolder]);
        $sources = $request->resolveSources();
        abort_if($sources->isEmpty(), 404);
        $sources->each(fn (IncomeSource $source) => Gate::authorize('view', $source));

        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $bytes = $exporter->generate($clientFolder, $sources);
        $filename = $this->batchFilename($clientFolder, $activePerson, $sources->count(), 'pdf');

        AuditLog::create([
            'user_id' => request()->user()->id,
            'client_folder_id' => $clientFolder->id,
            'action' => 'income_source.batch_pdf_downloaded',
            'module' => 'income_sources',
            'description' => 'A combined batch of saved Business Reports was downloaded as PDF.',
            'metadata' => ['income_source_ids' => $sources->pluck('id')->all(), 'count' => $sources->count()],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return response()->streamDownload(
            static fn () => print $bytes,
            $filename,
            ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0'],
        );
    }

    /** "Download Selected" → Excel — one workbook, one worksheet per selected business; see BusinessExcelExporter::generateBatch(). */
    public function batchExportExcel(BatchBusinessReportRequest $request, ClientFolder $clientFolder, BusinessExcelExporter $exporter): StreamedResponse
    {
        Gate::authorize('create', [GeneratedReport::class, $clientFolder]);
        $sources = $request->resolveSources();
        abort_if($sources->isEmpty(), 404);
        $sources->each(fn (IncomeSource $source) => Gate::authorize('view', $source));

        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $bytes = $exporter->generateBatch($clientFolder, $sources);
        $filename = $this->batchFilename($clientFolder, $activePerson, $sources->count(), 'xlsx');

        AuditLog::create([
            'user_id' => request()->user()->id,
            'client_folder_id' => $clientFolder->id,
            'action' => 'income_source.batch_excel_downloaded',
            'module' => 'income_sources',
            'description' => 'A combined batch of saved Business Reports was downloaded as Excel.',
            'metadata' => ['income_source_ids' => $sources->pluck('id')->all(), 'count' => $sources->count()],
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

    private function batchFilename(ClientFolder $clientFolder, ?CoMaker $activePerson, int $count, string $extension): string
    {
        $client = Str::of($activePerson?->full_name ?? $clientFolder->display_name)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-');

        return Str::limit("BRBI_{$clientFolder->folder_number}_{$client}_Business-Reports-Batch-{$count}", 180, '').'.'.$extension;
    }

    public function exportCibiExcel(ClientFolder $clientFolder, CibiExcelExporter $exporter): StreamedResponse
    {
        Gate::authorize('create', [GeneratedReport::class, $clientFolder]);
        $activePerson = ActivePersonResolver::resolve($clientFolder, request()->input('co_maker_id'));
        $report = $clientFolder->cibiReport()->where('co_maker_id', $activePerson?->id)->first();
        abort_unless($report?->state?->value === 'complete', 422, 'Complete the CI / BI report before downloading Excel.');

        $bytes = $exporter->generate($clientFolder, $activePerson);
        $client = Str::of($activePerson?->full_name ?? $clientFolder->display_name)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-');
        $filename = Str::limit("BRBI_{$clientFolder->folder_number}_{$client}_CI-BI-Report_v{$report->revision}", 180, '').'.xlsx';

        AuditLog::create([
            'user_id' => request()->user()->id,
            'client_folder_id' => $clientFolder->id,
            'action' => 'cibi_report.excel_downloaded',
            'module' => 'cibi_report',
            'description' => 'A saved CI / BI report was downloaded as Excel.',
            'metadata' => ['report_id' => $report->id, 'revision' => $report->revision],
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
        $activePerson = $generatedReport->co_maker_id ? $clientFolder->coMakers()->find($generatedReport->co_maker_id) : null;
        $report = $generate->execute(request()->user(), $clientFolder, OfficialReportType::from($generatedReport->report_type), $generatedReport->format, $source, $activePerson);
        $personParams = ActivePersonResolver::queryParams($activePerson);

        return redirect()->route('client-folders.generated-reports.index', [$clientFolder] + $personParams)->with(
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

    // When a source is given, its own co_maker_id is authoritative — the same pattern used by
    // exportBusinessPdf()/regenerate() — so a mismatched co_maker_id submitted alongside it can
    // never tag the resulting GeneratedReport (or, for CI/BI-style types, the report content
    // itself) as belonging to a different person than the source actually does.
    private function activePersonFor(ClientFolder $folder, ?IncomeSource $source, mixed $rawCoMakerId): ?CoMaker
    {
        return $source ? ActivePersonResolver::resolve($folder, $source->co_maker_id) : ActivePersonResolver::resolve($folder, $rawCoMakerId);
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
        if ($folder->residence_checks_count > 0 || $folder->business_checks_count > 0) {
            $options[] = ['type' => OfficialReportType::ResidenceBusinessPhoto, 'source' => null, 'label' => OfficialReportType::ResidenceBusinessPhoto->label()];
        }

        return $options;
    }
}
