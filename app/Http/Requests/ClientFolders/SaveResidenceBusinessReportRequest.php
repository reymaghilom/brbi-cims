<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\PhotoSectionCategory;
use App\Models\ResidenceBusinessReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveResidenceBusinessReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $folder = $this->route('clientFolder');
        $report = $folder->residenceBusinessReport;

        return $report
            ? $this->user()->can('update', $report)
            : $this->user()->can('create', [ResidenceBusinessReport::class, $folder]);
    }

    public function rules(): array
    {
        return [
            'intent' => ['required', Rule::in(['stay', 'return', 'complete'])],
            'report_date' => ['nullable', 'date', 'before_or_equal:today'],
            'default_location' => ['nullable', 'string', 'max:10000'],
            'default_subject' => ['nullable', 'string', 'max:255'],
            'residence_remarks' => ['nullable', 'string', 'max:30000'],
            'business_remarks' => ['nullable', 'string', 'max:30000'],
            'sections' => ['array', 'max:30'],
            'sections.*' => ['array:id,_delete,category,subject_party,subject_name,heading_subject,location,business_name,income_source_id,google_maps_link,remarks,sort_order,media'],
            'sections.*.id' => ['nullable', 'integer'],
            'sections.*._delete' => ['nullable', 'boolean'],
            'sections.*.category' => ['required', Rule::enum(PhotoSectionCategory::class)],
            'sections.*.subject_party' => ['required', Rule::in(['applicant', 'co_maker', 'other'])],
            'sections.*.subject_name' => ['nullable', 'string', 'max:255'],
            'sections.*.heading_subject' => ['nullable', 'string', 'max:255'],
            'sections.*.location' => ['nullable', 'string', 'max:10000'],
            'sections.*.business_name' => ['nullable', 'string', 'max:255'],
            'sections.*.income_source_id' => ['nullable', 'integer'],
            'sections.*.google_maps_link' => ['nullable', 'url:http,https', 'max:2048'],
            'sections.*.remarks' => ['nullable', 'string', 'max:30000'],
            'sections.*.sort_order' => ['required', 'integer', 'min:1', 'max:1000'],
            'sections.*.media' => ['array', 'max:100'],
            'sections.*.media.*' => ['array:media_reference_id,selected,output_label,caption,sort_order'],
            'sections.*.media.*.media_reference_id' => ['required', 'integer'],
            'sections.*.media.*.selected' => ['nullable', 'boolean'],
            'sections.*.media.*.output_label' => ['nullable', 'string', 'max:255'],
            'sections.*.media.*.caption' => ['nullable', 'string', 'max:10000'],
            'sections.*.media.*.sort_order' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $folder = $this->route('clientFolder');
            $report = $folder->residenceBusinessReport;
            $allowedSectionIds = $report?->sections()->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [];
            $allowedSources = $folder->incomeSources()->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $media = $folder->mediaReferences()->get(['id', 'income_source_id', 'category'])->keyBy('id');
            $complete = $this->input('intent') === 'complete';
            $activeSections = 0;
            $residenceSections = 0;
            $sectionOrders = [];

            if ($complete && ! filled($this->input('report_date'))) {
                $validator->errors()->add('report_date', 'The report date is required before marking the report complete.');
            }

            foreach ((array) $this->input('sections', []) as $index => $section) {
                $delete = filter_var($section['_delete'] ?? false, FILTER_VALIDATE_BOOL);
                $id = filled($section['id'] ?? null) ? (int) $section['id'] : null;
                if ($id !== null && ! in_array($id, $allowedSectionIds, true)) {
                    $validator->errors()->add("sections.$index.id", 'The section does not belong to this report.');
                }
                if ($delete) {
                    continue;
                }
                $activeSections++;
                $category = (string) ($section['category'] ?? '');
                if ($category === PhotoSectionCategory::Residence->value) {
                    $residenceSections++;
                }
                $order = (int) ($section['sort_order'] ?? 0);
                if (in_array($order, $sectionOrders, true)) {
                    $validator->errors()->add("sections.$index.sort_order", 'Section order values must be unique.');
                }
                $sectionOrders[] = $order;

                $sourceId = filled($section['income_source_id'] ?? null) ? (int) $section['income_source_id'] : null;
                if ($sourceId !== null && ! in_array($sourceId, $allowedSources, true)) {
                    $validator->errors()->add("sections.$index.income_source_id", 'The selected income source does not belong to this client folder.');
                }
                if ($category !== PhotoSectionCategory::Business->value && $sourceId !== null) {
                    $validator->errors()->add("sections.$index.income_source_id", 'Only a Business section may link an income source.');
                }
                if ($category !== PhotoSectionCategory::Business->value && filled($section['business_name'] ?? null)) {
                    $validator->errors()->add("sections.$index.business_name", 'Only a Business section may contain a business name.');
                }
                if ($category === PhotoSectionCategory::Business->value && $complete && $sourceId === null) {
                    $validator->errors()->add("sections.$index.income_source_id", 'A completed Business section must identify its income source.');
                }
                if (in_array($section['subject_party'] ?? null, ['co_maker', 'other'], true) && $complete && ! filled($section['subject_name'] ?? null)) {
                    $validator->errors()->add("sections.$index.subject_name", 'Enter the name of this report subject.');
                }

                $selectedMedia = [];
                $mediaOrders = [];
                foreach ((array) ($section['media'] ?? []) as $mediaIndex => $item) {
                    if (! filter_var($item['selected'] ?? false, FILTER_VALIDATE_BOOL)) {
                        continue;
                    }
                    $mediaId = (int) ($item['media_reference_id'] ?? 0);
                    $reference = $media->get($mediaId);
                    if ($reference === null) {
                        $validator->errors()->add("sections.$index.media.$mediaIndex.media_reference_id", 'The selected media does not belong to this client folder.');

                        continue;
                    }
                    if (in_array($mediaId, $selectedMedia, true)) {
                        $validator->errors()->add("sections.$index.media.$mediaIndex.media_reference_id", 'The same media cannot be linked twice in one section.');
                    }
                    $selectedMedia[] = $mediaId;
                    if ($reference->category->value !== $category) {
                        $validator->errors()->add("sections.$index.media.$mediaIndex.media_reference_id", 'The media category does not match this report section.');
                    }
                    if ($reference->income_source_id !== null && ($category !== PhotoSectionCategory::Business->value || (int) $reference->income_source_id !== $sourceId)) {
                        $validator->errors()->add("sections.$index.media.$mediaIndex.media_reference_id", 'This media belongs to a different income-source context.');
                    }
                    $mediaOrder = (int) ($item['sort_order'] ?? 0);
                    if ($mediaOrder < 1 || in_array($mediaOrder, $mediaOrders, true)) {
                        $validator->errors()->add("sections.$index.media.$mediaIndex.sort_order", 'Selected media must have a unique positive output order.');
                    }
                    $mediaOrders[] = $mediaOrder;
                }

                if ($complete) {
                    foreach (['heading_subject' => 'subject heading', 'location' => 'location'] as $field => $label) {
                        if (! filled($section[$field] ?? null)) {
                            $validator->errors()->add("sections.$index.$field", "The {$label} is required before marking the report complete.");
                        }
                    }
                    if ($category === PhotoSectionCategory::Business->value && ! filled($section['business_name'] ?? null)) {
                        $validator->errors()->add("sections.$index.business_name", 'The business name is required before marking the report complete.');
                    }
                    if ($selectedMedia === []) {
                        $validator->errors()->add("sections.$index.media", 'Select at least one existing media reference for a completed section.');
                    }
                }
            }

            if ($complete && ($activeSections === 0 || $residenceSections === 0)) {
                $validator->errors()->add('sections', 'A completed report requires at least one Residence section.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['default_location', 'default_subject'] as $field) {
            $data[$field] = $this->short($this->input($field));
        }
        foreach (['residence_remarks', 'business_remarks'] as $field) {
            $data[$field] = $this->text($this->input($field));
        }
        $data['sections'] = collect((array) $this->input('sections', []))->map(function ($section): array {
            $section = (array) $section;
            foreach (['subject_name', 'heading_subject', 'business_name'] as $field) {
                $section[$field] = $this->short($section[$field] ?? null);
            }
            foreach (['location', 'google_maps_link', 'remarks'] as $field) {
                $section[$field] = $this->text($section[$field] ?? null);
            }
            $section['_delete'] = filter_var($section['_delete'] ?? false, FILTER_VALIDATE_BOOL);
            $section['media'] = collect((array) ($section['media'] ?? []))->map(function ($item): array {
                $item = (array) $item;
                $item['selected'] = filter_var($item['selected'] ?? false, FILTER_VALIDATE_BOOL);
                $item['output_label'] = $this->short($item['output_label'] ?? null);
                $item['caption'] = $this->text($item['caption'] ?? null);

                return $item;
            })->values()->all();

            return $section;
        })->values()->all();
        $this->merge($data);
    }

    private function short(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value === null ? null : preg_replace('/\s+/u', ' ', $value);
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) || is_numeric($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
