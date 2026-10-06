<?php

namespace App\Http\Middleware;

use App\Models\MobileAccessToken;
use App\Models\User;
use App\Support\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateWebOrMobile
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (is_string($bearerToken) && $bearerToken !== '') {
            $accessToken = MobileAccessToken::findByPlainText($bearerToken);

            if (! $accessToken instanceof MobileAccessToken || $accessToken->revoked_at !== null) {
                throw new ApiProblem('mobile_token_invalid', 'The mobile access token is invalid.', 401);
            }

            if ($accessToken->expires_at->isPast()) {
                $accessToken->revoke();

                throw new ApiProblem('mobile_token_expired', 'The mobile access token has expired.', 401);
            }

            $accessToken->forceFill(['last_used_at' => now()])->save();
            $request->attributes->set(MobileAccessToken::class, $accessToken);
            $request->setUserResolver(fn (): User => $accessToken->user);

            if (! $this->allowsMobileScope($request)) {
                throw new ApiProblem('mobile_scope_forbidden', 'This mobile access token cannot perform that action.', 403);
            }

            return $next($request);
        }

        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }

        $request->setUserResolver(fn (): User => $user);

        return $next($request);
    }

    private function allowsMobileScope(Request $request): bool
    {
        if ($request->isMethod('PATCH') && $request->is('api/v1/auth/preferences')) {
            return true;
        }

        if ($request->isMethod('DELETE') && $request->is('api/v1/auth/session')) {
            return true;
        }

        return $request->isMethod('GET') && $request->is(
            'api/v1/auth/session',
            'api/v1/organizations/*/context',
            'api/v1/organizations/*/restaurant-locations',
            'api/v1/organizations/*/restaurant-locations/*',
            'api/v1/organizations/*/product-search',
            'api/v1/organizations/*/comparisons/*',
            'api/v1/organizations/*/price-history/*',
            'api/v1/organizations/*/saved-lists',
            'api/v1/organizations/*/saved-lists/*',
        );
    }
}
