<?php

use App\Models\User;

test('shows the TUGAS!OS landing page to guests', function () {
    $response = $this->get(route('home'));

    $response
        ->assertOk()
        ->assertSee('TUGAS!OS')
        ->assertSee('Semua tugas, satu kendali.')
        ->assertDontSee('Let\'s get started');
});

test('sends signed-in users to their dashboard from the home page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
