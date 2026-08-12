<?php

namespace App\Http\Requests\ClientFolders;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateGeneralIncomeSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $folder = $this->route('clientFolder');
        $source = $this->route('incomeSource');

        return $source?->client_folder_id === $folder->id && $source->template->is_fallback && $this->user()->can('update', $source);
    }

    public function rules(): array
    {
        $complete = $this->input('intent') === 'complete';

        return [
            'intent' => ['required', Rule::in(['stay', 'return', 'complete'])],
            'source_name' => ['required', 'string', 'max:255'],
            'applicant_name_snapshot' => [Rule::requiredIf($complete), 'nullable', 'string', 'max:255'],
            'branch_name' => [Rule::requiredIf($complete), 'nullable', 'string', 'max:255'],
            'amount_applied' => [Rule::requiredIf($complete), 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'account_officer_name' => [Rule::requiredIf($complete), 'nullable', 'string', 'max:255'],
            'general_remarks' => ['nullable', 'string', 'max:30000'],
            'items' => ['array', 'max:50'],
            'items.*' => ['array:id,_delete,source_name,source_type,description,amount_contribution,contribution_rank,remarks'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*._delete' => ['nullable', 'boolean'],
            'items.*.source_name' => ['nullable', 'string', 'max:255'],
            'items.*.source_type' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:10000'],
            'items.*.amount_contribution' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'items.*.contribution_rank' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'items.*.remarks' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedIds = $this->route('incomeSource')->generalReport?->declaredItems()->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [];
            $ranks = [];
            $activeItems = 0;
            foreach ((array) $this->input('items', []) as $index => $item) {
                $delete = filter_var($item['_delete'] ?? false, FILTER_VALIDATE_BOOL);
                $id = filled($item['id'] ?? null) ? (int) $item['id'] : null;
                if ($id !== null && ! in_array($id, $allowedIds, true)) {
                    $validator->errors()->add("items.$index.id", 'The selected item does not belong to this fallback report.');
                }
                if (! $delete && collect($item)->except(['id', '_delete'])->contains(fn ($value): bool => filled($value))) {
                    $activeItems++;
                    if (! filled($item['source_name'] ?? null)) {
                        $validator->errors()->add("items.$index.source_name", 'The source name is required for a populated row.');
                    }
                    if (! filled($item['contribution_rank'] ?? null)) {
                        $validator->errors()->add("items.$index.contribution_rank", 'A contribution rank is required for a populated row.');
                    } elseif (in_array((int) $item['contribution_rank'], $ranks, true)) {
                        $validator->errors()->add("items.$index.contribution_rank", 'Contribution ranks must be unique within this report.');
                    } else {
                        $ranks[] = (int) $item['contribution_rank'];
                    }
                }
            }
            if ($this->input('intent') === 'complete' && ($activeItems === 0 || ! in_array(1, $ranks, true))) {
                $validator->errors()->add('items', 'A completed fallback report requires declared sources including rank 1.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['source_name', 'applicant_name_snapshot', 'branch_name', 'account_officer_name'] as $field) {
            $data[$field] = $this->short($this->input($field));
        }
        $data['general_remarks'] = $this->text($this->input('general_remarks'));
        $data['items'] = collect((array) $this->input('items', []))->map(fn ($row) => collect((array) $row)->map(fn ($value, $key) => in_array($key, ['description', 'remarks'], true) ? $this->text($value) : ($key === '_delete' ? filter_var($value, FILTER_VALIDATE_BOOL) : (is_string($value) ? $this->short($value) : $value)))->all())->values()->all();
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
