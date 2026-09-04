<?php

use App\Models\User;

test('guests are redirected to the login route', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});

test('authenticated web users are redirected to the dashboard route', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'web')->get(route('home'));

    $response->assertRedirect(route('dashboard'));
});
