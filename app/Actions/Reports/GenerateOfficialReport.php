<?php

namespace App\Actions\Reports;

use App\Enums\GenerationStatus;
use App\Enums\OfficialReportType;
use App\Enums\ReportFormat;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Services\ClientFolders\GeneratedReportsCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use App\Services\Reports\Contracts\DocxGenerator;
use App\Services\Reports\Contracts\PdfGenerator;
use App\Services\Reports\Data\ReportRenderOptions;
use App\Services\Reports\OfficialReportDataBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GenerateOfficialReport
{
    public function __construct(
        private readonly OfficialReportDataBuilder $dataBuilder,
        private readonly PdfGenerator $pdfGenerator,
        private readonly DocxGenerator $docxGenerator,
        private readonly GeneratedReportsCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
    ) {}

    public function execute(User $actor, ClientFolder $folder, OfficialReportType $type, ReportFormat $format, ?IncomeSource $source = null, ?CoMaker $activePerson = null): GeneratedReport
    {
        $data = $this->dataBuilder->build($folder, $type, $source, $activePerson);
        $template = ReportTemplate::query()->where('report_type', $type->value)->where('format', $format->value)->where('is_active', true)->latest('version')->firstOrFail();
        // Co-maker id is folded into the scope key (even for $source-bearing types, where it's
        // redundant-but-harmless since the source id already disambiguates) so that the Applicant
        // and every Co-Maker each get their own independent version-numbering sequence instead of
        // silently sharing — and colliding on — one another's.
        $scopeKey = 'folder:'.$folder->id.($activePerson ? ':co_maker:'.$activePerson->id : '').':'.$type->value.($source ? ':source:'.$source->id : '');

        $report = DB::transaction(function () use ($actor, $folder, $type, $format, $source, $activePerson, $template, $scopeKey, $data): GeneratedReport {
            ClientFolder::query()->whereKey($folder->id)->lockForUpdate()->firstOrFail();
            $version = (int) GeneratedReport::query()->where('scope_key', $scopeKey)->where('report_type', $type->value)->where('format', $format)->max('version') + 1;

            return GeneratedReport::create([
                'client_folder_id' => $folder->id, 'co_maker_id' => $activePerson?->id, 'income_source_id' => $source?->id, 'scope_key' => $scopeKey,
                'source_type' => $source ? 'income_source' : $type->value, 'source_id' => $source?->id,
                'report_type' => $type->value, 'format' => $format, 'version' => $version,
                'template_version' => $template->version, 'source_revision' => $data['source_revision'] ?? null,
                'source_snapshot' => ['client' => $data['client_name'] ?? $folder->display_name, 'folder_number' => $folder->folder_number, 'source_name' => $data['source_name'] ?? null, 'report_type' => $type->value],
                'status' => GenerationStatus::Processing, 'generated_by' => $actor->id,
            ]);
        });
        $this->audit($actor, $folder, 'generated_report.requested', 'Official report generation was requested.', $report);

        $filename = $this->filename($folder, $type, $format, $report->version, $source, $activePerson);
        // Source names can run very long (e.g. Remittance's preset category labels, such as
        // "Remittance Received from OFW, Foreigner, Allotment, Alimony, Allowance, Family
        // Sharing of Profits"), and an uncapped slug here previously produced a stored path
        // over private_file_reference's 255-char column limit — silently truncating the
        // Eloquent update and throwing the SAME error again from the catch block's own
        // recovery update() (since the oversized attribute stayed dirty on the model), which
        // surfaced as an uncaught 500 instead of a normal "generation failed" report.
        // The co-maker segment must be present here too (not just in scope_key/version numbering
        // above) — otherwise the Applicant's and a Co-Maker's reports of the same type/version
        // would resolve to the exact same storage path and silently overwrite one another on disk.
        $path = collect(['generated-reports', Str::slug($folder->folder_number), $type->value, $activePerson ? 'co-maker-'.$activePerson->id : null, $source ? Str::limit(Str::slug($source->source_name), 30, '') : null, 'v'.$report->version, $filename])->filter()->implode('/');

        try {
            $data['_artifact_path'] = $path;
            $options = new ReportRenderOptions((float) $template->paper_width_inches, (float) $template->paper_height_inches, $template->margins_inches ?: ['top' => .45, 'right' => .45, 'bottom' => .45, 'left' => .45]);
            $generator = $format === ReportFormat::Pdf ? $this->pdfGenerator : $this->docxGenerator;
            $artifact = $generator->generate('reports.official.document', $data, $options);
            $report->update([
                'status' => GenerationStatus::Completed, 'private_file_reference' => $artifact->path,
                'mime_type' => $artifact->mimeType, 'byte_size' => $artifact->sizeBytes, 'checksum' => $artifact->sha256,
                'generated_at' => now(), 'failure_code' => null, 'failure_message' => null,
            ]);
            $this->completion->evaluate($folder);
            $this->progress->recalculate($folder);
            $this->audit($actor, $folder, 'generated_report.completed', 'Official report generation completed.', $report);

            return $report->refresh();
        } catch (Throwable $exception) {
            Storage::disk(config('cims.report_disk'))->delete($path);
            $report->update(['status' => GenerationStatus::Failed, 'failure_code' => 'generation_failed', 'failure_message' => 'The report could not be generated. Please retry or contact an administrator.']);
            $this->audit($actor, $folder, 'generated_report.failed', 'Official report generation failed.', $report);
            report($exception);

            return $report->refresh();
        }
    }

    public function filename(ClientFolder $folder, OfficialReportType $type, ReportFormat $format, int $version, ?IncomeSource $source = null, ?CoMaker $activePerson = null): string
    {
        $parts = ['BRBI', $folder->folder_number, $activePerson?->full_name ?? $folder->display_name, $type->label(), $source?->source_name, 'v'.$version];
        $stem = collect($parts)->filter()->map(fn ($part) => Str::of($part)->ascii()->replaceMatches('/[^A-Za-z0-9]+/', '-')->trim('-'))->filter()->implode('_');

        // Capped well below the 255-char private_file_reference column limit — the directory
        // portion of the stored path (folder number, report type, and a separately-capped
        // source-name slug) also has to fit within that same budget.
        return Str::limit($stem, 100, '').'.'.$format->value;
    }

    private function audit(User $actor, ClientFolder $folder, string $action, string $description, GeneratedReport $report): void
    {
        AuditLog::create([
            'user_id' => $actor->id, 'client_folder_id' => $folder->id, 'action' => $action, 'module' => 'generated_reports', 'description' => $description,
            'metadata' => ['generated_report_id' => $report->id, 'report_type' => $report->report_type, 'format' => $report->format->value, 'version' => $report->version, 'income_source_id' => $report->income_source_id, 'status' => $report->status->value],
            'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
        ]);
    }
}
