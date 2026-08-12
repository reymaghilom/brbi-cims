<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\OfficialReportType;
use App\Enums\ReportFormat;
use App\Models\GeneratedReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateOfficialReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [GeneratedReport::class, $this->route('clientFolder')]) ?? false;
    }

    public function rules(): array
    {
        return [
            'report_type' => ['required', Rule::enum(OfficialReportType::class)],
            'format' => ['required', Rule::enum(ReportFormat::class)],
            'income_source_id' => ['nullable', 'integer'],
        ];
    }
}
