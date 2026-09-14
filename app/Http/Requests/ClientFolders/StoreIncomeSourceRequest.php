<?php

namespace App\Http\Requests\ClientFolders;

use App\Actions\ClientFolders\BusinessReportDuplicateGuard;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreIncomeSourceRequest extends UpdateBusinessIncomeSourceRequest
{
    /** Error key for the Other Business duplicate-category failure, rendered at the top of the form. */
    public const DUPLICATE_CATEGORIES_KEY = BusinessReportDuplicateGuard::COMBINATION_KEY;

    public function authorize(): bool
    {
        return $this->user()->can('create', [IncomeSource::class, $this->route('clientFolder')]);
    }

    public function rules(): array
    {
        $rules = parent::rules() + [
            'income_source_template_id' => ['required', 'integer', Rule::exists('income_source_templates', 'id')->where(fn ($query) => $query
                ->where('is_active', true)
                ->where('is_fallback', false)
                ->where('form_handler', 'dedicated-business'))],
        ];

        $template = IncomeSourceTemplate::query()->find($this->integer('income_source_template_id'));
        if ($template?->template_type === 'other_business_source_of_income') {
            $rules['template_data.fields.income_sources'] = ['required', 'array', 'min:1'];
            $rules['template_data.fields.income_sources.*'] = ['string', 'max:10000'];
        }

        return $rules;
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
            if ($template->template_type === 'other_business_source_of_income') {
                $this->validateOtherIncomeSourceGroup($validator);
            }
            $this->validateNotADuplicateBusiness($validator, $template);
            $compatible = $template->businessReportSchema() !== [] ? [] : ($template->compatibility_tags ?? []);
            foreach (['properties', 'tenants', 'branches', 'products', 'suppliers', 'observations', 'competitors'] as $section) {
                if (! in_array($section, $compatible, true) && collect((array) $this->input($section))->flatten()->filter(fn ($value) => filled($value))->isNotEmpty()) {
                    $validator->errors()->add($section, 'This section is not compatible with the selected Business Template.');
                }
            }
        });
    }

    /**
     * Stops a second business being created under an identity this exact person already uses.
     *
     * This first runs during validation for immediate feedback. The initial-create transaction
     * invokes the same checker again under its stable folder/person lock before writing anything.
     * It only guards CREATION — editing or continuing an existing business goes through
     * UpdateBusinessIncomeSourceRequest, and Business Check quick-create keeps its own flow.
     *
     * Identity is ClientFolder + exact person + exact template. "Other Business/Source of Income"
     * is the one template that may legitimately repeat, so it is compared one level deeper: by the
     * exact set of income-source keys ticked on it.
     *
     * A sibling only counts when it actually holds a live, encoded Business Report. An IncomeSource
     * whose report was intentionally deleted (business_report_deleted_at), or one created by a
     * Business Check and never encoded through Business / Income Sources, is not an existing report
     * — counting it blocked templates
     * the person genuinely does not use, and because the message lands on the hidden
     * income_source_template_id field the form could only show the generic "correct the highlighted
     * fields" banner. This is the same "legitimate" test IncomeSourceController::launch() applies,
     * so Next and Save can never disagree.
     */
    protected function validateNotADuplicateBusiness(Validator $validator, IncomeSourceTemplate $template): void
    {
        $coMakerId = $this->input('co_maker_id');
        $coMakerId = filled($coMakerId) ? (int) $coMakerId : null;

        $error = app(BusinessReportDuplicateGuard::class)->duplicateError(
            $this->route('clientFolder'),
            $template,
            $coMakerId,
            $this->input('template_data.fields.income_sources'),
            filled($this->input('income_source_id')) ? (int) $this->input('income_source_id') : null,
        );

        foreach ($error as $key => $message) {
            $validator->errors()->add($key, $message);
        }
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
