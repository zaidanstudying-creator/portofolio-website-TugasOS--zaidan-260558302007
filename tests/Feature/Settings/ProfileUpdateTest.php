<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
});

test('profile updates do not require email verification', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'updated@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email)->toBe('updated@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

test('user can delete their account', function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake([
        'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
    ]);

    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $ownedPath = 'tasks/'.$task->id.'/private-file.pdf';
    Storage::disk('local')->put($ownedPath, 'private file contents');
    $user->taskFiles()->create([
        'task_id' => $task->id,
        'kind' => 'reference',
        'original_name' => 'private-file.pdf',
        'disk' => 'local',
        'path' => $ownedPath,
        'byte_size' => 21,
    ]);

    $otherUser = User::factory()->create();
    $otherTask = Task::factory()->for($otherUser)->create();
    $otherPath = 'tasks/'.$otherTask->id.'/other-private-file.pdf';
    Storage::disk('local')->put($otherPath, 'other private file contents');
    $otherUser->taskFiles()->create([
        'task_id' => $otherTask->id,
        'kind' => 'reference',
        'original_name' => 'other-private-file.pdf',
        'disk' => 'local',
        'path' => $otherPath,
        'byte_size' => 27,
    ]);
    $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'google-access-token',
        'refresh_token' => 'google-refresh-token',
        'status' => 'connected',
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    Storage::disk('local')->assertMissing($ownedPath);
    Storage::disk('local')->assertExists($otherPath);
    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke'
        && in_array($request['token'], ['google-access-token', 'google-refresh-token'], true));
});

test('account deletion preserves data when an external grant cannot be revoked', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://oauth2.googleapis.com/revoke' => Http::response(['error' => 'temporarily_unavailable'], 503),
    ]);

    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'google-access-token',
        'refresh_token' => 'google-refresh-token',
        'status' => 'connected',
    ]);
    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors('account')
        ->assertSee('Akun dan datanya tetap tersimpan');

    expect($user->fresh())->not->toBeNull()
        ->and($connection->fresh())->not->toBeNull()
        ->and(auth()->check())->toBeTrue();
});

test('account deletion explains missing GitHub OAuth configuration and preserves the account', function () {
    config([
        'services.github.client_id' => null,
        'services.github.client_secret' => null,
    ]);
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'github',
        'access_token' => 'github-access-token',
        'scopes' => ['read:user'],
        'status' => 'connected',
    ]);
    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors('account')
        ->assertSee('GITHUB_CLIENT_ID')
        ->assertSee('GITHUB_CLIENT_SECRET');

    expect($user->fresh())->not->toBeNull()
        ->and($connection->fresh())->not->toBeNull()
        ->and(auth()->check())->toBeTrue();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});
