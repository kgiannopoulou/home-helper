<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Token login for the phone. The browser dashboard uses the session login
 * from the starter kit instead.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
            // Only when the account has two-factor on: the authenticator code, or a recovery code
            'code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'recovery_code' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        // Hash something even when there's no such account, so the answer takes
        // as long either way and the timing doesn't tell which emails exist
        $hash = $user->password ?? self::dummyHash();

        if (! Hash::check($credentials['password'], $hash) || ! $user) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // Same rules as the web login: a verified email, and the second factor when it's on
        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => 'Confirm your email address first: open the link we emailed you, or sign in on the website to send a new one.',
            ]);
        }
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->checkSecondFactor($user, $credentials['code'] ?? null, $credentials['recovery_code'] ?? null);
        }

        $token = $user->createToken($credentials['device_name'] ?? 'phone');

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * A 6-digit authenticator code, or one recovery code (used up). Without either the
     * answer names the `code` field, so the app knows to ask for it.
     */
    private function checkSecondFactor(User $user, ?string $code, ?string $recoveryCode): void
    {
        if ($recoveryCode) {
            $match = collect($user->recoveryCodes())->first(fn (string $c) => hash_equals($c, trim($recoveryCode)));
            if ($match) {
                $user->replaceRecoveryCode($match);

                return;
            }
            throw ValidationException::withMessages(['recovery_code' => 'That recovery code is not valid.']);
        }

        $code = preg_replace('/\s+/', '', (string) $code);
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'Enter the 6-digit code from your authenticator app.']);
        }

        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        if (! app(TwoFactorAuthenticationProvider::class)->verify($secret, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator app and try again.']);
        }
    }

    /** A bcrypt hash at the configured cost, made once per process */
    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(bin2hex(random_bytes(16)));
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('households'));
    }
}
