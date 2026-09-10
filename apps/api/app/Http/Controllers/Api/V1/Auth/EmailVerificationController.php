<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiProblem;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailVerificationController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'If verification is needed, an email has been sent.']);
    }

    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! hash_equals($user->id, $id)
            || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new ApiProblem('verification_invalid', 'The verification link is invalid or expired.', 403);
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['message' => 'Email verified.']);
    }
}
