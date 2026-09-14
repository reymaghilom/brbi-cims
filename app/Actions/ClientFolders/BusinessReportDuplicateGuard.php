<?php

namespace App\Actions\ClientFolders;

use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;

class BusinessReportDuplicateGuard
{
    public const OTHER_BUSINESS_TEMPLATE_TYPE = 'other_business_source_of_income';

    public const STANDARD_MESSAGE = 'This business template already exists for this client. Please select another template.';

    public const COMBINATION_KEY = 'duplicate_business_categories';

    public const COMBINATION_MESSAGE = 'A Business Report with these selected business categories already exists. Please select a different combination or add another category to continue.';

    /** @return array<string, string> */
    public function duplicateError(
        ClientFolder $folder,
        IncomeSourceTemplate $template,
        ?int $coMakerId,
        mixed $incomeSourceKeys,
        ?int $exceptSourceId = null,
    ): array {
        $siblings = IncomeSource::query()
            ->where('client_folder_id', $folder->id)
            ->where('income_source_template_id', $template->id)
            ->when(
                $coMakerId === null,
                fn ($query) => $query->whereNull('co_maker_id'),
                fn ($query) => $query->where('co_maker_id', $coMakerId),
            )
            ->whereNull('business_report_deleted_at')
            ->has('businessReport')
            ->where('revision', '>', 1)
            ->when($exceptSourceId !== null, fn ($query) => $query->whereKeyNot($exceptSourceId))
            ->with('businessReport:id,income_source_id,template_data')
            ->get();

        if ($siblings->isEmpty()) {
            return [];
        }

        if ($template->template_type !== self::OTHER_BUSINESS_TEMPLATE_TYPE) {
            return ['income_source_template_id' => self::STANDARD_MESSAGE];
        }

        $incoming = $this->normalizedIncomeSourceKeys($incomeSourceKeys);
        if ($incoming === []) {
            return [];
        }

        foreach ($siblings as $sibling) {
            $existing = $this->normalizedIncomeSourceKeys(data_get($sibling->businessReport?->template_data, 'fields.income_sources'));

            if ($existing !== [] && $existing === $incoming) {
                return [self::COMBINATION_KEY => self::COMBINATION_MESSAGE];
            }
        }

        return [];
    }

    /** @return list<string> */
    private function normalizedIncomeSourceKeys(mixed $selection): array
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
}
