<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('authenticated users see a collapsible application sidebar', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-flux-sidebar-collapse', false)
        ->assertSee('data-flux-sidebar-item', false)
        ->assertSee('in-data-flux-sidebar-collapsed-desktop:hidden', false)
        ->assertSee(route('dashboard'), false)
        ->assertSee('Dashboard')
        ->assertSee('Repository');
});

test('authenticated users see the application shell as sibling sidebar, topbar, and main regions', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder([
            'data-flux-sidebar',
            'data-test="application-topbar"',
            'data-flux-main',
        ], false)
        ->assertSee('data-test="topbar-clock"', false)
        ->assertSee('data-test="topbar-appearance-switcher"', false)
        ->assertSee('x-model="$flux.appearance"', false)
        ->assertSee('data-test="topbar-user-menu"', false)
        ->assertSee('data-test="logout-button"', false)
        ->assertSee('method="POST"', false);
});
