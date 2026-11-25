<?php

namespace App\Support;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A user's phone logins (Sanctum tokens), shared by the API and the security settings page.
 */
class SignedInDevices
{
    /**
     * @return list<array{id: int, name: string, created_at: string|null, last_used_at: string|null, current: bool}>
     */
    public static function list(User $user, ?int $currentId = null): array
    {
        return $user->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get(['id', 'name', 'created_at', 'last_used_at'])
            ->map(fn (PersonalAccessToken $t) => [
                'id' => (int) $t->id,
                'name' => $t->name,
                'created_at' => $t->created_at?->toIso8601String(),
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'current' => $currentId !== null && (int) $t->id === $currentId,
            ])
            ->values()
            ->all();
    }

    /** Deletes every token except $keepId (none kept when null); returns how many went. */
    public static function revokeOthers(User $user, ?int $keepId = null): int
    {
        return $user->tokens()
            ->when($keepId !== null, fn ($q) => $q->whereKeyNot($keepId))
            ->delete();
    }
}
