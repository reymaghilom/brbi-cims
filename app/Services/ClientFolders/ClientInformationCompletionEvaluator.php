<?php

namespace App\Services\ClientFolders;

use App\Enums\RecordState;
use App\Models\ClientFolder;
use App\Models\ClientInformation;
use App\Models\CompletionRule;

class ClientInformationCompletionEvaluator
{
    public function evaluate(ClientFolder $folder, ClientInformation $information): bool
    {
        $rule = CompletionRule::query()->where('code', 'client_information')->where('is_active', true)->first();
        if ($rule === null) {
            return false;
        }

        $condition = $rule->source_condition ?? [];
        $folderFields = $condition['required_folder_fields'] ?? ['first_name', 'last_name'];
        $informationFields = $condition['required_information_fields'] ?? ['birth_date', 'civil_status', 'contact_number'];
        $addressCondition = $condition['required_address'] ?? [
            'type' => 'present',
            'fields' => ['address_line_1', 'city_municipality', 'province'],
        ];

        $satisfied = collect($folderFields)->every(fn (string $field): bool => filled($folder->{$field}))
            && collect($informationFields)->every(fn (string $field): bool => filled($information->{$field}));

        $address = $folder->addresses()->where('address_type', $addressCondition['type'])->first();
        $satisfied = $satisfied && $address !== null
            && collect($addressCondition['fields'])->every(fn (string $field): bool => filled($address->{$field}));

        $information->update([
            'completion_state' => $satisfied ? RecordState::Complete : RecordState::Draft,
            'completed_at' => $satisfied ? ($information->completed_at ?? now()) : null,
        ]);

        $folder->completionResults()->updateOrCreate(
            ['completion_rule_id' => $rule->id],
            [
                'is_satisfied' => $satisfied,
                'score' => null,
                'explanation_key' => $satisfied ? 'client_information.complete' : 'client_information.missing_required_items',
                'evaluated_at' => now(),
            ],
        );

        return $satisfied;
    }
}
