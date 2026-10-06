<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\PreferredLanguage;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\MobileAccessToken;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\Audit;
use App\Support\AuthSession;
use App\Support\SafeDestination;
use App\Support\SessionPayload;
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
            'session' => SessionPayload::forUser($user),
            'destination' => SafeDestination::afterLogin($user, $validated['intended_destination'] ?? null),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }

        return response()->json(['session' => SessionPayload::forUser($user)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $mobileAccessToken = $request->attributes->get(MobileAccessToken::class);

        if ($mobileAccessToken instanceof MobileAccessToken) {
            $mobileAccessToken->revoke();
            Audit::record('mobile_access_token.revoked', $request->user(), metadata: [
                'mobile_access_token_id' => $mobileAccessToken->id,
            ]);

            return response()->json(['message' => 'Signed out.']);
        }

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

        return response()->json(['session' => SessionPayload::forUser($user->refresh())]);
    }
}
