<?php

namespace App\Http\Requests\Reports;

use App\Services\Reports\ReportWorkspaceQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the Reports workspace filters. Everything arrives as a GET parameter so a filtered view
 * is bookmarkable and survives pagination; anything unrecognised is rejected rather than reaching a
 * query. `tab` is the single status concept — the three tabs and the Status filter both write it,
 * so the page can never hold two disagreeing statuses at once.
 */
class BrowseGlobalReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            // Set when the user picks an exact client from the search suggestions. Validated
            // against live folders only, so a guessed or recycled id can never reach the query;
            // every row it can produce still passes the workspace's own access scope.
            'client_folder_id' => ['nullable', 'integer', Rule::exists('client_folders', 'id')->whereNull('deleted_at')],
            'report_type' => ['nullable', Rule::in(array_keys(ReportWorkspaceQuery::KINDS))],
            'person' => ['nullable', Rule::in(['applicant', 'co_maker'])],
            'tab' => ['nullable', Rule::in(ReportWorkspaceQuery::TABS)],
            // Whitelisted here so no request value can ever reach orderBy() — see
            // ReportWorkspaceQuery::SORTS for the key-to-column map.
            'sort' => ['nullable', Rule::in(array_keys(ReportWorkspaceQuery::SORTS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }
}
