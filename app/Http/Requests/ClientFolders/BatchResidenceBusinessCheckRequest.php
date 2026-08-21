<?php

namespace App\Http\Requests\ClientFolders;

use App\Models\BusinessCheck;
use App\Models\ResidenceCheck;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

class BatchResidenceBusinessCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        return [
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'residence_check_ids' => ['nullable', 'array', 'max:50'],
            'residence_check_ids.*' => ['integer'],
            'business_check_ids' => ['nullable', 'array', 'max:50'],
            'business_check_ids.*' => ['integer'],
        ];
    }

    /**
     * Resolves the validated ids into their records, scoped to this folder and the active person,
     * ordered exactly as submitted. Any id that isn't owned by the active person is silently
     * dropped rather than substituted.
     *
     * @return Collection<int, ResidenceCheck>
     */
    public function resolveResidenceChecks(): Collection
    {
        $folder = $this->route('clientFolder');
        $activePerson = ActivePersonResolver::resolve($folder, $this->validated('co_maker_id'));
        $ids = array_map('intval', $this->validated('residence_check_ids') ?? []);

        $checks = $folder->residenceChecks()->where('co_maker_id', $activePerson?->id)->whereIn('id', $ids)->with('photos')->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $checks->get($id))->filter()->values();
    }

    /** @return Collection<int, BusinessCheck> */
    public function resolveBusinessChecks(): Collection
    {
        $folder = $this->route('clientFolder');
        $activePerson = ActivePersonResolver::resolve($folder, $this->validated('co_maker_id'));
        $ids = array_map('intval', $this->validated('business_check_ids') ?? []);

        $checks = $folder->businessChecks()->where('co_maker_id', $activePerson?->id)->whereIn('id', $ids)->with(['photos', 'incomeSource:id,source_name,business_name,income_source_template_id', 'incomeSource.template:id,template_type'])->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $checks->get($id))->filter()->values();
    }
}
