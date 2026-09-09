<?php

namespace App\Http\Requests\ClientFolders;

use App\Models\CustomBusinessCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Add / rename a custom "Other Business / Source of Income" checkbox option.
 *
 * The only rule beyond a present name is that the catalog must not end up offering the same option
 * twice: a custom name may not repeat another custom category's name, nor any DEFAULT catalog label
 * from config/business-report-templates.php. Comparison is trimmed and case-insensitive.
 *
 * This is deliberately about the checkbox list alone. A category is still perfectly allowed to
 * share its name with a dedicated Business Template — the two lists coexist by design.
 */
class SaveCustomBusinessCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Please enter a business name.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('name')) {
                    return;
                }

                $incoming = CustomBusinessCategory::normalizeName($this->input('name'));
                if ($incoming === '') {
                    return;
                }

                if (in_array($incoming, $this->defaultCatalogNames(), true)) {
                    $validator->errors()->add('name', 'That business is already one of the default options.');

                    return;
                }

                // The category being renamed is excluded, so re-saving its own name is not a clash.
                $clash = CustomBusinessCategory::query()
                    ->when($this->route('customBusinessCategory'), fn ($query, $current) => $query->whereKeyNot($current->getKey()))
                    ->get(['id', 'name'])
                    ->contains(fn (CustomBusinessCategory $category): bool => CustomBusinessCategory::normalizeName($category->name) === $incoming);

                if ($clash) {
                    $validator->errors()->add('name', 'That business is already in the list.');
                }
            },
        ];
    }

    /** Every default catalog label, normalized the same way. */
    private function defaultCatalogNames(): array
    {
        return collect(config('business-report-templates.other_business_source_of_income.schema.income_source_groups', []))
            ->flatten(1)
            ->map(fn (array $choice): string => CustomBusinessCategory::normalizeName($choice['label'] ?? null))
            ->all();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
