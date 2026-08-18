<?php

use App\IdentityAndAccess\Users\Domain\Events\UserCreated;
use App\IdentityAndAccess\Users\Domain\User;
use ComplexHeart\Domain\Contracts\Events\EventBus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register and the UserCreated domain event is published', function () {
    Event::fake([UserCreated::class]);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => true,
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

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

test('a caller may supply the user identifier', function () {
    $id = (string) Str::uuid7();

    expect(User::new(['id' => $id, 'name' => 'Test User'])->id)->toBe($id);
});

test('users built through the factory carry an identifier and record their creation', function () {
    expect(Str::isUuid(User::factory()->make()->id))->toBeTrue()
        ->and(Str::isUuid(User::factory()->create()->id))->toBeTrue();

    // The identifier alone does not prove the factory went through new():
    // AggregateFactory routes there for the recorded event just as much, and
    // an aggregate that skips it persists rows nothing ever announced.
    Event::fake([UserCreated::class]);

    $user = User::factory()->make();
    $user->publishDomainEvents(app(EventBus::class));

    Event::assertDispatched(
        UserCreated::class,
        fn (UserCreated $event): bool => $event->user === $user->id
    );
});
