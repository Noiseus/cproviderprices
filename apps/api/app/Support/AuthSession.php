<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class AuthSession
{
    public static function start(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $user->auth_version);
    }

    public static function end(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
