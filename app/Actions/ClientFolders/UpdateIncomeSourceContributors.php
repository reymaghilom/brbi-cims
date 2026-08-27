<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\User;
use App\Services\ClientFolders\CiParticipantService;
use Illuminate\Support\Facades\DB;

class UpdateIncomeSourceContributors
{
    public function __construct(private readonly CiParticipantService $participants) {}

    /**
     * @param  array<int, int>  $userIds
     */
    public function execute(User $actor, ClientFolder $folder, IncomeSource $source, array $userIds): IncomeSource
    {
        return DB::transaction(function () use ($actor, $folder, $source, $userIds): IncomeSource {
            $result = $this->participants->syncCompanions($source, $userIds);

            foreach ($result->added as $userId) {
                $this->log($actor, $folder, $source, 'income_source.contributor_added', $userId);
            }
            foreach ($result->removed as $userId) {
                $this->log($actor, $folder, $source, 'income_source.contributor_removed', $userId);
            }

            return $source->refresh();
        });
    }

    private function log(User $actor, ClientFolder $folder, IncomeSource $source, string $action, int $contributorId): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'client_folder_id' => $folder->id,
            'action' => $action,
            'module' => 'income_sources',
            'description' => $action === 'income_source.contributor_added'
                ? 'A Credit Investigator was added as a business report contributor.'
                : 'A Credit Investigator was removed as a business report contributor.',
            'metadata' => ['income_source_id' => $source->id, 'co_maker_id' => $source->co_maker_id, 'contributor_id' => $contributorId],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
