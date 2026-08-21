<?php

namespace App\Http\Requests\ClientFolders;

use App\Models\IncomeSource;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

class BatchBusinessReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        return [
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'income_source_ids' => ['required', 'array', 'min:1', 'max:50'],
            'income_source_ids.*' => ['integer'],
        ];
    }

    /**
     * Resolves the validated ids into their IncomeSource records, scoped to this folder and to
     * the active person (never a mix of Applicant and Co-Maker records — see the class-level
     * co_maker_id filter), ordered exactly as submitted rather than by whatever order the query
     * happens to return. Any id that isn't a real, dedicated-business source owned by the active
     * person is silently dropped, not substituted — the caller is expected to compare the
     * returned count against the submitted id count and reject a mismatch itself.
     *
     * @return Collection<int, IncomeSource>
     */
    public function resolveSources(): Collection
    {
        $folder = $this->route('clientFolder');
        $activePerson = ActivePersonResolver::resolve($folder, $this->validated('co_maker_id'));
        $ids = array_map('intval', $this->validated('income_source_ids'));

        $sources = $folder->incomeSources()
            ->where('co_maker_id', $activePerson?->id)
            ->whereIn('id', $ids)
            ->whereHas('template', fn ($query) => $query->where('is_fallback', false)->where('form_handler', 'dedicated-business'))
            ->with('template')
            ->get()
            ->keyBy('id');

        return collect($ids)->map(fn (int $id) => $sources->get($id))->filter()->values();
    }
}
