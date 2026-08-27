<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\CiParticipantService;
use Illuminate\Support\Facades\DB;

class UpdateBusinessCheckContributors
{
    public function __construct(private readonly CiParticipantService $participants) {}

    /**
     * @param  array<int, int>  $userIds
     */
    public function execute(User $actor, ClientFolder $folder, BusinessCheck $check, array $userIds): BusinessCheck
    {
        return DB::transaction(function () use ($actor, $folder, $check, $userIds): BusinessCheck {
            $result = $this->participants->syncCompanions($check, $userIds);

            foreach ($result->added as $userId) {
                $this->log($actor, $folder, $check, 'business_check.contributor_added', $userId);
            }
            foreach ($result->removed as $userId) {
                $this->log($actor, $folder, $check, 'business_check.contributor_removed', $userId);
            }

            return $check->refresh();
        });
    }

    private function log(User $actor, ClientFolder $folder, BusinessCheck $check, string $action, int $contributorId): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'client_folder_id' => $folder->id,
            'action' => $action,
            'module' => 'residence_business_report',
            'description' => $action === 'business_check.contributor_added'
                ? 'A Credit Investigator was added as a Business Check contributor.'
                : 'A Credit Investigator was removed as a Business Check contributor.',
            'metadata' => ['business_check_id' => $check->id, 'co_maker_id' => $check->co_maker_id, 'contributor_id' => $contributorId],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
