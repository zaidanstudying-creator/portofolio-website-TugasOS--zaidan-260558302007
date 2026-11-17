<?php

use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

test('security settings page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'));

    $response->assertOk();

    $response->assertSee('Verifikasi dua langkah');
    $response->assertSee('Siapkan verifikasi dua langkah');
});

test('security settings page requires authentication', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('security.edit'));

    $response->assertOk();
});

test('security settings page renders when two factor is disabled', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Verifikasi dua langkah')
        ->assertSee('Nonaktif');
});

test('two-factor setup remains disabled until a valid code is confirmed', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Livewire::test('pages::settings.security')
        ->set('twoFactorPassword', 'password')
        ->call('startTwoFactorSetup')
        ->assertSet('manualSetupKey', fn (string $secret): bool => $secret !== '')
        ->set('twoFactorCode', '000000')
        ->call('confirmTwoFactorSetup')
        ->assertHasErrors('twoFactorCode');

    expect(app(TwoFactorAuthenticationService::class)->hasEnabled($user->refresh()))->toBeFalse()
        ->and($user->refresh()->two_factor_secret)->not->toBeNull();
});

test('an enabled two-factor setup cannot be replaced without disabling it first', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user);
    Livewire::test('pages::settings.security')
        ->set('twoFactorPassword', 'password')
        ->call('startTwoFactorSetup')
        ->assertHasErrors('twoFactorPassword')
        ->assertSet('manualSetupKey', '');
});

test('two-factor recovery code regeneration is throttled after repeated invalid codes', function () {
    $user = User::factory()->withTwoFactor()->create();
    $throttleKey = 'two-factor-security:'.$user->id;
    RateLimiter::clear($throttleKey);

    $component = Livewire::actingAs($user)
        ->test('pages::settings.security');

    foreach (range(1, 5) as $attempt) {
        $component->set('twoFactorCode', '123456')
            ->call('regenerateTwoFactorRecoveryCodes')
            ->assertHasErrors('twoFactorCode');
    }

    $component->set('twoFactorCode', '123456')
        ->call('regenerateTwoFactorRecoveryCodes')
        ->assertSee('Terlalu banyak percobaan. Coba lagi dalam satu menit.');

    expect(json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true))
        ->toBe(['recovery-code-1']);
});

test('two-factor deactivation is throttled after repeated incorrect password checks', function () {
    $user = User::factory()->withTwoFactor()->create();
    $throttleKey = 'two-factor-disable-password:'.$user->id;
    RateLimiter::clear($throttleKey);

    $component = Livewire::actingAs($user)
        ->test('pages::settings.security');

    foreach (range(1, 5) as $attempt) {
        $component->set('twoFactorPassword', 'incorrect-password')
            ->set('twoFactorCode', 'recovery-code-1')
            ->call('disableTwoFactor')
            ->assertHasErrors('twoFactorPassword');
    }

    $component->set('twoFactorPassword', 'incorrect-password')
        ->set('twoFactorCode', 'recovery-code-1')
        ->call('disableTwoFactor')
        ->assertSee('Terlalu banyak percobaan. Coba lagi dalam satu menit.');

    expect(app(TwoFactorAuthenticationService::class)->hasEnabled($user->fresh()))->toBeTrue();
});

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.security')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});

test('updating a password keeps the current session and rejects sessions with the old password hash', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $oldPasswordHash = $user->password;

    $this->actingAs($user);

    Livewire::test('pages::settings.security')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);

    $this->actingAs($user->fresh())
        ->withSession(['password_hash_web' => $oldPasswordHash])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
