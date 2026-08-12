<?php

namespace App\Actions\Authentication;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Authentication\SessionInvalidator;
use App\Support\Authentication\PasswordPolicy;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ResetUserPassword
{
    public function __construct(private readonly SessionInvalidator $sessions) {}

    public function execute(User $administrator, User $user, ?string $temporaryPassword = null): string
    {
        if ($administrator->role !== UserRole::Administrator) {
            throw new DomainException('Only an Administrator may reset another user password.');
        }

        if ($administrator->is($user)) {
            throw new DomainException('Use the authenticated password-change flow for your own account.');
        }

        $temporaryPassword ??= Str::password(16);

        Validator::make(
            ['password' => $temporaryPassword],
            ['password' => ['required', 'string', PasswordPolicy::rule()]],
        )->validate();

        DB::transaction(function () use ($administrator, $user, $temporaryPassword): void {
            $user->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'password_changed_at' => null,
            ])->save();

            $this->sessions->invalidate($user);

            AuditLog::create([
                'user_id' => $administrator->id,
                'action' => 'user.password_reset',
                'module' => 'authentication',
                'description' => 'An Administrator reset a user password and required a password change.',
                'metadata' => [
                    'subject_user_id' => $user->id,
                    'subject_username' => $user->username,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });

        return $temporaryPassword;
    }
}
