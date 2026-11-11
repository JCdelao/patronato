<?php

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Patronato\Persistencia\Models\User;

function enlaceDe(User $usuario): string
{
    $token = '';

    Notification::assertSentTo($usuario, ResetPassword::class, function (ResetPassword $aviso) use (&$token) {
        $token = $aviso->token;

        return true;
    });

    return $token;
}

beforeEach(function () {
    Notification::fake();

    $this->usuario = User::factory()->create(['email' => 'ana@laspalmas.test']);
});

test('the response is the same whether the email exists or not', function () {
    $existe = $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'ana@laspalmas.test']);

    $noExiste = $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => 'nadie@laspalmas.test']);

    $existe->assertSessionHasNoErrors()->assertSessionHas('status', trans('passwords.sent'));
    $noExiste->assertSessionHasNoErrors()->assertSessionHas('status', trans('passwords.sent'));

    expect($existe->getStatusCode())->toBe($noExiste->getStatusCode());

    Notification::assertSentTimes(ResetPassword::class, 1);
});

test('the link expires after 30 minutes', function () {
    $this->post(route('password.email'), ['email' => 'ana@laspalmas.test']);
    $token = enlaceDe($this->usuario);

    $this->travel(31)->minutes();

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => 'ana@laspalmas.test',
        'password' => 'nueva-clave1',
        'password_confirmation' => 'nueva-clave1',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('nueva-clave1', $this->usuario->refresh()->password))->toBeFalse();
});

test('the link works once', function () {
    $this->post(route('password.email'), ['email' => 'ana@laspalmas.test']);
    $token = enlaceDe($this->usuario);

    $datos = ['token' => $token, 'email' => 'ana@laspalmas.test', 'password' => 'nueva-clave1', 'password_confirmation' => 'nueva-clave1'];

    $this->post(route('password.update'), $datos)->assertSessionHasNoErrors();

    expect(Hash::check('nueva-clave1', $this->usuario->refresh()->password))->toBeTrue();

    $this->post(route('password.update'), [...$datos, 'password' => 'otra-clave2', 'password_confirmation' => 'otra-clave2'])
        ->assertSessionHasErrors('email');

    expect(Hash::check('otra-clave2', $this->usuario->refresh()->password))->toBeFalse();
});

test('the new password must follow the minimum policy', function (string $clave) {
    $this->post(route('password.email'), ['email' => 'ana@laspalmas.test']);

    $this->post(route('password.update'), [
        'token' => enlaceDe($this->usuario),
        'email' => 'ana@laspalmas.test',
        'password' => $clave,
        'password_confirmation' => $clave,
    ])->assertSessionHasErrors('password');
})->with([
    'corta' => 'abc1234',
    'sin numeros' => 'solo-letras',
    'sin letras' => '12345678',
]);

test('resetting removes the login lock and the pending temporary password', function () {
    $this->usuario->forceFill(['debe_cambiar_password' => true])->save();

    foreach (range(1, 5) as $intento) {
        $this->post(route('login.store'), ['email' => 'ana@laspalmas.test', 'password' => 'mala']);
    }

    $this->post(route('password.email'), ['email' => 'ana@laspalmas.test']);

    $this->post(route('password.update'), [
        'token' => enlaceDe($this->usuario),
        'email' => 'ana@laspalmas.test',
        'password' => 'nueva-clave1',
        'password_confirmation' => 'nueva-clave1',
    ])->assertSessionHasNoErrors();

    $this->post(route('login.store'), ['email' => 'ana@laspalmas.test', 'password' => 'nueva-clave1']);

    $this->assertAuthenticated();
    expect($this->usuario->refresh()->debe_cambiar_password)->toBeFalse();
});
