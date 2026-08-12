<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Authentication\ResetUserPassword;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserPasswordResetController extends Controller
{
    public function store(Request $request, User $user, ResetUserPassword $resetPassword): RedirectResponse
    {
        Gate::authorize('resetPassword', $user);

        $temporaryPassword = $resetPassword->execute($request->user(), $user);

        return back()
            ->with('status', 'Password reset. Existing sessions were invalidated.')
            ->with('temporary_password', $temporaryPassword);
    }
}
