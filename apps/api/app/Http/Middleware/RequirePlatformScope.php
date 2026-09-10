<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePlatformScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->platform_role !== Role::PlatformAdmin) {
            if ($user instanceof User) {
                Audit::record('platform.access_denied', $user, metadata: ['route' => $request->route()?->getName()]);
            }

            throw new ApiProblem('platform_scope_required', 'Explicit platform scope is required.', 403);
        }

        Audit::record('platform.action', $user, metadata: ['route' => $request->route()?->getName()]);

        return $next($request);
    }
}
