<?php

namespace App\Services\ClientFolders;

use App\Models\ClientCompletionResult;
use Illuminate\Support\Collection;

class ClientFolderPreviewData
{
    public function for(Collection $folderIds): Collection
    {
        if ($folderIds->isEmpty()) {
            return collect();
        }

        return ClientCompletionResult::query()
            ->join('completion_rules', 'completion_rules.id', '=', 'client_completion_results.completion_rule_id')
            ->whereIn('client_completion_results.client_folder_id', $folderIds)
            ->where('completion_rules.is_active', true)
            ->where('completion_rules.is_required', true)
            ->orderBy('completion_rules.sort_order')
            ->get([
                'client_completion_results.client_folder_id',
                'client_completion_results.is_satisfied',
                'completion_rules.label',
            ])
            ->groupBy('client_folder_id')
            ->map(function (Collection $results): array {
                $completed = $results->where('is_satisfied', true)->count();

                return [
                    'completed' => $completed,
                    'total' => $results->count(),
                    'missing' => $results->where('is_satisfied', false)->pluck('label')->values(),
                ];
            });
    }
}
