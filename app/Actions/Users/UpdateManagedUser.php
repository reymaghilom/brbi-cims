<?php

namespace App\Actions\Users;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Authentication\SessionInvalidator;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateManagedUser
{
    public function __construct(private readonly SessionInvalidator $sessions) {}

    public function execute(User $administrator, User $user, array $data): User
    {
        if ($administrator->is($user) && $data['role'] !== $user->role->value) {
            throw new DomainException('You cannot change your own Administrator role.');
        }

        return DB::transaction(function () use ($administrator, $user, $data): User {
            $previousRole = $user->role->value;
            $roleChanged = $previousRole !== $data['role'];

            $user->update([
                'full_name' => $data['full_name'],
                'employee_id' => $data['employee_id'],
                'username' => $data['username'],
                'role' => $data['role'],
            ]);

            if ($roleChanged) {
                $this->sessions->invalidate($user);
            }

            AuditLog::create([
                'user_id' => $administrator->id,
                'action' => 'user.updated',
                'module' => 'user_management',
                'description' => 'An Administrator updated a user account.',
                'metadata' => [
                    'subject_user_id' => $user->id,
                    'username' => $user->username,
                    'previous_role' => $previousRole,
                    'role' => $user->role->value,
                    'role_changed' => $roleChanged,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $user;
        });
    }
}
