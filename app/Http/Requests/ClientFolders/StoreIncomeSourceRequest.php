<?php

namespace App\Http\Requests\ClientFolders;

use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreIncomeSourceRequest extends UpdateBusinessIncomeSourceRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [IncomeSource::class, $this->route('clientFolder')]);
    }

    public function rules(): array
    {
        return parent::rules() + [
            'income_source_template_id' => ['required', 'integer', Rule::exists('income_source_templates', 'id')->where(fn ($query) => $query
                ->where('is_active', true)
                ->where('is_fallback', false)
                ->where('form_handler', 'dedicated-business'))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->hasAny(['start_date', 'submitted_date'])
                && filled($this->input('start_date'))
                && filled($this->input('submitted_date'))
                && strtotime((string) $this->input('submitted_date')) < strtotime((string) $this->input('start_date'))) {
                $validator->errors()->add('submitted_date', 'The Business Report submission date must be on or after its start date.');
            }

            $template = IncomeSourceTemplate::query()->find($this->integer('income_source_template_id'));
            if (! $template) {
                return;
            }
            $this->validateTemplateData($validator, $template->businessReportSchema());
            $compatible = $template->businessReportSchema() !== [] ? [] : ($template->compatibility_tags ?? []);
            foreach (['properties', 'tenants', 'branches', 'products', 'suppliers', 'observations', 'competitors'] as $section) {
                if (! in_array($section, $compatible, true) && collect((array) $this->input($section))->flatten()->filter(fn ($value) => filled($value))->isNotEmpty()) {
                    $validator->errors()->add($section, 'This section is not compatible with the selected Business Template.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $template = IncomeSourceTemplate::query()->find($this->integer('income_source_template_id'));
        $sourceName = $this->input('source_name');
        $this->merge([
            'intent' => $this->input('intent', 'stay'),
            'source_name' => filled($sourceName) ? $sourceName : $this->input('business_name'),
            'business_name' => filled($this->input('business_name')) ? $this->input('business_name') : $sourceName,
            'report_category' => filled($this->input('report_category')) ? $this->input('report_category') : ($template?->business_category ?: $template?->name),
        ]);
    }
}
