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
            $updatedUser = DB::transaction(function () use ($administrator, $user, $data, $newPhotoUploaded, $newPhotoPath): User {
                $previousRole = $user->role->value;
                $roleChanged = $previousRole !== $data['role'];

                $user->update([
                    'full_name' => $data['full_name'],
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
        }

        // The replacement path is durably committed before the previous file is removed. The
        // shared/default avatar is only a frontend fallback and is never stored in this column.
        if ($newPhotoUploaded && filled($previousPhotoPath)) {
            $this->photos->delete($previousPhotoPath);
        }

        return $updatedUser;
    }
}
