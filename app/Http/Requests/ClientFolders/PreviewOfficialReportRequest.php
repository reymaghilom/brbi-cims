<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\OfficialReportType;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewOfficialReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('clientFolder')) ?? false;
    }

    public function rules(): array
    {
        return [
            'report_type' => ['required', Rule::enum(OfficialReportType::class)],
            'income_source_id' => ['nullable', 'integer'],
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
        ];
    }
}
