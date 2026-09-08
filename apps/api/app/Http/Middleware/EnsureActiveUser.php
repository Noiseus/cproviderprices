<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }

        $user->refresh();

        if ($user->status !== UserStatus::Active) {
            $this->invalidate($request);

            throw new ApiProblem('account_suspended', 'This account is suspended.', 403);
        }

        if ((int) $request->session()->get('auth_version', 0) !== $user->auth_version) {
            $this->invalidate($request);

            throw new ApiProblem('session_invalidated', 'This session is no longer valid.', 401);
        }

        return $next($request);
    }

    private function invalidate(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
