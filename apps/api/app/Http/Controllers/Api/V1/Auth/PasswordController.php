<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

final class PasswordController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']]);
        Password::sendResetLink(['email' => mb_strtolower(trim($validated['email']))]);

        return response()->json([
            'message' => 'If an eligible account exists, password reset instructions have been sent.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'max:512'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);
        $credentials = [
            ...$validated,
            'email' => mb_strtolower(trim($validated['email'])),
        ];
        $status = Password::reset($credentials, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
                'auth_version' => $user->auth_version + 1,
            ])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'error' => [
                    'code' => 'password_reset_invalid',
                    'message' => 'The password reset request is invalid or expired.',
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 422);
        }

        return response()->json(['message' => 'Password reset. Sign in with your new password.']);
    }
}
