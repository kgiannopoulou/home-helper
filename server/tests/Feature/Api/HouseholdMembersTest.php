<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Konstantina']);
    $this->member = User::factory()->create(['name' => 'Alex']);
    $this->home = Household::factory()->create(['name' => 'Patission 12']);
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->home->addMember($this->member);
});

test('an owner removes a member, whose access ends at once', function () {
    $alexToken = $this->member->createToken('phone')->plainTextToken;
    $this->withToken($alexToken)->getJson("/api/households/{$this->home->id}/shopping-items")->assertOk();

    Sanctum::actingAs($this->owner);
    $this->deleteJson("/api/households/{$this->home->id}/members/{$this->member->id}")->assertNoContent();

    expect($this->member->isMemberOf($this->home))->toBeFalse();
    app('auth')->forgetGuards();
    $this->withToken($alexToken)->getJson("/api/households/{$this->home->id}/shopping-items")->assertForbidden();
    $this->withToken($alexToken)->postJson("/api/households/{$this->home->id}/sync", ['changes' => []])->assertForbidden();
});

test('removing someone deletes their unused invites so an old link cannot bring them back', function () {
    $this->home->invites()->create(['email' => strtolower($this->member->email), 'role' => HouseholdRole::Member, 'invited_by' => $this->owner->id, 'expires_at' => now()->addDays(7)]);

    Sanctum::actingAs($this->owner);
    $this->deleteJson("/api/households/{$this->home->id}/members/{$this->member->id}")->assertNoContent();

    expect($this->home->invites()->count())->toBe(0);
});

test('a member can leave but cannot remove anyone else', function () {
    $third = User::factory()->create();
    $this->home->addMember($third);
    Sanctum::actingAs($this->member);

    $this->deleteJson("/api/households/{$this->home->id}/members/{$third->id}")->assertForbidden();
    $this->deleteJson("/api/households/{$this->home->id}/members/{$this->owner->id}")->assertForbidden();
    $this->deleteJson("/api/households/{$this->home->id}/members/{$this->member->id}")->assertNoContent();

    expect($this->member->isMemberOf($this->home))->toBeFalse()
        ->and($third->isMemberOf($this->home))->toBeTrue();
});

test('the last owner cannot leave until someone else is owner', function () {
    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/households/{$this->home->id}/members/{$this->owner->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('member');
    expect($this->owner->roleIn($this->home))->toBe(HouseholdRole::Owner);
});

test('outsiders cannot remove members', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->deleteJson("/api/households/{$this->home->id}/members/{$this->member->id}")->assertForbidden();
    expect($this->member->isMemberOf($this->home))->toBeTrue();
});

test('removing someone who is not a member is a validation error', function () {
    Sanctum::actingAs($this->owner);

    $this->deleteJson("/api/households/{$this->home->id}/members/".User::factory()->create()->id)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('member');
});

test('an owner hands the household over and becomes a member', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson("/api/households/{$this->home->id}/owner", ['user_id' => $this->member->id])
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Alex')
        ->assertJsonPath('data.0.role', 'owner')
        ->assertJsonPath('data.1.role', 'member');

    expect($this->member->roleIn($this->home))->toBe(HouseholdRole::Owner)
        ->and($this->owner->roleIn($this->home))->toBe(HouseholdRole::Member);

    // ...and then can leave, since Alex owns it now
    $this->deleteJson("/api/households/{$this->home->id}/members/{$this->owner->id}")->assertNoContent();
});

test('ownership only goes to members, and only owners give it', function () {
    Sanctum::actingAs($this->owner);
    $this->postJson("/api/households/{$this->home->id}/owner", ['user_id' => User::factory()->create()->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');

    Sanctum::actingAs($this->member);
    $this->postJson("/api/households/{$this->home->id}/owner", ['user_id' => $this->member->id])->assertForbidden();
});
