<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'auth_session_version' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function assignedClientFolders(): HasMany
    {
        return $this->hasMany(ClientFolder::class, 'assigned_ci_id');
    }

    public function createdClientFolders(): HasMany
    {
        return $this->hasMany(ClientFolder::class, 'created_by');
    }

    public function activityNotes(): HasMany
    {
        return $this->hasMany(ActivityNote::class);
    }

    /**
     * Null when the user has no uploaded photo, or the saved path no longer has a file behind
     * it — callers fall back to the default avatar in either case rather than rendering a
     * broken image.
     */
    public function profilePhotoUrl(): ?string
    {
        if (blank($this->profile_photo_path) || ! Storage::disk('public')->exists($this->profile_photo_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->profile_photo_path);
    }
}
