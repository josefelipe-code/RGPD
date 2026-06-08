<?php

use Laravel\Fortify\Features;

test('registration is disabled', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();
});

test('registration route is not available', function () {
    $response = $this->get('/register');

    $response->assertStatus(404);
});

test('registration store route is not available', function () {
    $response = $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertStatus(404);
});
