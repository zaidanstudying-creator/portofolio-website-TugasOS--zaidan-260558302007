<?php

use App\Models\User;
use App\Services\TwoFactorAuthenticationService;

test('two factor challenge redirects to login without a pending sign-in', function () {
    $response = $this->get(route('two-factor.login'));

    $response->assertRedirect(route('login'));
});

test('two-factor challenge rejects malformed pending session identifiers', function () {
    $this->withSession(['login.id' => [1, 2], 'login.remember' => true])
        ->post(route('two-factor.login.store'), ['code' => '123456'])
        ->assertRedirect(route('login'))
        ->assertSessionMissing('login.id');

    $this->assertGuest();
});

test('two-factor login requires a challenge before authenticating the user', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
    $this->get(route('two-factor.login'))->assertOk()->assertSee('Verifikasi akun');

    $this->post(route('two-factor.login.store'), ['code' => 'recovery-code-1'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(app(TwoFactorAuthenticationService::class)->verify($user->fresh(), 'recovery-code-1'))->toBeFalse();
});

test('invalid two-factor codes do not authenticate the user', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), ['code' => 'incorrect'])
        ->assertSessionHasErrors('code')
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

test('the custom TOTP verifier accepts the RFC 6238 SHA-1 test vector and recovery codes are single use', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
    ])->save();
    $service = app(TwoFactorAuthenticationService::class);
    $this->travelTo(now()->setTimestamp(59));

    expect($service->verifyTotp($user, '287082'))->toBeTrue();

    $recoveryCodes = $service->confirmSetup($user, '287082');

    expect($recoveryCodes)->toHaveCount(10)
        ->and($service->hasEnabled($user->fresh()))->toBeTrue()
        ->and($service->verify($user->fresh(), $recoveryCodes[0]))->toBeTrue()
        ->and($service->verify($user->fresh(), $recoveryCodes[0]))->toBeFalse();
});
