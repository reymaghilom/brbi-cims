<?php

namespace App\Actions\Users;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateManagedUser
{
    public function execute(User $administrator, array $data): User
    {
        return DB::transaction(function () use ($administrator, $data): User {
            $user = User::create([
                'full_name' => $data['full_name'],
                'employee_id' => $data['employee_id'],
                'username' => $data['username'],
                'role' => $data['role'],
                'status' => UserStatus::Active,
                'password' => $data['password'],
                'must_change_password' => true,
                'password_changed_at' => null,
            ]);

            AuditLog::create([
                'user_id' => $administrator->id,
                'action' => 'user.created',
                'module' => 'user_management',
                'description' => 'An Administrator created a user account.',
                'metadata' => ['subject_user_id' => $user->id, 'username' => $user->username, 'role' => $user->role->value],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return $user;
        });
    }
}
