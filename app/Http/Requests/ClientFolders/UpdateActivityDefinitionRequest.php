<?php

namespace App\Http\Requests\ClientFolders;

use App\Models\ActivityDefinition;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateActivityDefinitionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    if (ActivityDefinition::isDedicatedModuleName($value)) {
                        $fail('Residence Check and Business Check use their dedicated Client Folder modules.');

                        return;
                    }

                    if (ActivityDefinition::isCanonicalBuiltInName($value)) {
                        $fail('This name is reserved for a built-in Activity Type.');

                        return;
                    }

                    // Identity is the definition's own id — a rename may keep its current name
                    // (including casing/spacing changes), but must never collide with another one.
                    $existing = ActivityDefinition::equivalentToName($value);
                    if ($existing && $existing->id !== $this->route('activityDefinition')?->id) {
                        $fail('An activity type with this name already exists.');
                    }
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => ActivityDefinition::normalizeName($this->input('name'))]);
        }
    }
}
