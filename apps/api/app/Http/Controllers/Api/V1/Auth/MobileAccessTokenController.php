<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\MobileAccessToken;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\Audit;
use App\Support\SessionPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class MobileAccessTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);
        $user = User::query()->where('email', mb_strtolower(trim($validated['email'])))->first();

        if (! $user instanceof User || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are invalid.']]);
        }

        if ($user->status !== UserStatus::Active) {
            throw new ApiProblem('account_suspended', 'This account is suspended.', 403);
        }

        $issued = MobileAccessToken::issue($user, $validated['device_name'] ?? 'CProviderPrices mobile');
        Audit::record('mobile_access_token.issued', $user, metadata: [
            'mobile_access_token_id' => $issued['accessToken']->id,
            'device_name' => $issued['accessToken']->device_name,
        ]);

        return response()->json([
            'access_token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['accessToken']->expires_at->toAtomString(),
            'session' => SessionPayload::forUser($user),
        ]);
    }
}
