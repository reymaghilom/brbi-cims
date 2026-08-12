<?php

namespace App\Services\Dashboard;

use App\Enums\ClientFolderStatus;
use App\Enums\GenerationStatus;
use App\Models\ClientFolder;
use App\Models\GeneratedReport;
use App\Models\User;
use App\Services\ClientFolders\ClientFolderBrowser;
use App\Services\ClientFolders\ClientFolderCreationOptions;

class DashboardData
{
    public function __construct(
        private readonly ClientFolderBrowser $folderBrowser,
        private readonly ClientFolderCreationOptions $creationOptions,
    ) {}

    public function for(User $user, array $filters = []): array
    {
        $folderCounts = ClientFolder::query()
            ->accessibleTo($user)
            ->selectRaw(
                'COUNT(*) AS total_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS on_progress_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_count',
                [ClientFolderStatus::OnProgress->value, ClientFolderStatus::Completed->value],
            )
            ->first();

        $reportsGenerated = GeneratedReport::query()
            ->where('status', GenerationStatus::Completed)
            ->whereHas('clientFolder', fn ($query) => $query->accessibleTo($user))
            ->count();

        $clientFolders = $this->folderBrowser->browse($user, $filters);

        return [
            'summary' => [
                'total' => (int) ($folderCounts->total_count ?? 0),
                'on_progress' => (int) ($folderCounts->on_progress_count ?? 0),
                'completed' => (int) ($folderCounts->completed_count ?? 0),
                'reports_generated' => $reportsGenerated,
            ],
            'clientFolders' => $clientFolders,
            'recentFolders' => $clientFolders->getCollection(),
            'filters' => $filters,
            'creditInvestigators' => $this->creationOptions->creditInvestigatorsFor($user),
        ];
    }
}
