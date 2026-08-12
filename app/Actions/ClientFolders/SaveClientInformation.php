<?php

namespace App\Actions\ClientFolders;

use App\Enums\AddressType;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\ClientInformation;
use App\Models\User;
use App\Services\ClientFolders\ClientInformationCompletionEvaluator;
use App\Services\ClientFolders\ClientNameFormatter;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveClientInformation
{
    public function __construct(
        private readonly ClientNameFormatter $names,
        private readonly ClientInformationCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
    ) {}

    public function execute(User $actor, ClientFolder $folder, array $data): ClientInformation
    {
        return DB::transaction(function () use ($actor, $folder, $data): ClientInformation {
            $wasCreated = ! $folder->information()->exists();
            $identity = Arr::only($data, ['first_name', 'middle_name', 'last_name', 'suffix']);
            $folder->fill($identity + [
                'display_name' => $this->names->format($data['last_name'], $data['first_name'], $data['middle_name'], $data['suffix']),
                'updated_by' => $actor->id,
            ]);
            $folderChanges = array_keys($folder->getDirty());
            $folder->save();

            $informationData = Arr::except($data, ['first_name', 'middle_name', 'last_name', 'suffix', 'addresses']);
            $information = $folder->information()->firstOrNew();
            $information->fill($informationData + ['last_edited_by' => $actor->id]);
            $informationChanges = array_keys($information->getDirty());
            $information->save();

            $changedAddressTypes = $this->syncAddresses($folder, $data['addresses'] ?? []);
            $this->completion->evaluate($folder, $information);
            $this->progress->recalculate($folder);

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => $wasCreated ? 'client_information.created' : 'client_information.updated',
                'module' => 'client_information',
                'description' => $wasCreated ? 'Client information was created.' : 'Client information was updated.',
                'metadata' => [
                    'changed_fields' => array_values(array_unique(array_merge($folderChanges, $informationChanges))),
                    'changed_address_types' => $changedAddressTypes,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $information->refresh();
        });
    }

    private function syncAddresses(ClientFolder $folder, array $addresses): array
    {
        $changed = [];

        foreach (AddressType::cases() as $index => $type) {
            $data = $addresses[$type->value] ?? [];
            $existing = $folder->addresses()->where('address_type', $type->value)->orderBy('id')->get();

            if (! ($data['enabled'] ?? false)) {
                if ($existing->isNotEmpty()) {
                    $folder->addresses()->where('address_type', $type->value)->delete();
                    $changed[] = $type->value;
                }

                continue;
            }

            $payload = Arr::except($data, ['enabled']) + ['address_type' => $type->value, 'sort_order' => $index + 1];
            $address = $existing->first() ?? $folder->addresses()->make(['address_type' => $type->value]);
            $address->fill($payload);

            if (! $address->exists || $address->isDirty()) {
                $changed[] = $type->value;
            }
            $address->save();

            // The Client Information workflow intentionally keeps one structured row per enum type.
            $existing->skip(1)->each->delete();
        }

        return array_values(array_unique($changed));
    }
}
