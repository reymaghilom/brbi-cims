<?php

namespace App\Actions\Users;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Authentication\SessionInvalidator;
use DomainException;
use Illuminate\Support\Facades\DB;

class SetUserStatus
{
    public function __construct(private readonly SessionInvalidator $sessions) {}

    public function execute(User $administrator, User $user, UserStatus $status): User
    {
        if ($administrator->is($user)) {
            throw new DomainException('You cannot change your own account status.');
        }

        return DB::transaction(function () use ($administrator, $user, $status): User {
            $previousStatus = $user->status;
            $user->forceFill(['status' => $status])->save();

            if ($status === UserStatus::Disabled) {
                $this->sessions->invalidate($user);
            }

            AuditLog::create([
                'user_id' => $administrator->id,
                'action' => $status === UserStatus::Disabled ? 'user.disabled' : 'user.activated',
                'module' => 'user_management',
                'description' => $status === UserStatus::Disabled
                    ? 'An Administrator disabled a user account.'
                    : 'An Administrator activated a user account.',
                'metadata' => [
                    'subject_user_id' => $user->id,
                    'username' => $user->username,
                    'previous_status' => $previousStatus->value,
                    'status' => $status->value,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $user;
        });
    }
}
