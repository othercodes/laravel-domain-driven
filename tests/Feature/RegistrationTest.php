<?php

use App\IdentityAndAccess\Users\Domain\Events\UserCreated;
use App\IdentityAndAccess\Users\Domain\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => true,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('registering a user publishes the UserCreated domain event', function () {
    Event::fake([UserCreated::class]);

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => true,
    ]);

    $user = User::whereEmail('test@example.com')->sole();

    Event::assertDispatched(
        UserCreated::class,
        fn (UserCreated $event): bool => $event->user === $user->id
    );
});

test('a user is assigned a uuid before it is persisted', function () {
    $user = User::new(['name' => 'Test User', 'email' => 'test@example.com']);

    expect($user->exists)->toBeFalse()
        ->and(Str::isUuid($user->id))->toBeTrue();
});
