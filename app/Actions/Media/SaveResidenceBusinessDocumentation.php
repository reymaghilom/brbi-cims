<?php

namespace App\Actions\Media;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\ResidenceBusinessDocumentation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveResidenceBusinessDocumentation
{
    /** @param  array{co_maker_id: ?int, business_name?: ?string, category: string, location: string, remarks: ?string}  $data */
    public function execute(User $actor, ClientFolder $folder, array $data, ?ResidenceBusinessDocumentation $documentation = null): ResidenceBusinessDocumentation
    {
        return DB::transaction(function () use ($actor, $folder, $data, $documentation): ResidenceBusinessDocumentation {
            $isNew = $documentation === null;
            $documentation ??= new ResidenceBusinessDocumentation([
                'client_folder_id' => $folder->id,
                'co_maker_id' => $data['co_maker_id'] ?? null,
                'created_by' => $actor->id,
            ]);

            $documentation->fill([
                'category' => $data['category'],
                'business_name' => $data['category'] === ResidenceBusinessDocumentation::CATEGORY_BUSINESS
                    ? ($data['business_name'] ?? $documentation->business_name)
                    : null,
                'location' => $data['location'],
                // Optional Remarks, owned by this documentation set for Residence and Business
                // alike. Never written to CIBI, Residence Check, or CI Activities.
                'remarks' => $data['remarks'] ?? null,
                'updated_by' => $actor->id,
            ]);
            $changedFields = $isNew
                ? []
                : array_values(array_intersect(['business_name', 'location', 'remarks'], array_keys($documentation->getDirty())));
            $documentation->save();

            if ($isNew || $changedFields !== []) {
                $category = ucfirst($documentation->category);
                AuditLog::create([
                    'user_id' => $actor->id,
                    'client_folder_id' => $folder->id,
                    'action' => $isNew ? 'residence_business_documentation.created' : 'residence_business_documentation.updated',
                    'module' => 'media',
                    'description' => $isNew ? "Saved {$category} Documentation." : "Updated {$category} Documentation.",
                    'metadata' => [
                        'residence_business_documentation_id' => $documentation->id,
                        'co_maker_id' => $documentation->co_maker_id,
                        'business_name' => $documentation->business_name,
                        'category' => $documentation->category,
                        'changed_fields' => $changedFields,
                    ],
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);
            }

            return $documentation;
        });
    }
}
