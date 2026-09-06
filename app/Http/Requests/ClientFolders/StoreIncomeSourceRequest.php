<?php

namespace App\Http\Requests\ClientFolders;

use App\Http\Controllers\IncomeSourceController;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreIncomeSourceRequest extends UpdateBusinessIncomeSourceRequest
{
    /** Error key for the Other Business duplicate-category failure, rendered at the top of the form. */
    public const DUPLICATE_CATEGORIES_KEY = 'duplicate_business_categories';

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
     * This runs during validation, so nothing is written and then undone: no IncomeSource, no
     * BusinessReport, no template children. It only guards CREATION — editing or continuing an
     * existing business goes through UpdateBusinessIncomeSourceRequest and is never compared
     * against itself, and the Business Check "+ Add Business" quick-create keeps its own flow.
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

        $siblings = IncomeSource::query()
            ->where('client_folder_id', $this->route('clientFolder')->id)
            ->where('income_source_template_id', $template->id)
            // Applicant is co_maker_id NULL; a Co-Maker is that one exact id. One person's
            // businesses can never block another's.
            ->when(
                $coMakerId === null,
                fn ($query) => $query->whereNull('co_maker_id'),
                fn ($query) => $query->where('co_maker_id', $coMakerId),
            )
            ->whereNull('business_report_deleted_at')
            ->has('businessReport')
            // An encoded report, not the shell CreateIncomeSource auto-creates for a Business
            // Check-first business: revision stays 1 until SaveBusinessIncomeSource has actually
            // run for it. Exactly the condition IncomeSourceController::personAlreadyUsesTemplate()
            // and the picker's used-template list apply, so Next and Save can never disagree.
            ->where('revision', '>', 1)
            // A business is never compared against itself, in the one flow that submits its own id.
            ->when(
                filled($this->input('income_source_id')),
                fn ($query) => $query->whereKeyNot((int) $this->input('income_source_id')),
            )
            ->with('businessReport:id,income_source_id,template_data')
            ->get();

        if ($siblings->isEmpty()) {
            return;
        }

        if ($template->template_type !== 'other_business_source_of_income') {
            $validator->errors()->add('income_source_template_id', IncomeSourceController::DUPLICATE_TEMPLATE_MESSAGE);

            return;
        }

        $incoming = $this->normalizedIncomeSourceKeys($this->input('template_data.fields.income_sources'));
        if ($incoming === []) {
            // Its own "select at least one" rule already reported this.
            return;
        }

        foreach ($siblings as $sibling) {
            $existing = $this->normalizedIncomeSourceKeys(data_get($sibling->businessReport?->template_data, 'fields.income_sources'));

            // Exact set equality, never partial overlap: [A,B] repeats [B,A], but [A,B,C] and
            // [A,B] are genuinely different businesses and both remain allowed.
            if ($existing !== [] && $existing === $incoming) {
                // Its own key, not the checkbox field's: this message belongs at the top of the
                // form as the single explanation of the failure, and must not also surface beside
                // the checkboxes where the field's own "select at least one" message lives.
                $validator->errors()->add(self::DUPLICATE_CATEGORIES_KEY, 'A Business Report with these selected business categories already exists. Please select a different combination or add another category to continue.');

                return;
            }
        }
    }

    /**
     * A comparable signature built from the stored option keys themselves — never their labels,
     * their display order, or the order they arrived in the request.
     *
     * @return list<string>
     */
    protected function normalizedIncomeSourceKeys(mixed $selection): array
    {
        if (! is_array($selection)) {
            return [];
        }

        $keys = [];
        foreach ($selection as $value) {
            if (! is_string($value)) {
                continue;
            }
            $key = mb_strtolower(trim($value));
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
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
