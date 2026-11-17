<?php

use App\Models\User;

test('confirm password screen can be rendered', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('password.confirm'));

    $response->assertOk();
});

test('password confirmation rejects repeated guesses for an authenticated session', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post(route('password.confirm.store'), ['password' => 'incorrect-password'])
            ->assertSessionHasErrors('password');
    }

    $this->post(route('password.confirm.store'), ['password' => 'incorrect-password'])
        ->assertTooManyRequests();

    $this->assertAuthenticatedAs($user);
});
