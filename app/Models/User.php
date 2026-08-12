<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
}
