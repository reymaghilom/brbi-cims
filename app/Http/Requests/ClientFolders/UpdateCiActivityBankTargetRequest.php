<?php

namespace App\Http\Requests\ClientFolders;

use Illuminate\Support\Arr;

class UpdateCiActivityBankTargetRequest extends StoreCiActivityBankTargetRequest
{
    public function authorize(): bool
    {
        $target = $this->route('bankTarget');
        $activity = $this->route('ciActivity');

        return parent::authorize()
            && $target !== null
            && $target->ci_activity_id === $activity->id;
    }

    /**
     * The edit form always renders the target's current revision, so the token is required rather
     * than optional: an omitted one would otherwise be the easiest way to bypass stale protection.
     */
    public function rules(): array
    {
        // allow_duplicate is a CREATE-only affordance: dropping it here means an edit can never
        // carry it into validated(), so the duplicate warning cannot be reached or bypassed from
        // the edit form.
        return Arr::except(parent::rules(), ['allow_duplicate']) + [
            'expected_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
