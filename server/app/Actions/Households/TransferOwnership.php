<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An owner hands the household to another member: they become an owner and the
 * current owner becomes a member. Used by the API (phone) and the web dashboard.
 */
class TransferOwnership
{
    public function __invoke(Household $household, User $owner, User $newOwner): void
    {
        if ($owner->roleIn($household) !== HouseholdRole::Owner) {
            throw new AuthorizationException('Only an owner can hand over the household.');
        }
        if ($owner->is($newOwner)) {
            throw ValidationException::withMessages(['user_id' => 'You are already the owner.']);
        }
        if (! $newOwner->isMemberOf($household)) {
            throw ValidationException::withMessages(['user_id' => 'They need to join the household first.']);
        }

        DB::transaction(function () use ($household, $owner, $newOwner) {
            $household->users()->updateExistingPivot($newOwner->id, ['role' => HouseholdRole::Owner]);
            $household->users()->updateExistingPivot($owner->id, ['role' => HouseholdRole::Member]);
        });
    }
}
