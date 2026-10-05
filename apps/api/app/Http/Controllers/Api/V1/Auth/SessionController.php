<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\MembershipStatus;
use App\Enums\PreferredLanguage;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuthSession;
use App\Support\SafeDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SessionController extends Controller
{
    public function csrf(): JsonResponse
    {
        return response()->json(['message' => 'CSRF cookie initialized.']);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'intended_destination' => ['nullable', 'string', 'max:2048'],
        ]);
        $email = mb_strtolower(trim($validated['email']));
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are invalid.']]);
        }

        if ($user->status !== UserStatus::Active) {
            throw new ApiProblem('account_suspended', 'This account is suspended.', 403);
        }

        AuthSession::start($request, $user);

        return response()->json([
            'session' => $this->sessionPayload($user),
            'destination' => SafeDestination::afterLogin($user, $validated['intended_destination'] ?? null),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }

        return response()->json(['session' => $this->sessionPayload($user)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        AuthSession::end($request);

        return response()->json(['message' => 'Signed out.']);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }

        $validated = $request->validate([
            'preferred_language' => ['required', 'string', Rule::in(array_column(PreferredLanguage::cases(), 'value'))],
        ]);
        $user->update(['preferred_language' => $validated['preferred_language']]);

        return response()->json(['session' => $this->sessionPayload($user->refresh())]);
    }

    /** @return array<string, mixed> */
    private function sessionPayload(User $user): array
    {
        $memberships = $user->memberships()
            ->where('status', MembershipStatus::Active)
            ->with('organization')
            ->get();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
                'platform_role' => $user->platform_role?->value,
                'preferred_language' => $user->preferred_language->value,
            ],
            'organizations' => $memberships->map(fn (OrganizationMembership $membership): array => [
                'id' => $membership->organization->id,
                'name' => $membership->organization->name,
                'slug' => $membership->organization->slug,
                'status' => $membership->organization->status->value,
                'role' => $membership->role->value,
            ])->values()->all(),
        ];
    }
}
