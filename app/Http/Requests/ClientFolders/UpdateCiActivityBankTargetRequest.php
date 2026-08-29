<?php

namespace App\Http\Requests\ClientFolders;

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
}
