<?php

namespace App\Services\Reports\Contracts;

use App\Services\Reports\Data\GeneratedReportArtifact;
use App\Services\Reports\Data\ReportRenderOptions;

interface ReportGenerator
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function generate(
        string $template,
        array $data,
        ReportRenderOptions $options,
    ): GeneratedReportArtifact;
}
