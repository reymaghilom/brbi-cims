<?php

namespace App\Actions\Authentication;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutCurrentSession
{
    public function execute(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
