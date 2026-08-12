<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\SetUserStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UserStatusController extends Controller
{
    public function update(UpdateUserStatusRequest $request, User $user, SetUserStatus $setStatus): RedirectResponse
    {
        $setStatus->execute($request->user(), $user, UserStatus::from($request->validated('status')));

        return back()->with('status', $user->status === UserStatus::Disabled
            ? 'User disabled and existing sessions invalidated.'
            : 'User activated.');
    }
}
