<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->home = Household::factory()->create(['name' => 'Patission 12']);
    $this->me = User::factory()->create(['name' => 'Konstantina']);
    $this->partner = User::factory()->create(['name' => 'Alex']);
    $this->home->addMember($this->me, HouseholdRole::Owner);
    $this->home->addMember($this->partner);
});

test('the household page knows who you are, for the member buttons', function () {
    $this->actingAs($this->me)->get('/household')->assertInertia(fn (Assert $page) => $page
        ->component('household')
        ->where('me', $this->me->id)
        ->where('canInvite', true)
        ->has('members', 2));
});

test('the owner removes someone from the dashboard', function () {
    $this->actingAs($this->me)->delete("/household/members/{$this->partner->id}")->assertRedirect();

    expect($this->partner->isMemberOf($this->home))->toBeFalse();
});

test('the owner hands over the household, then can leave it', function () {
    $this->actingAs($this->me)->post("/household/owner/{$this->partner->id}")->assertRedirect();
    expect($this->partner->roleIn($this->home))->toBe(HouseholdRole::Owner);

    $this->delete("/household/members/{$this->me->id}")->assertRedirect('/household');
    expect($this->me->isMemberOf($this->home))->toBeFalse();
});

test('a member cannot remove the owner, and the last owner cannot leave', function () {
    $this->actingAs($this->partner)->delete("/household/members/{$this->me->id}")->assertForbidden();

    $this->actingAs($this->me)->delete("/household/members/{$this->me->id}")->assertSessionHasErrors('member');
    expect($this->me->isMemberOf($this->home))->toBeTrue();
});

test('the security page lists phones and signs them all out', function () {
    $this->me->createToken('android phone');
    $this->me->createToken('ios phone');
    $other = $this->partner->createToken('theirs');

    $this->actingAs($this->me)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page->component('settings/security')->has('phones', 2));

    $this->delete('/settings/phones')->assertRedirect();

    expect($this->me->tokens()->count())->toBe(0)
        ->and($other->accessToken->fresh())->not->toBeNull();
});

test('signing out one phone from the dashboard leaves the rest', function () {
    $old = $this->me->createToken('old phone');
    $this->me->createToken('new phone');
    $theirs = $this->partner->createToken('theirs');

    $this->actingAs($this->me)->delete("/settings/phones/{$theirs->accessToken->id}")->assertRedirect();
    $this->delete("/settings/phones/{$old->accessToken->id}")->assertRedirect();

    expect($this->me->tokens()->pluck('name')->all())->toBe(['new phone'])
        ->and($theirs->accessToken->fresh())->not->toBeNull();
});
