<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SignedInDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The phones (Sanctum tokens) signed in to your account: list them, sign one out,
 * or sign out every other one, e.g. after losing a phone. Signing out a phone also
 * stops its push notifications (devices cascade with their token).
 */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => SignedInDevices::list($request->user(), $this->currentId($request))]);
    }

    public function destroy(Request $request, int $session): Response
    {
        $deleted = $request->user()->tokens()->whereKey($session)->delete();
        abort_if($deleted === 0, 404);

        return response()->noContent();
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $count = SignedInDevices::revokeOthers($request->user(), $this->currentId($request));

        return response()->json(['revoked' => $count]);
    }

    private function currentId(Request $request): ?int
    {
        $token = $request->user()->currentAccessToken();

        return isset($token->id) ? (int) $token->id : null;
    }
}
