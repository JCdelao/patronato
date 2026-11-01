<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->usuario = User::factory()->create([
        'password' => 'temporal-123',
        'debe_cambiar_password' => true,
    ]);
});

test('a user with a temporary password is redirected to change it', function () {
    $this->actingAs($this->usuario)
        ->get(route('dashboard'))
        ->assertRedirect(route('password-temporal.edit'));

    $this->actingAs($this->usuario)
        ->get(route('profile.edit'))
        ->assertRedirect(route('password-temporal.edit'));

    $this->actingAs($this->usuario)
        ->get(route('password-temporal.edit'))
        ->assertOk();
});

test('a user with a temporary password can log out', function () {
    $this->actingAs($this->usuario)->post(route('logout'));

    $this->assertGuest();
});

test('the temporary password can be replaced', function () {
    $this->actingAs($this->usuario)
        ->put(route('password-temporal.update'), [
            'current_password' => 'temporal-123',
            'password' => 'definitiva-456',
            'password_confirmation' => 'definitiva-456',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $this->usuario->refresh();

    expect($this->usuario->debe_cambiar_password)->toBeFalse()
        ->and(Hash::check('definitiva-456', $this->usuario->password))->toBeTrue();

    $this->actingAs($this->usuario)->get(route('dashboard'))->assertOk();
    $this->actingAs($this->usuario)->get(route('password-temporal.edit'))->assertRedirect(route('dashboard'));
});

test('the change is rejected when it is not valid', function (array $datos, string $campo) {
    $this->actingAs($this->usuario)
        ->from(route('password-temporal.edit'))
        ->put(route('password-temporal.update'), $datos)
        ->assertSessionHasErrors($campo);

    expect($this->usuario->refresh()->debe_cambiar_password)->toBeTrue();
})->with([
    'temporal incorrecta' => [
        ['current_password' => 'otra', 'password' => 'definitiva-456', 'password_confirmation' => 'definitiva-456'],
        'current_password',
    ],
    'igual a la temporal' => [
        ['current_password' => 'temporal-123', 'password' => 'temporal-123', 'password_confirmation' => 'temporal-123'],
        'password',
    ],
    'sin confirmar' => [
        ['current_password' => 'temporal-123', 'password' => 'definitiva-456', 'password_confirmation' => 'distinta'],
        'password',
    ],
]);

test('a user without a temporary password is not redirected', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
});
