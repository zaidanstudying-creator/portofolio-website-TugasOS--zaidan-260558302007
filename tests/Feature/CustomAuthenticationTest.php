<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('custom login screen is served without starter kit branding', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Masuk ke ruang kerja')
        ->assertDontSee('Laravel');
});

test('guest home displays the TUGAS!OS landing page instead of Laravel welcome', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Semua tugas, satu kendali.')
        ->assertSee('TUGAS!OS')
        ->assertDontSee("Let's get started")
        ->assertDontSee('Laravel');
});

test('a visitor can register and is signed in', function () {
    $this->post(route('register.store'), [
        'name' => 'Ihsan',
        'email' => 'Ihsan@Example.com',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::where('email', 'ihsan@example.com')->firstOrFail();

    expect(Hash::check('secure-password', $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

test('registration rejects an existing email regardless of letter case', function () {
    User::factory()->create(['email' => 'student@example.com']);

    $this->post(route('register.store'), [
        'name' => 'Another Student',
        'email' => 'STUDENT@EXAMPLE.COM',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
    ])->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(1);
    $this->assertGuest();
});

test('registration attempts are rate limited per client address', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('register.store'), [])
            ->assertSessionHasErrors(['name', 'email', 'password']);
    }

    $this->post(route('register.store'), [])->assertTooManyRequests();

    expect(User::query()->count())->toBe(0);
});

test('a user can sign in and sign out through application routes', function () {
    $user = User::factory()->create(['email' => 'student@example.com']);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => '1',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'))->assertRedirect(route('home'));
    $this->assertGuest();
});

test('invalid credentials are rejected', function () {
    $this->post(route('login.store'), [
        'email' => 'missing@example.com',
        'password' => 'incorrect-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('custom login blocks additional password attempts after five failures', function () {
    User::factory()->create(['email' => 'limited-login@example.com']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('login.store'), [
            'email' => 'limited-login@example.com',
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors('email');
    }

    $this->post(route('login.store'), [
        'email' => 'limited-login@example.com',
        'password' => 'incorrect-password',
    ])->assertSessionHasErrors([
        'email' => 'Terlalu banyak percobaan masuk. Coba lagi dalam satu menit.',
    ]);

    $this->assertGuest();
});

test('password recovery does not reveal whether an email is registered', function () {
    $this->post(route('password.email'), ['email' => 'unknown@example.com'])
        ->assertSessionHas('status', 'Jika email terdaftar, tautan reset akan dikirim.');
});
