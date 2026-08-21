<?php

namespace App\Actions\Users;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Authentication\SessionInvalidator;
use App\Services\Users\ProfilePhotoStorage;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateManagedUser
{
    public function __construct(
        private readonly SessionInvalidator $sessions,
        private readonly ProfilePhotoStorage $photos,
    ) {}

    public function execute(User $administrator, User $user, array $data): User
    {
        if ($administrator->is($user) && $data['role'] !== $user->role->value) {
            throw new DomainException('You cannot change your own Administrator role.');
        }

        // Stored before the transaction (the same pattern as CreateManagedUser): a newly
        // uploaded photo is only ever added to the update payload below when one was actually
        // submitted, so the previous path is left completely untouched otherwise.
        $previousPhotoPath = $user->profile_photo_path;
        $newPhotoUploaded = filled($data['profile_photo'] ?? null);
        $newPhotoPath = $newPhotoUploaded ? $this->photos->store($data['profile_photo']) : null;

        try {
            return DB::transaction(function () use ($administrator, $user, $data, $newPhotoUploaded, $newPhotoPath): User {
                $previousRole = $user->role->value;
                $roleChanged = $previousRole !== $data['role'];

                $user->update([
                    'full_name' => $data['full_name'],
                    'employee_id' => $data['employee_id'],
                    'username' => $data['username'],
                    'role' => $data['role'],
                    ...($newPhotoUploaded ? ['profile_photo_path' => $newPhotoPath] : []),
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
                        'profile_photo_replaced' => $newPhotoUploaded,
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);

                return $user;
            });
        } catch (\Throwable $exception) {
            if ($newPhotoUploaded) {
                $this->photos->delete($newPhotoPath);
            }

            throw $exception;
        } finally {
            // Only reached after a successful commit (the catch above rethrows), so this is the
            // one safe point to remove the old file: the new path is durably saved by now, and
            // it's never the shared/default avatar — that's a frontend fallback, never a row in
            // profile_photo_path.
            if ($newPhotoUploaded && filled($previousPhotoPath) && ! isset($exception)) {
                $this->photos->delete($previousPhotoPath);
            }
        }
    }
}
