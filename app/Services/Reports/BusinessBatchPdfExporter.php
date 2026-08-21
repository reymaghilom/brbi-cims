<?php

namespace App\Services\Reports;

use App\Enums\OfficialReportType;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Services\Reports\Data\ReportRenderOptions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;

/**
 * Renders one combined, print-ready PDF for a batch of selected Business Reports — the "Download
 * Selected" convenience download on the Business / Income Sources list. Deliberately bypasses
 * GenerateOfficialReport's versioned GeneratedReport/disk-storage machinery (that system numbers
 * and audits one official report per income source at a time, which doesn't fit a combined,
 * disposable, non-versioned download); this mirrors BusinessExcelExporter's own direct/streamed
 * pattern already used for the equivalent per-row "Download Excel" button.
 */
class BusinessBatchPdfExporter
{
    public function __construct(private readonly OfficialReportDataBuilder $dataBuilder) {}

    /** @param  Collection<int, IncomeSource>  $sources */
    public function generate(ClientFolder $folder, Collection $sources): string
    {
        $documents = $sources
            ->map(fn (IncomeSource $source) => $this->dataBuilder->build($folder, OfficialReportType::BusinessIncomeSource, $source))
            ->all();

        $html = view('reports.official.business-batch', [
            'documents' => $documents,
            'pdfMode' => true,
            'title' => 'Business Reports - '.$folder->display_name,
            'clientFolder' => $folder,
            'personParams' => [],
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('chroot', [storage_path('app/private'), public_path()]);
        // Same fix as DompdfOfficialReportGenerator: without this, Dompdf applies the preview-only
        // `@media screen` rules (meant for the on-screen browser view) to the PDF too.
        $options->set('defaultMediaType', 'print');

        $render = ReportRenderOptions::brbiDefault();
        $dompdf = new Dompdf($options);
        $dompdf->setPaper([0, 0, $render->widthInches * 72, $render->heightInches * 72]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }
}
