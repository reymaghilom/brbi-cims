<?php

namespace App\Services\Authentication;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SessionInvalidator
{
    public function invalidate(User $user): void
    {
        $user->forceFill([
            'auth_session_version' => $user->auth_session_version + 1,
            'remember_token' => Str::random(60),
        ])->save();

        if (Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->delete();
        }
    }
}
