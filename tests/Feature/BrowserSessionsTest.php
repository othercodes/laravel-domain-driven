<?php

use App\IdentityAndAccess\Users\Domain\User;

test('other browser sessions can be logged out', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->delete('/user/other-browser-sessions', [
        'password' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
});
