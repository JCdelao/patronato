<?php

use Patronato\Persistencia\Models\User;

test('guests are sent to the login page', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('authenticated users are sent to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
