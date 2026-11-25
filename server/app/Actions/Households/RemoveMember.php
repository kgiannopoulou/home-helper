<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes someone out of a household: an owner removing a member, or a member leaving.
 *
 * Every API route, sync and push checks membership on each request, so access ends at once:
 * their next sync gets 403 and the household's pushes stop reaching their phones. Their
 * unused invites to this household are deleted too, so an old link can't bring them back.
 * Rows they created stay: the shopping list and expenses belong to the household.
 *
 * The last owner can't leave or be removed (transfer ownership first), so a household
 * always has someone who can invite and manage it.
 * Used by the API (phone) and the web dashboard.
 */
class RemoveMember
{
    public function __invoke(Household $household, User $actor, User $member): void
    {
        $actorRole = $actor->roleIn($household);
        $memberRole = $member->roleIn($household);

        if ($actorRole === null) {
            throw new AuthorizationException;
        }
        if ($memberRole === null) {
            throw ValidationException::withMessages(['member' => 'They are not a member of this household.']);
        }
        if ($actor->isNot($member) && $actorRole !== HouseholdRole::Owner) {
            throw new AuthorizationException('Only an owner can remove people.');
        }

        DB::transaction(function () use ($household, $member, $memberRole) {
            if ($memberRole === HouseholdRole::Owner) {
                $owners = $household->memberships()->where('role', HouseholdRole::Owner)->lockForUpdate()->count();
                if ($owners <= 1) {
                    throw ValidationException::withMessages([
                        'member' => 'The last owner can’t leave. Make someone else the owner first.',
                    ]);
                }
            }

            $household->users()->detach($member->id);
            $household->invites()->whereNull('accepted_at')->where('email', strtolower($member->email))->delete();
        });
    }
}
