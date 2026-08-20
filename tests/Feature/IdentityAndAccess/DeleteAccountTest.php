<?php

use App\IdentityAndAccess\Users\Domain\User;

test('user accounts can be deleted', function () {
    $this->actingAs($user = User::factory()->create());

    $this->delete('/user', [
        'password' => 'password',
    ]);

    expect($user->fresh())->toBeNull();
});

test('correct password must be provided before account can be deleted', function () {
    $this->actingAs($user = User::factory()->create());

    $response = $this->delete('/user', [
        'password' => 'wrong-password',
    ]);

    expect($user->fresh())->not->toBeNull();

    // Under any other key the modal stays open saying nothing at all:
    // DeleteUserForm.vue binds form.errors.password and nothing else, and the
    // alert component reads only flashed alerts. This test asserted the
    // account survived and nothing about the user being told why, so an error
    // bag keyed 'Invalid Password' sat there rendering into a void.
    $response->assertSessionHasErrors('password');
});
