<?php

use App\Jobs\SyncGoogleCalendar;
use App\Jobs\UploadGoogleDriveTaskFile;
use App\Models\SyncEvent;
use App\Models\Task;
use App\Models\User;
use App\Services\GitHubRepositoryService;
use App\Services\GoogleAccessTokenService;
use App\Services\GoogleCalendarSyncService;
use App\Services\GoogleDriveSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('Google OAuth callback validates state and encrypts stored tokens', function () {
    config([
        'services.google.client_id' => 'client-id',
        'services.google.client_secret' => 'client-secret',
        'services.google.redirect' => 'https://mytask.test/integrations/google/callback',
    ]);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'access-token-secret',
            'refresh_token' => 'refresh-token-secret',
            'expires_in' => 3600,
            'scope' => 'https://www.googleapis.com/auth/calendar.events',
        ]),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([
            'oauth.google.state' => 'csrf-state',
            'oauth.google.verifier' => 'pkce-verifier',
        ])
        ->get(route('integrations.google.callback', ['state' => 'csrf-state', 'code' => 'authorization-code']))
        ->assertRedirect(route('integrations.index'))
        ->assertSessionHas('status');

    $connection = $user->integrations()->where('provider', 'google')->firstOrFail();
    $stored = DB::table('integration_connections')->where('id', $connection->id)->value('access_token');

    expect($connection->access_token)->toBe('access-token-secret')
        ->and($connection->refresh_token)->toBe('refresh-token-secret')
        ->and($stored)->not->toBe('access-token-secret')
        ->and($connection->toArray())->not->toHaveKey('access_token')
        ->and($connection->toArray())->not->toHaveKey('refresh_token')
        ->and(json_encode($connection, JSON_THROW_ON_ERROR))->not->toContain('access-token-secret')
        ->and(json_encode($connection, JSON_THROW_ON_ERROR))->not->toContain('refresh-token-secret')
        ->and($connection->status)->toBe('connected');
});

test('Google OAuth state mismatch does not exchange or store credentials', function () {
    Http::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['oauth.google.state' => 'expected-state', 'oauth.google.verifier' => 'verifier'])
        ->get(route('integrations.google.callback', ['state' => 'attacker-state', 'code' => 'authorization-code']))
        ->assertRedirect(route('integrations.index'))
        ->assertSessionHasErrors('google');

    expect($user->integrations()->count())->toBe(0);
    Http::assertNothingSent();
});

test('Google reconnect preserves the existing connection when no refresh token is returned', function () {
    config([
        'services.google.client_id' => 'client-id',
        'services.google.client_secret' => 'client-secret',
        'services.google.redirect' => 'https://mytask.test/integrations/google/callback',
    ]);
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'access-token-for-another-account',
            'expires_in' => 3600,
        ]),
    ]);
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'existing-access-token',
        'refresh_token' => 'existing-refresh-token',
        'status' => 'connected',
    ]);

    $this->actingAs($user)
        ->withSession([
            'oauth.google.state' => 'csrf-state',
            'oauth.google.verifier' => 'pkce-verifier',
        ])
        ->get(route('integrations.google.callback', ['state' => 'csrf-state', 'code' => 'authorization-code']))
        ->assertRedirect(route('integrations.index'))
        ->assertSessionHasErrors('google');

    expect($connection->fresh()->access_token)->toBe('existing-access-token')
        ->and($connection->fresh()->refresh_token)->toBe('existing-refresh-token')
        ->and($connection->fresh()->status)->toBe('connected');
});

test('Google marks a revoked refresh token for reconnection and preserves its local credentials', function () {
    config([
        'services.google.client_id' => 'client-id',
        'services.google.client_secret' => 'client-secret',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'expired-access-token',
        'refresh_token' => 'revoked-refresh-token',
        'expires_at' => now()->subMinute(),
        'status' => 'connected',
    ]);

    expect(fn () => app(GoogleAccessTokenService::class)->for($connection))
        ->toThrow(RuntimeException::class, 'Akses Google perlu diperbarui.');

    expect($connection->fresh()->status)->toBe('reauth_required')
        ->and($connection->fresh()->last_error)->toContain('Hubungkan ulang')
        ->and($connection->fresh()->access_token)->toBe('expired-access-token')
        ->and($connection->fresh()->refresh_token)->toBe('revoked-refresh-token');

    Livewire::actingAs($user)
        ->test('pages::integrations')
        ->assertSee('Perlu hubungkan ulang');
});

test('Google refreshes access tokens that are about to expire', function () {
    config([
        'services.google.client_id' => 'client-id',
        'services.google.client_secret' => 'client-secret',
    ]);
    $this->travelTo('2026-11-08 12:00:00');
    Http::preventStrayRequests();
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'refreshed-access-token',
            'expires_in' => 3600,
        ]),
    ]);

    $connection = User::factory()->create()->integrations()->create([
        'provider' => 'google',
        'access_token' => 'expiring-access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addSeconds(30),
        'status' => 'connected',
    ]);

    expect(app(GoogleAccessTokenService::class)->for($connection))->toBe('refreshed-access-token')
        ->and($connection->fresh()->access_token)->toBe('refreshed-access-token')
        ->and($connection->fresh()->expires_at->isAfter(now()->addMinutes(59)))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request['refresh_token'] === 'refresh-token');
});

test('failed Google background jobs preserve the reconnection message', function () {
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'expired-access-token',
        'refresh_token' => 'revoked-refresh-token',
        'status' => 'reauth_required',
        'last_error' => 'Akses Google perlu diperbarui. Hubungkan ulang akun Google.',
    ]);
    $event = $connection->syncEvents()->create([
        'provider' => 'google_calendar',
        'direction' => 'outbound',
        'action' => 'calendar_sync',
        'status' => 'running',
        'message' => 'Sinkronisasi sedang berjalan.',
    ]);

    (new SyncGoogleCalendar($connection->id, $event->id))->failed(null);
    (new UploadGoogleDriveTaskFile($connection->id, 1, $event->id))->failed(null);

    expect($connection->fresh()->last_error)->toBe('Akses Google perlu diperbarui. Hubungkan ulang akun Google.');
});

test('Google disconnect keeps local credentials when remote revocation is not confirmed', function () {
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'status' => 'connected',
    ]);
    Http::fake([
        'https://oauth2.googleapis.com/revoke' => Http::response(['error' => 'temporarily_unavailable'], 503),
    ]);

    $this->actingAs($user)
        ->delete(route('integrations.google.disconnect'))
        ->assertRedirect(route('integrations.index'))
        ->assertSessionHasErrors('google');

    expect($connection->fresh()->status)->toBe('connected')
        ->and($connection->fresh()->access_token)->toBe('access-token');
});

test('Google disconnect removes the local connection after provider revocation succeeds', function () {
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'status' => 'connected',
    ]);
    Http::fake([
        'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
    ]);

    $this->actingAs($user)
        ->delete(route('integrations.google.disconnect'))
        ->assertRedirect(route('integrations.index'))
        ->assertSessionHas('status', 'Koneksi Google dihapus dari TUGAS!OS.');

    expect($connection->fresh())->toBeNull();
});

test('Google Calendar sync updates mapped schedules and deadlines without creating duplicates', function () {
    config(['services.google.calendar_timezone' => 'Asia/Jakarta']);
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create([
        'title' => 'Laporan semester',
        'due_at' => '2026-11-12 23:59:00',
    ]);
    $schedule = $task->schedules()->create([
        'user_id' => $user->id,
        'label' => 'Sesi pengerjaan',
        'starts_at' => '2026-11-10 14:30:00',
        'ends_at' => '2026-11-10 16:00:00',
    ]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $methodCalls = [];
    $patchCount = 0;
    $postCount = 0;
    $payloads = [];

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$methodCalls, &$patchCount, &$postCount, &$payloads) {
        $methodCalls[] = $request->method();
        $payloads[] = $request->data();

        if ($request->method() === 'PATCH') {
            $patchCount++;

            return $patchCount === 1
                ? Http::response([], 404)
                : Http::response(['id' => $request->data()['id']], 200);
        }

        if (strtoupper($request->method()) === 'POST') {
            $postCount++;

            return Http::response(['id' => $request->data()['id']], 200);
        }

        return Http::response([], 204);
    });

    $service = app(GoogleCalendarSyncService::class);

    $firstSyncCount = $service->sync($user);

    expect($firstSyncCount)->toBe(2)
        ->and(collect($payloads)->firstWhere('summary', 'Sesi pengerjaan')['start']['dateTime'])->toBe('2026-11-10T14:30:00+07:00')
        ->and(collect($payloads)->firstWhere('summary', 'Sesi pengerjaan')['start']['timeZone'])->toBe('Asia/Jakarta')
        ->and(collect($payloads)->firstWhere('summary', 'Deadline · Laporan semester')['start']['dateTime'])->toBe('2026-11-12T23:59:00+07:00')
        ->and($connection->mappings()->where('resource_type', 'task_schedule')->where('internal_id', (string) $schedule->id)->count())->toBe(1)
        ->and($connection->mappings()->where('resource_type', 'task_deadline')->where('internal_id', (string) $task->id)->count())->toBe(1);

    expect($service->sync($user))->toBe(2)
        ->and($postCount)->toBe(3)
        ->and($connection->refresh()->last_synced_at)->not->toBeNull();

    $task->update(['archived_at' => now()]);

    expect($service->sync($user))->toBe(0)
        ->and($connection->mappings()->count())->toBe(0);
});

test('Google Calendar sync retries a transient error on an idempotent event update', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['due_at' => now()->addDay()]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $connection->mappings()->create([
        'resource_type' => 'task_deadline',
        'internal_id' => (string) $task->id,
        'external_id' => 'existing-calendar-event',
        'external_container_id' => 'primary',
        'status' => 'synced',
    ]);
    $requestCount = 0;
    Http::fake(function (Request $request) use (&$requestCount) {
        $requestCount++;

        if ($requestCount === 1) {
            return Http::response(['error' => ['message' => 'Temporary issue']], 503, ['Retry-After' => '0']);
        }

        return Http::response(['id' => $request->data()['id']]);
    });

    $synchronized = app(GoogleCalendarSyncService::class)->sync($user);

    expect($synchronized)->toBe(1)
        ->and($requestCount)->toBe(2)
        ->and($connection->refresh()->last_error)->toBeNull()
        ->and($connection->mappings()->where('resource_type', 'task_deadline')->exists())->toBeTrue();
});

test('Google Calendar does not overwrite an unrelated event when a create ID conflicts', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['due_at' => now()->addDay()]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $eventId = substr(hash('sha256', 'tugasos-deadline-'.$task->id), 0, 32);
    $methods = [];
    Http::fake(function (Request $request) use (&$methods, $eventId) {
        $methods[] = $request->method();

        if ($request->method() === 'POST') {
            return Http::response(['error' => ['message' => 'ID already exists']], 409);
        }

        if ($request->method() === 'GET') {
            return Http::response([
                'id' => $eventId,
                'summary' => 'Personal event',
                'extendedProperties' => ['private' => ['createdBy' => 'user']],
            ]);
        }

        return Http::response([], 500);
    });

    expect(app(GoogleCalendarSyncService::class)->sync($user))->toBe(0)
        ->and($methods)->toBe(['POST', 'GET'])
        ->and($connection->mappings()->where('resource_type', 'task_deadline')->exists())->toBeFalse()
        ->and($connection->syncEvents()->where('status', 'failed')->value('message'))
        ->toContain('unrelated event');
});

test('Google Calendar recovers an idempotent create after a confirmed matching event conflict', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['due_at' => now()->addDay()]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $eventId = substr(hash('sha256', 'tugasos-deadline-'.$task->id), 0, 32);
    Http::fake(function (Request $request) use ($eventId, $task) {
        if ($request->method() === 'POST') {
            return Http::response(['error' => ['message' => 'ID already exists']], 409);
        }

        if ($request->method() === 'GET') {
            return Http::response([
                'id' => $eventId,
                'summary' => 'Deadline · '.$task->title,
                'extendedProperties' => ['private' => ['tugasosTaskDeadlineId' => (string) $task->id]],
            ]);
        }

        return Http::response([], 500);
    });

    expect(app(GoogleCalendarSyncService::class)->sync($user))->toBe(1)
        ->and($connection->mappings()->where('resource_type', 'task_deadline')->value('external_id'))->toBe($eventId)
        ->and($connection->syncEvents()->where('status', 'failed')->count())->toBe(0);
});

test('Google Calendar sync stops when the provider requests a long rate limit delay', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    Task::factory()->for($user)->create(['due_at' => now()->addDay()]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $requestCount = 0;
    Http::fake(function () use (&$requestCount) {
        $requestCount++;

        return Http::response(['error' => ['message' => 'Rate limit exceeded']], 429, ['Retry-After' => '60']);
    });

    $synchronized = app(GoogleCalendarSyncService::class)->sync($user);

    expect($synchronized)->toBe(0)
        ->and($requestCount)->toBe(1)
        ->and($connection->refresh()->last_error)->toBe('1 item(s) failed to synchronize.')
        ->and($connection->syncEvents()->where('status', 'failed')->value('message'))->toBe('Google Calendar returned HTTP 429.');
});

test('Google Calendar target selection only saves a calendar the user can edit', function () {
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'scopes' => ['https://www.googleapis.com/auth/calendar.calendarlist.readonly'],
        'status' => 'connected',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
            'items' => [
                ['id' => 'primary@example.test', 'summary' => 'Pribadi', 'primary' => true, 'accessRole' => 'owner'],
                ['id' => 'shared@example.test', 'summary' => 'Kuliah', 'primary' => false, 'accessRole' => 'writer'],
                ['id' => 'read-only@example.test', 'summary' => 'Baca saja', 'primary' => false, 'accessRole' => 'reader'],
            ],
        ]),
    ]);

    Livewire::actingAs($user)
        ->test('pages::integrations')
        ->call('loadGoogleCalendars')
        ->assertSet('calendarId', 'primary@example.test')
        ->assertSee('Kuliah')
        ->set('calendarId', 'shared@example.test')
        ->call('saveGoogleCalendar')
        ->assertHasNoErrors();

    expect($connection->refresh()->settings['calendar_id'])->toBe('shared@example.test');

    Livewire::actingAs($user)
        ->test('pages::integrations')
        ->set('calendarId', 'read-only@example.test')
        ->call('saveGoogleCalendar')
        ->assertHasErrors('calendar');

    expect($connection->refresh()->settings['calendar_id'])->toBe('shared@example.test');
});

test('Google Calendar import creates one linked task and preserves the source event when unlinked', function () {
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'scopes' => ['https://www.googleapis.com/auth/calendar.events'],
        'status' => 'connected',
    ]);
    $start = now()->addDay()->toRfc3339String();
    $end = now()->addDay()->addHour()->toRfc3339String();
    $httpMethods = [];
    $event = [
        'id' => 'google-event-1',
        'status' => 'confirmed',
        'summary' => 'Bimbingan proyek',
        'description' => 'Bahas kemajuan proyek semester.',
        'start' => ['dateTime' => $start],
        'end' => ['dateTime' => $end],
        'htmlLink' => 'https://calendar.google.com/calendar/event?eid=event',
    ];

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$httpMethods, $event) {
        $httpMethods[] = $request->method();
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && str_ends_with((string) $path, '/events/google-event-1')) {
            return Http::response($event);
        }

        if ($request->method() === 'GET' && str_ends_with((string) $path, '/events')) {
            return Http::response(['items' => [
                $event,
                [
                    'id' => 'tugasos-event',
                    'status' => 'confirmed',
                    'summary' => 'Sudah di TUGAS!OS',
                    'start' => ['dateTime' => $event['start']['dateTime']],
                    'end' => ['dateTime' => $event['end']['dateTime']],
                    'extendedProperties' => ['private' => ['tugasosScheduleId' => '12']],
                ],
                [
                    'id' => 'recurring-event',
                    'status' => 'confirmed',
                    'summary' => 'Agenda berulang',
                    'start' => ['dateTime' => $event['start']['dateTime']],
                    'end' => ['dateTime' => $event['end']['dateTime']],
                    'recurringEventId' => 'recurring-master',
                ],
            ]]);
        }

        return Http::response([], 204);
    });

    Livewire::actingAs($user)
        ->test('pages::integrations')
        ->call('loadGoogleImportEvents')
        ->assertSee('Bimbingan proyek')
        ->assertDontSee('Sudah di TUGAS!OS')
        ->assertDontSee('Agenda berulang')
        ->set('googleEventId', 'google-event-1')
        ->call('importGoogleEvent')
        ->assertHasNoErrors()
        ->assertSee('diimpor sebagai tugas');

    $task = $user->tasks()->where('title', 'Bimbingan proyek')->firstOrFail();
    $schedule = $task->schedules()->firstOrFail();
    $mapping = $connection->mappings()->where('resource_type', 'task_schedule')->where('internal_id', (string) $schedule->id)->firstOrFail();

    expect($task->due_at)->toBeNull()
        ->and($schedule->is_all_day)->toBeFalse()
        ->and($mapping->external_id)->toBe('google-event-1')
        ->and($mapping->status)->toBe('imported');

    $service = app(GoogleCalendarSyncService::class);
    expect(fn () => $service->importEvent($user, 'google-event-1'))
        ->toThrow(RuntimeException::class, 'This Google Calendar event is already linked to TUGAS!OS.')
        ->and($user->tasks()->where('title', 'Bimbingan proyek')->count())->toBe(1);

    expect($service->sync($user))->toBe(0);

    $task->delete();
    expect($service->sync($user))->toBe(0)
        ->and($connection->mappings()->where('external_id', 'google-event-1')->exists())->toBeFalse()
        ->and($httpMethods)->not->toContain('PATCH')
        ->and($httpMethods)->not->toContain('DELETE');
});

test('integration page is available when providers are disconnected', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('integrations.index'))
        ->assertOk()
        ->assertSee('Belum terhubung')
        ->assertSee('Hubungkan Google untuk mengaktifkan upload');
});

test('Google Calendar sync is queued once and records completion status', function () {
    Queue::fake([SyncGoogleCalendar::class]);
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'scopes' => ['https://www.googleapis.com/auth/calendar.events'],
        'status' => 'connected',
    ]);

    Livewire::actingAs($user)
        ->test('pages::integrations')
        ->call('syncCalendar')
        ->assertHasNoErrors()
        ->assertSee('masuk antrean')
        ->call('syncCalendar')
        ->assertHasNoErrors();

    Queue::assertPushedTimes(SyncGoogleCalendar::class, 1);

    $event = SyncEvent::query()->where('provider', 'google_calendar')->firstOrFail();
    expect($event->status)->toBe('queued')
        ->and($event->connection->is($connection))->toBeTrue();

    (new SyncGoogleCalendar($connection->id, $event->id))->handle(app(GoogleCalendarSyncService::class));

    expect($event->fresh()->status)->toBe('succeeded')
        ->and($event->fresh()->context)->toBe(['synchronized_count' => 0])
        ->and($connection->fresh()->last_synced_at)->not->toBeNull();
});

test('task changes automatically queue one Google Calendar sync after commit', function () {
    Queue::fake([SyncGoogleCalendar::class]);
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['title' => 'Judul lama']);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);

    DB::transaction(function () use ($task): void {
        $task->update(['title' => 'Judul baru']);

        expect(SyncEvent::query()->where('action', 'calendar_sync')->count())->toBe(0);
    });

    $event = SyncEvent::query()->where('action', 'calendar_sync')->sole();

    Queue::assertPushedTimes(SyncGoogleCalendar::class, 1);
    expect($event->status)->toBe('queued')
        ->and($event->integration_connection_id)->toBe($connection->id);

});

test('creating a work schedule queues an automatic calendar sync', function () {
    Queue::fake([SyncGoogleCalendar::class]);
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);

    $task->schedules()->create([
        'user_id' => $user->id,
        'starts_at' => now()->addDay()->setTime(9, 0),
        'ends_at' => now()->addDay()->setTime(10, 0),
    ]);

    $event = SyncEvent::query()->where('action', 'calendar_sync')->sole();

    Queue::assertPushed(SyncGoogleCalendar::class);
    expect($event->integration_connection_id)->toBe($connection->id)
        ->and($event->status)->toBe('queued');
});

test('archiving a project queues calendar cleanup for its schedules', function () {
    Queue::fake([SyncGoogleCalendar::class]);
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $project = Task::factory()->for($user)->create(['title' => 'Proyek diarsipkan']);
    $project->schedules()->create([
        'user_id' => $user->id,
        'starts_at' => now()->addDay()->setTime(9, 0),
        'ends_at' => now()->addDay()->setTime(10, 0),
    ]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);

    Livewire::actingAs($user)
        ->test('pages::tasks')
        ->call('archiveTask', $project->id)
        ->assertHasNoErrors();

    $event = SyncEvent::query()->where('action', 'calendar_sync')->sole();

    Queue::assertPushed(SyncGoogleCalendar::class);
    expect($project->fresh()->archived_at)->not->toBeNull()
        ->and($event->integration_connection_id)->toBe($connection->id)
        ->and($event->status)->toBe('queued');
});

test('Google Calendar jobs cannot use one connection to complete another connections event', function () {
    Http::preventStrayRequests();
    $eventOwner = User::factory()->create();
    $workerOwner = User::factory()->create();
    $eventConnection = $eventOwner->integrations()->create([
        'provider' => 'google',
        'access_token' => 'event-owner-token',
        'refresh_token' => 'event-owner-refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $workerConnection = $workerOwner->integrations()->create([
        'provider' => 'google',
        'access_token' => 'worker-owner-token',
        'refresh_token' => 'worker-owner-refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $event = $eventConnection->syncEvents()->create([
        'provider' => 'google_calendar',
        'direction' => 'outbound',
        'action' => 'calendar_sync',
        'status' => 'queued',
    ]);

    (new SyncGoogleCalendar($workerConnection->id, $event->id))->handle(app(GoogleCalendarSyncService::class));

    expect($event->fresh()->status)->toBe('queued')
        ->and($eventConnection->fresh()->last_synced_at)->toBeNull()
        ->and($workerConnection->fresh()->last_synced_at)->toBeNull();
});

test('a user can verify and link a selected Google Drive file to a task without duplicates', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create(['title' => 'Laporan riset']);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'scopes' => ['https://www.googleapis.com/auth/drive.file'],
        'status' => 'connected',
    ]);
    $metadataRequests = 0;

    Http::fake(function (Request $request) use (&$metadataRequests) {
        $metadataRequests++;

        return Http::response([
            'id' => 'selected-drive-file',
            'name' => 'Research Notes',
            'mimeType' => 'application/pdf',
            'webViewLink' => 'https://drive.google.com/file/d/selected-drive-file/view',
            'trashed' => false,
            'parents' => ['drive-folder'],
        ]);
    });

    Livewire::actingAs($user)
        ->test('pages::integrations')
        ->set('driveLinkFileId', 'selected-drive-file')
        ->set('driveLinkFileName', 'Research Notes')
        ->set('driveLinkTaskId', (string) $task->id)
        ->call('linkDriveFileToTask')
        ->assertHasNoErrors()
        ->assertSee('Research Notes ditautkan ke Laporan riset.');

    $serviceResult = app(GoogleDriveSyncService::class)->linkDriveFile($user, $task, 'selected-drive-file');

    expect($task->links()->count())->toBe(1)
        ->and($task->links()->firstOrFail()->url)->toBe('https://drive.google.com/file/d/selected-drive-file/view')
        ->and($connection->mappings()->where('resource_type', 'drive_file_link')->count())->toBe(1)
        ->and($connection->syncEvents()->where('action', 'link_drive_file')->where('status', 'succeeded')->count())->toBe(2)
        ->and($metadataRequests)->toBe(2)
        ->and($serviceResult['action'])->toBe('unchanged');
});

test('Google Drive refuses trashed files and tasks owned by another user', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $otherTask = Task::factory()->for($otherUser)->create();
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'scopes' => ['https://www.googleapis.com/auth/drive.file'],
        'status' => 'connected',
    ]);
    Http::fake([
        'https://www.googleapis.com/drive/v3/files/trashed-file' => Http::response([
            'id' => 'trashed-file',
            'name' => 'Removed notes',
            'webViewLink' => 'https://drive.google.com/file/d/trashed-file/view',
            'trashed' => true,
        ]),
        'https://www.googleapis.com/drive/v3/files/mismatched-file' => Http::response([
            'id' => 'another-file',
            'name' => 'Unexpected file',
            'webViewLink' => 'https://drive.google.com/file/d/another-file/view',
            'trashed' => false,
        ]),
        'https://www.googleapis.com/drive/v3/files/malicious-link' => Http::response([
            'id' => 'malicious-link',
            'name' => 'Unexpected link',
            'webViewLink' => 'javascript:alert(1)',
            'trashed' => false,
        ]),
    ]);

    expect(fn () => app(GoogleDriveSyncService::class)->linkDriveFile($user, $task, 'trashed-file'))
        ->toThrow(RuntimeException::class);
    expect(fn () => app(GoogleDriveSyncService::class)->linkDriveFile($user, $task, 'mismatched-file'))
        ->toThrow(RuntimeException::class);
    expect(fn () => app(GoogleDriveSyncService::class)->linkDriveFile($user, $task, 'malicious-link'))
        ->toThrow(RuntimeException::class);
    expect(fn () => app(GoogleDriveSyncService::class)->linkDriveFile($user, $otherTask, 'trashed-file'))
        ->toThrow(RuntimeException::class);
    expect($task->links()->count())->toBe(0)
        ->and($otherUser->tasks()->findOrFail($otherTask->id)->links()->count())->toBe(0)
        ->and($connection->mappings()->count())->toBe(0);
});

test('Google Drive sync uploads a vault file and reuses its mapped resource on retry', function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    Storage::disk('local')->put('task-files/'.$user->id.'/'.$task->id.'/final.pdf', 'final document');
    $file = $task->files()->create([
        'user_id' => $user->id,
        'kind' => 'final',
        'original_name' => 'final.pdf',
        'disk' => 'local',
        'path' => 'task-files/'.$user->id.'/'.$task->id.'/final.pdf',
        'mime_type' => 'application/pdf',
        'byte_size' => 14,
        'version' => 1,
        'is_final' => true,
        'sha256' => hash('sha256', 'final document'),
    ]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
        'settings' => ['drive_folder_id' => 'folder-123'],
    ]);
    $uploads = 0;
    $httpMethods = [];
    $uploadBody = '';
    $uploadContentType = '';
    Http::fake(function (Request $request) use (&$uploads, &$httpMethods, &$uploadBody, &$uploadContentType, $file) {
        $httpMethods[] = $request->method();
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && str_ends_with((string) $path, '/drive/v3/files')) {
            return Http::response(['files' => []]);
        }

        if ($request->method() === 'POST' && str_contains((string) $path, '/upload/drive/v3/files')) {
            $uploads++;
            $uploadBody = $request->body();
            $uploadContentType = $request->header('Content-Type')[0] ?? '';

            return Http::response([
                'id' => 'drive-file-123',
                'name' => 'final.pdf',
                'webViewLink' => 'javascript:alert(1)',
                'appProperties' => ['tugasosFileId' => (string) $file->id],
            ]);
        }

        if ($request->method() === 'GET' && str_ends_with((string) $path, '/drive-file-123')) {
            return Http::response([
                'id' => 'drive-file-123',
                'name' => 'final.pdf',
                'webViewLink' => 'javascript:alert(1)',
                'md5Checksum' => md5('final document'),
                'appProperties' => ['tugasosFileId' => (string) $file->id],
            ]);
        }

        return Http::response(['error' => 'Unexpected request'], 500);
    });

    $service = app(GoogleDriveSyncService::class);
    $firstResult = $service->syncFile($user, $file);
    $retryResult = $service->syncFile($user, $file);

    expect($firstResult['id'])->toBe('drive-file-123')
        ->and($firstResult['action'])->toBe('create_file')
        ->and($firstResult['webViewLink'])->toBeNull()
        ->and($retryResult['action'])->toBe('unchanged')
        ->and($retryResult['webViewLink'])->toBeNull()
        ->and($uploads)->toBe(1)
        ->and($httpMethods)->toBe(['GET', 'POST', 'GET'])
        ->and($uploadContentType)->toContain('multipart/related')
        ->and($uploadBody)->toContain('"parents":["folder-123"]')
        ->and($uploadBody)->toContain('"tugasosFileId":"'.$file->id.'"')
        ->and($uploadBody)->toContain('final document');

    expect($connection->mappings()->where('resource_type', 'task_file')->where('internal_id', (string) $file->id)->value('external_id'))
        ->toBe('drive-file-123');
    expect($connection->syncEvents()->where('provider', 'google_drive')->where('status', 'succeeded')->count())->toBe(2);
});

test('Google Drive replaces a mapped remote file when its content changed externally', function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $bytes = 'local final document';
    $path = 'task-files/'.$user->id.'/'.$task->id.'/final.pdf';
    Storage::disk('local')->put($path, $bytes);
    $file = $task->files()->create([
        'user_id' => $user->id,
        'kind' => 'final',
        'original_name' => 'final.pdf',
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'byte_size' => strlen($bytes),
        'version' => 1,
        'is_final' => true,
    ]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $connection->mappings()->create([
        'resource_type' => 'task_file',
        'internal_id' => (string) $file->id,
        'external_id' => 'mapped-drive-file',
        'external_container_id' => 'root',
        'sync_hash' => hash('sha256', $file->original_name."\0".$file->mime_type."\0".$bytes),
        'status' => 'synced',
    ]);
    $uploadedBody = null;
    Http::fake(function (Request $request) use ($file, &$uploadedBody) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && str_ends_with((string) $path, '/mapped-drive-file')) {
            return Http::response([
                'id' => 'mapped-drive-file',
                'name' => 'final.pdf',
                'md5Checksum' => md5('modified remotely'),
                'appProperties' => ['tugasosFileId' => (string) $file->id],
                'parents' => ['root'],
            ]);
        }

        if ($request->method() === 'PATCH' && str_contains((string) $path, '/upload/drive/v3/files/mapped-drive-file')) {
            $uploadedBody = $request->body();

            return Http::response(['id' => 'mapped-drive-file', 'name' => 'final.pdf']);
        }

        return Http::response(['error' => 'Unexpected request'], 500);
    });

    $result = app(GoogleDriveSyncService::class)->syncFile($user, $file);

    expect($result['action'])->toBe('update_file')
        ->and($result['id'])->toBe('mapped-drive-file')
        ->and($uploadedBody)->toContain($bytes)
        ->and($connection->mappings()->where('resource_type', 'task_file')->value('status'))->toBe('synced');
});

test('Google Drive recreates a trashed mapped file instead of updating it', function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $path = 'task-files/'.$user->id.'/'.$task->id.'/final.pdf';
    Storage::disk('local')->put($path, 'current final document');
    $file = $task->files()->create([
        'user_id' => $user->id,
        'kind' => 'final',
        'original_name' => 'final.pdf',
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'byte_size' => 22,
        'version' => 1,
        'is_final' => true,
    ]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $connection->mappings()->create([
        'resource_type' => 'task_file',
        'internal_id' => (string) $file->id,
        'external_id' => 'old-trashed-file',
        'external_container_id' => 'root',
        'status' => 'synced',
    ]);
    $requests = [];
    Http::fake(function (Request $request) use (&$requests, $file) {
        $requests[] = [$request->method(), parse_url($request->url(), PHP_URL_PATH)];
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && str_ends_with((string) $path, '/old-trashed-file')) {
            return Http::response([
                'id' => 'old-trashed-file',
                'name' => 'final.pdf',
                'trashed' => true,
                'appProperties' => ['tugasosFileId' => (string) $file->id],
            ]);
        }

        if ($request->method() === 'GET' && str_ends_with((string) $path, '/drive/v3/files')) {
            return Http::response(['files' => []]);
        }

        if ($request->method() === 'POST' && str_contains((string) $path, '/upload/drive/v3/files')) {
            return Http::response([
                'id' => 'replacement-drive-file',
                'name' => 'final.pdf',
                'appProperties' => ['tugasosFileId' => (string) $file->id],
            ]);
        }

        return Http::response(['error' => 'Unexpected request'], 500);
    });

    $result = app(GoogleDriveSyncService::class)->syncFile($user, $file);

    expect($result['id'])->toBe('replacement-drive-file')
        ->and($result['action'])->toBe('create_file')
        ->and($requests)->toHaveCount(3)
        ->and($requests[0][0])->toBe('GET')
        ->and($requests[1][0])->toBe('GET')
        ->and($requests[2][0])->toBe('POST')
        ->and($connection->mappings()->where('resource_type', 'task_file')->value('external_id'))->toBe('replacement-drive-file');
});

test('Google Drive does not overwrite a mapped file owned by another TUGAS!OS file', function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $path = 'task-files/'.$user->id.'/'.$task->id.'/final.pdf';
    Storage::disk('local')->put($path, 'current final document');
    $file = $task->files()->create([
        'user_id' => $user->id,
        'kind' => 'final',
        'original_name' => 'final.pdf',
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'byte_size' => 22,
        'version' => 1,
        'is_final' => true,
    ]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $connection->mappings()->create([
        'resource_type' => 'task_file',
        'internal_id' => (string) $file->id,
        'external_id' => 'another-tugasos-file',
        'external_container_id' => 'root',
        'status' => 'synced',
    ]);
    $methods = [];
    Http::fake(function (Request $request) use (&$methods, $file) {
        $methods[] = $request->method();
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && str_ends_with((string) $path, '/another-tugasos-file')) {
            return Http::response([
                'id' => 'another-tugasos-file',
                'name' => 'other-final.pdf',
                'appProperties' => ['tugasosFileId' => '99999'],
                'trashed' => false,
            ]);
        }

        if ($request->method() === 'GET' && str_ends_with((string) $path, '/drive/v3/files')) {
            return Http::response(['files' => []]);
        }

        if ($request->method() === 'POST' && str_contains((string) $path, '/upload/drive/v3/files')) {
            return Http::response([
                'id' => 'replacement-drive-file',
                'name' => 'final.pdf',
                'appProperties' => ['tugasosFileId' => (string) $file->id],
            ]);
        }

        return Http::response(['error' => 'Unexpected request'], 500);
    });

    $result = app(GoogleDriveSyncService::class)->syncFile($user, $file);

    expect($result['id'])->toBe('replacement-drive-file')
        ->and($methods)->toBe(['GET', 'GET', 'POST'])
        ->and($connection->mappings()->where('resource_type', 'task_file')->value('external_id'))->toBe('replacement-drive-file');
});

test('Google Drive sync uses resumable upload for files larger than five megabytes', function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $bytes = str_repeat('x', 5 * 1024 * 1024 + 1);
    $path = 'task-files/'.$user->id.'/'.$task->id.'/large.zip';
    Storage::disk('local')->put($path, $bytes);
    $file = $task->files()->create([
        'user_id' => $user->id,
        'kind' => 'final',
        'original_name' => 'large.zip',
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'application/zip',
        'byte_size' => strlen($bytes),
        'version' => 1,
        'is_final' => true,
    ]);
    $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $httpMethods = [];
    Http::fake(function (Request $request) use (&$httpMethods) {
        $httpMethods[] = $request->method();
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && str_ends_with((string) $path, '/drive/v3/files')) {
            return Http::response(['files' => []]);
        }

        if ($request->method() === 'POST' && str_contains((string) $path, '/upload/drive/v3/files')) {
            return Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files/session-123']);
        }

        if ($request->method() === 'PUT' && str_ends_with((string) $path, '/session-123')) {
            return Http::response(['id' => 'drive-large-123', 'name' => 'large.zip']);
        }

        return Http::response(['error' => 'Unexpected request'], 500);
    });

    $result = app(GoogleDriveSyncService::class)->syncFile($user, $file);

    expect($result['id'])->toBe('drive-large-123')
        ->and($httpMethods)->toBe(['GET', 'POST', 'PUT']);
});

test('Google Drive uploads are queued and failures stay pending-free without claiming success', function () {
    Http::preventStrayRequests();
    Queue::fake([UploadGoogleDriveTaskFile::class]);
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $path = 'task-files/'.$user->id.'/'.$task->id.'/final.pdf';
    Storage::disk('local')->put($path, 'final document');
    $file = $task->files()->create([
        'user_id' => $user->id,
        'kind' => 'final',
        'original_name' => 'final.pdf',
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'byte_size' => 14,
        'version' => 1,
        'is_final' => true,
    ]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    Livewire::actingAs($user)
        ->test('pages::integrations')
        ->set('driveFileId', (string) $file->id)
        ->call('uploadDriveFile')
        ->assertHasNoErrors()
        ->assertSee('Upload masuk antrean');

    Queue::assertPushed(UploadGoogleDriveTaskFile::class, fn (UploadGoogleDriveTaskFile $job): bool => $job->connectionId === $connection->id
        && $job->fileId === $file->id);
    $event = $connection->syncEvents()->where('provider', 'google_drive')->where('action', 'upload_file')->firstOrFail();

    expect($event->status)->toBe('queued')
        ->and($connection->mappings()->where('resource_type', 'task_file')->count())->toBe(0)
        ->and($connection->fresh()->last_error)->toBeNull();

    Livewire::actingAs($user)
        ->test('pages::integrations')
        ->set('driveFileId', (string) $file->id)
        ->call('uploadDriveFile')
        ->assertHasErrors('drive');

    Queue::assertPushed(UploadGoogleDriveTaskFile::class, 1);
    expect($connection->syncEvents()->where('provider', 'google_drive')->where('action', 'upload_file')->count())->toBe(1);

    (new UploadGoogleDriveTaskFile($connection->id, $file->id, $event->id))->failed(new RuntimeException('Provider unavailable'));

    expect($connection->mappings()->where('resource_type', 'task_file')->count())->toBe(0)
        ->and($event->fresh()->status)->toBe('failed')
        ->and($connection->syncEvents()->where('provider', 'google_drive')->where('status', 'failed')->count())->toBe(1)
        ->and($connection->fresh()->last_error)->toBe('Upload Google Drive gagal. Periksa izin dan koneksi Drive.');
});

test('Google Drive upload can be queued again when the previous queue entry is stale', function () {
    Queue::fake([UploadGoogleDriveTaskFile::class]);
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $path = 'task-files/'.$user->id.'/'.$task->id.'/final.pdf';
    Storage::disk('local')->put($path, 'final document');
    $file = $task->files()->create([
        'user_id' => $user->id,
        'kind' => 'final',
        'original_name' => 'final.pdf',
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'byte_size' => 14,
        'version' => 1,
        'is_final' => true,
    ]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $staleEvent = $connection->syncEvents()->create([
        'provider' => 'google_drive',
        'direction' => 'outbound',
        'action' => 'upload_file',
        'status' => 'queued',
        'context' => ['task_id' => $task->id, 'task_file_id' => $file->id],
    ]);
    $staleEvent->timestamps = false;
    $staleEvent->updated_at = now()->subMinutes(16);
    $staleEvent->save();

    Livewire::actingAs($user)
        ->test('pages::integrations')
        ->set('driveFileId', (string) $file->id)
        ->call('uploadDriveFile')
        ->assertHasNoErrors()
        ->assertSee('Upload masuk antrean');

    Queue::assertPushed(UploadGoogleDriveTaskFile::class, 1);
    expect($staleEvent->fresh()->status)->toBe('failed')
        ->and($connection->syncEvents()->where('action', 'upload_file')->where('status', 'queued')->count())->toBe(1);
});

test('Google Drive upload job records a verified result and links the remote file to its task', function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    $user = User::factory()->create();
    $task = Task::factory()->for($user)->create();
    $path = 'task-files/'.$user->id.'/'.$task->id.'/final.pdf';
    Storage::disk('local')->put($path, 'final document');
    $file = $task->files()->create([
        'user_id' => $user->id,
        'kind' => 'final',
        'original_name' => 'final.pdf',
        'disk' => 'local',
        'path' => $path,
        'mime_type' => 'application/pdf',
        'byte_size' => 14,
        'version' => 1,
        'is_final' => true,
    ]);
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);
    $event = $connection->syncEvents()->create([
        'provider' => 'google_drive',
        'direction' => 'outbound',
        'action' => 'upload_file',
        'status' => 'queued',
        'context' => ['task_id' => $task->id, 'task_file_id' => $file->id],
    ]);
    Http::fake([
        'https://www.googleapis.com/drive/v3/files*' => Http::response(['files' => []]),
        'https://www.googleapis.com/upload/drive/v3/files*' => Http::response([
            'id' => 'drive-file-queued',
            'name' => 'final.pdf',
            'webViewLink' => 'https://drive.google.com/file/d/drive-file-queued/view',
        ]),
    ]);

    (new UploadGoogleDriveTaskFile($connection->id, $file->id, $event->id))->handle(app(GoogleDriveSyncService::class));

    expect($event->fresh()->status)->toBe('succeeded')
        ->and($connection->mappings()->where('resource_type', 'task_file')->value('external_id'))->toBe('drive-file-queued')
        ->and($task->links()->where('url', 'https://drive.google.com/file/d/drive-file-queued/view')->exists())->toBeTrue();
});

test('Google Drive folder selection is stored on the private user connection', function () {
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'status' => 'connected',
    ]);

    Livewire::actingAs($user)
        ->test('pages::integrations')
        ->set('driveFolderId', 'selected-folder-456')
        ->call('saveDriveFolder')
        ->assertHasNoErrors();

    expect($connection->fresh()->settings['drive_folder_id'])->toBe('selected-folder-456');
});

test('Google Picker receives a non-cacheable access token only for a connected Drive scope', function () {
    config([
        'services.google.picker_api_key' => 'picker-api-key',
        'services.google.app_id' => '123456789',
    ]);
    $user = User::factory()->create();
    $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'picker-access-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'scopes' => ['https://www.googleapis.com/auth/drive.file'],
        'status' => 'connected',
    ]);

    $this->actingAs($user)
        ->postJson(route('integrations.google.picker-token'))
        ->assertOk()
        ->assertExactJson(['accessToken' => 'picker-access-token'])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Pragma', 'no-cache');
});

test('Google Picker token endpoint refuses a connection without the Drive scope', function () {
    config([
        'services.google.picker_api_key' => 'picker-api-key',
        'services.google.app_id' => '123456789',
    ]);
    $user = User::factory()->create();
    $user->integrations()->create([
        'provider' => 'google',
        'access_token' => 'calendar-only-token',
        'refresh_token' => 'refresh-token',
        'expires_at' => now()->addHour(),
        'scopes' => ['https://www.googleapis.com/auth/calendar.events'],
        'status' => 'connected',
    ]);

    $this->actingAs($user)
        ->postJson(route('integrations.google.picker-token'))
        ->assertForbidden()
        ->assertJsonPath('message', 'Hubungkan ulang Google dan izinkan akses Drive terbatas.');
});

test('GitHub OAuth validates state and stores an encrypted read-only identity token', function () {
    config([
        'services.github.client_id' => 'github-client-id',
        'services.github.client_secret' => 'github-client-secret',
        'services.github.redirect' => 'http://mytask.test/integrations/github/callback',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://github.com/login/oauth/access_token' => Http::response([
            'access_token' => 'github-access-token',
            'scope' => 'read:user',
            'token_type' => 'bearer',
        ]),
        'https://api.github.com/user' => Http::response([
            'id' => 12345,
            'login' => 'sample-student',
            'html_url' => 'https://github.com/sample-student',
        ]),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['oauth.github.state' => 'csrf-state', 'oauth.github.verifier' => 'pkce-verifier'])
        ->get(route('integrations.github.callback', ['state' => 'csrf-state', 'code' => 'authorization-code']))
        ->assertRedirect(route('integrations.index'))
        ->assertSessionHas('status');

    $connection = $user->integrations()->where('provider', 'github')->firstOrFail();
    $stored = DB::table('integration_connections')->where('id', $connection->id)->value('access_token');

    expect($connection->account_label)->toBe('sample-student')
        ->and($connection->external_user_id)->toBe('12345')
        ->and($connection->scopes)->toBe(['read:user'])
        ->and($stored)->not->toBe('github-access-token')
        ->and($connection->access_token)->toBe('github-access-token');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/login/oauth/access_token')
        && $request->data()['code_verifier'] === 'pkce-verifier');
});

test('GitHub OAuth state mismatch does not exchange or store credentials', function () {
    Http::preventStrayRequests();
    Http::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['oauth.github.state' => 'expected-state'])
        ->get(route('integrations.github.callback', ['state' => 'attacker-state', 'code' => 'authorization-code']))
        ->assertRedirect(route('integrations.index'))
        ->assertSessionHasErrors('github');

    expect($user->integrations()->count())->toBe(0);
    Http::assertNothingSent();
});

test('GitHub connector links only a public repository returned for the connected account', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $project = Task::factory()->for($user)->create(['parent_task_id' => null, 'title' => 'Semester project']);
    $connection = $user->integrations()->create([
        'provider' => 'github',
        'access_token' => 'github-access-token',
        'external_user_id' => '12345',
        'account_label' => 'sample-student',
        'scopes' => ['read:user'],
        'status' => 'connected',
        'settings' => ['login' => 'sample-student'],
    ]);
    Http::fake([
        'https://api.github.com/users/sample-student/repos*' => Http::response([
            [
                'id' => 987,
                'full_name' => 'sample-student/course-project',
                'html_url' => 'https://github.com/sample-student/course-project',
                'private' => false,
                'description' => 'A semester project',
                'language' => 'PHP',
                'stargazers_count' => 2,
                'updated_at' => '2026-11-06T10:00:00Z',
            ],
            [
                'id' => 654,
                'full_name' => 'sample-student/private-repository',
                'html_url' => 'https://github.com/sample-student/private-repository',
                'private' => true,
            ],
            [
                'id' => 321,
                'full_name' => 'sample-student/untrusted-url',
                'html_url' => 'https://attacker.example/sample-student/untrusted-url',
                'private' => false,
            ],
        ]),
    ]);

    $service = app(GitHubRepositoryService::class);
    $repositories = $service->publicRepositories($connection);
    $linked = $service->linkPublicRepository($user, $project, 987);

    expect($repositories)->toHaveCount(1)
        ->and($repositories[0]['full_name'])->toBe('sample-student/course-project')
        ->and($linked['id'])->toBe(987)
        ->and($project->links()->where('kind', 'repository')->value('url'))->toBe('https://github.com/sample-student/course-project')
        ->and($connection->mappings()->where('resource_type', 'github_repository')->value('external_id'))->toBe('987')
        ->and($connection->syncEvents()->where('provider', 'github')->where('status', 'succeeded')->count())->toBe(1);
});

test('GitHub connector follows pagination so older public repositories remain selectable', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'github',
        'access_token' => 'github-access-token',
        'external_user_id' => '12345',
        'account_label' => 'sample-student',
        'scopes' => ['read:user'],
        'status' => 'connected',
        'settings' => ['login' => 'sample-student'],
    ]);
    $requestedPages = [];
    Http::fake(function (Request $request) use (&$requestedPages) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 0);
        $requestedPages[] = $page;

        if ($page === 1) {
            return Http::response([[
                'id' => 987,
                'full_name' => 'sample-student/new-project',
                'html_url' => 'https://github.com/sample-student/new-project',
                'private' => false,
            ]], 200, [
                'Link' => '<https://api.github.com/users/sample-student/repos?type=owner&sort=pushed&direction=desc&per_page=100&page=2>; rel="next", <https://api.github.com/users/sample-student/repos?type=owner&sort=pushed&direction=desc&per_page=100&page=4>; rel="last"',
            ]);
        }

        return Http::response([[
            'id' => 654,
            'full_name' => 'sample-student/older-project',
            'html_url' => 'https://github.com/sample-student/older-project',
            'private' => false,
        ]]);
    });

    $repositories = app(GitHubRepositoryService::class)->publicRepositories($connection);

    expect($requestedPages)->toBe([1, 2])
        ->and(collect($repositories)->pluck('full_name')->all())->toBe([
            'sample-student/new-project',
            'sample-student/older-project',
        ]);
});

test('GitHub repository cache follows the connected account after OAuth reconnect', function () {
    Cache::flush();
    Http::preventStrayRequests();

    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'github',
        'access_token' => 'first-account-token',
        'external_user_id' => '12345',
        'status' => 'connected',
        'settings' => ['login' => 'first-account'],
    ]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/users/first-account/repos')) {
            return Http::response([[
                'id' => 101,
                'full_name' => 'first-account/course-work',
                'html_url' => 'https://github.com/first-account/course-work',
                'private' => false,
            ]]);
        }

        return Http::response([[
            'id' => 202,
            'full_name' => 'second-account/course-work',
            'html_url' => 'https://github.com/second-account/course-work',
            'private' => false,
        ]]);
    });

    $service = app(GitHubRepositoryService::class);
    $firstRepositories = $service->publicRepositories($connection);

    $connection->update([
        'access_token' => 'second-account-token',
        'external_user_id' => '67890',
        'settings' => ['login' => 'second-account'],
    ]);

    $secondRepositories = $service->publicRepositories($connection->fresh());

    expect($firstRepositories[0]['full_name'])->toBe('first-account/course-work')
        ->and($secondRepositories[0]['full_name'])->toBe('second-account/course-work');
});

test('GitHub connector retries transient server errors using the provider delay', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'github',
        'access_token' => 'github-access-token',
        'status' => 'connected',
        'settings' => ['login' => 'sample-student'],
    ]);
    $requestCount = 0;
    Http::fake(function () use (&$requestCount) {
        $requestCount++;

        if ($requestCount === 1) {
            return Http::response(['message' => 'Try again'], 503, ['Retry-After' => '0']);
        }

        return Http::response([[
            'id' => 987,
            'full_name' => 'sample-student/course-project',
            'html_url' => 'https://github.com/sample-student/course-project',
            'private' => false,
        ]]);
    });

    $repositories = app(GitHubRepositoryService::class)->publicRepositories($connection);

    expect($requestCount)->toBe(2)
        ->and($repositories[0]['full_name'])->toBe('sample-student/course-project');
});

test('GitHub marks a rejected identity token for reconnection', function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/users/sample-student/repos*' => Http::response(['message' => 'Bad credentials'], 401),
    ]);
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'github',
        'access_token' => 'revoked-github-token',
        'status' => 'connected',
        'settings' => ['login' => 'sample-student'],
    ]);

    expect(fn () => app(GitHubRepositoryService::class)->publicRepositories($connection))
        ->toThrow(RequestException::class);

    expect($connection->fresh()->status)->toBe('reauth_required')
        ->and($connection->fresh()->last_error)->toContain('Hubungkan ulang');

    Livewire::actingAs($user)
        ->test('pages::integrations')
        ->assertSee('Perlu hubungkan ulang');
});

test('GitHub connector does not retry before a long rate limit reset', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'github',
        'access_token' => 'github-access-token',
        'status' => 'connected',
        'settings' => ['login' => 'sample-student'],
    ]);
    $requestCount = 0;
    Http::fake(function () use (&$requestCount) {
        $requestCount++;

        return Http::response(['message' => 'Rate limit exceeded'], 429, ['Retry-After' => '60']);
    });

    expect(fn () => app(GitHubRepositoryService::class)->publicRepositories($connection))
        ->toThrow(RequestException::class);

    expect($requestCount)->toBe(1);
});

test('GitHub integration page loads public repositories and links one to a root project', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $project = Task::factory()->for($user)->create(['parent_task_id' => null, 'title' => 'Web project']);
    $user->integrations()->create([
        'provider' => 'github',
        'access_token' => 'github-access-token',
        'external_user_id' => '12345',
        'account_label' => 'sample-student',
        'scopes' => ['read:user'],
        'status' => 'connected',
        'settings' => ['login' => 'sample-student'],
    ]);
    Http::fake([
        'https://api.github.com/users/sample-student/repos*' => Http::response([[
            'id' => 987,
            'full_name' => 'sample-student/course-project',
            'html_url' => 'https://github.com/sample-student/course-project',
            'private' => false,
            'description' => 'A semester project',
            'language' => 'PHP',
            'stargazers_count' => 2,
            'updated_at' => '2026-11-06T10:00:00Z',
        ]]),
    ]);

    Livewire::actingAs($user)
        ->test('pages::integrations')
        ->call('loadGitHubRepositories')
        ->assertSet('githubRepositories.0.id', 987)
        ->assertSee('sample-student/course-project')
        ->set('githubProjectId', (string) $project->id)
        ->set('githubRepositoryId', '987')
        ->call('linkGitHubRepository')
        ->assertHasNoErrors()
        ->assertSee('sample-student/course-project');

    expect($project->links()->where('kind', 'repository')->exists())->toBeTrue();
});

test('GitHub disconnect revokes the remote grant before removing the local encrypted token', function () {
    config([
        'services.github.client_id' => 'github-client-id',
        'services.github.client_secret' => 'github-client-secret',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/applications/github-client-id/grant' => Http::response('', 204),
    ]);
    $user = User::factory()->create();
    $connection = $user->integrations()->create([
        'provider' => 'github',
        'access_token' => 'github-access-token',
        'external_user_id' => '12345',
        'account_label' => 'sample-student',
        'scopes' => ['read:user'],
        'status' => 'connected',
        'settings' => ['login' => 'sample-student'],
    ]);

    $this->actingAs($user)
        ->delete(route('integrations.github.disconnect'))
        ->assertRedirect(route('integrations.index'))
        ->assertSessionHas('status');

    expect($user->integrations()->where('provider', 'github')->exists())->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/applications/github-client-id/grant')
        && $request->data()['access_token'] === 'github-access-token'
        && $request->hasHeader('Authorization'));
});
