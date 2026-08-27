<?php

namespace App\Policies;

use App\Models\CibiReport;
use App\Models\User;

class CibiReportPolicy extends ClientFolderResourcePolicy
{
    public function reassignSignatory(User $user, CibiReport $report): bool
    {
        return $this->isAdministrator($user) && $this->canAccessFolder($user, $this->folderFor($report));
    }

    public function viewHistory(User $user, CibiReport $report): bool
    {
        return $this->canAccessFolder($user, $this->folderFor($report));
    }
}
