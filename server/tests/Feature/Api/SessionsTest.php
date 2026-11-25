<?php

use App\Models\Device;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'konstantina@example.com', 'password' => 'correct-horse-battery']);
});

test('lists the phones signed in, marking this one', function () {
    $this->user->createToken('android phone');
    $mine = $this->user->createToken('ios phone')->plainTextToken;

    $this->withToken($mine)->getJson('/api/sessions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'ios phone')
        ->assertJsonPath('data.0.current', true)
        ->assertJsonPath('data.1.current', false);
});

test('signing out the other phones keeps this one and stops their pushes', function () {
    $lost = $this->user->createToken('lost phone');
    Device::forceCreate(['user_id' => $this->user->id, 'personal_access_token_id' => $lost->accessToken->id, 'expo_token' => 'ExponentPushToken[lost]']);
    $this->user->createToken('tablet');
    $mine = $this->user->createToken('my phone')->plainTextToken;

    $this->withToken($mine)->deleteJson('/api/sessions/others')->assertOk()->assertJsonPath('revoked', 2);

    expect($this->user->tokens()->pluck('name')->all())->toBe(['my phone'])
        ->and(Device::count())->toBe(0);
    app('auth')->forgetGuards();
    $this->withToken($lost->plainTextToken)->getJson('/api/me')->assertUnauthorized();
    $this->withToken($mine)->getJson('/api/me')->assertOk();
});

test('signs out one phone, but only your own', function () {
    $other = User::factory()->create()->createToken('theirs');
    $old = $this->user->createToken('old phone');
    $mine = $this->user->createToken('my phone')->plainTextToken;

    $this->withToken($mine)->deleteJson("/api/sessions/{$other->accessToken->id}")->assertNotFound();
    $this->withToken($mine)->deleteJson("/api/sessions/{$old->accessToken->id}")->assertNoContent();

    expect($this->user->tokens()->count())->toBe(1)
        ->and($other->accessToken->fresh())->not->toBeNull();
});

test('the token login refuses an unconfirmed email', function () {
    $this->user->forceFill(['email_verified_at' => null])->save();

    $this->postJson('/api/login', ['email' => 'konstantina@example.com', 'password' => 'correct-horse-battery'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
    expect($this->user->tokens()->count())->toBe(0);
});

test('with two-factor on, the token login asks for the code and checks it', function () {
    $google = app(Google2FA::class);
    $secret = $google->generateSecretKey();
    $this->user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $login = ['email' => 'konstantina@example.com', 'password' => 'correct-horse-battery'];

    $this->postJson('/api/login', $login)->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/login', [...$login, 'code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');
    expect($this->user->tokens()->count())->toBe(0);

    $this->postJson('/api/login', [...$login, 'code' => $google->getCurrentOtp($secret)])->assertOk()->assertJsonStructure(['token']);
});

test('a recovery code works once instead of the authenticator code', function () {
    $this->user->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $login = ['email' => 'konstantina@example.com', 'password' => 'correct-horse-battery', 'recovery_code' => 'recovery-code-1'];

    $this->postJson('/api/login', $login)->assertOk();
    $this->postJson('/api/login', $login)->assertUnprocessable()->assertJsonValidationErrors('recovery_code');
    expect($this->user->fresh()->recoveryCodes())->not->toContain('recovery-code-1');
});
