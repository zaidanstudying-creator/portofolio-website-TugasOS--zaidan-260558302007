<?php

use App\Models\User;

test('unverified email accounts can access the dashboard and workspace menus', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    foreach (['dashboard', 'tasks.index', 'calendar', 'files.index', 'submissions.index', 'recurrence.index', 'habits.index', 'analytics.index', 'assistant', 'integrations.index', 'workspace', 'profile.edit', 'appearance.edit', 'security.edit'] as $routeName) {
        $this->get(route($routeName))->assertOk();
    }
});

test('email verification endpoints are no longer registered', function () {
    $this->get('/email/verify')->assertNotFound();
    $this->post('/email/verification-notification')->assertNotFound();
});
