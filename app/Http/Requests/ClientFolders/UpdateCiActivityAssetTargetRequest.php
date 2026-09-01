<?php

namespace App\Http\Requests\ClientFolders;

class UpdateCiActivityAssetTargetRequest extends StoreCiActivityAssetTargetRequest
{
    public function authorize(): bool
    {
        $target = $this->route('assetTarget');
        $activity = $this->route('ciActivity');

        return parent::authorize()
            && $target !== null
            && $target->ci_activity_id === $activity->id;
    }
}
