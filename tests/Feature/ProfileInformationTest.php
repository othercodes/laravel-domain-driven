<?php

use App\IdentityAndAccess\Users\Domain\Events\UserEmailUpdated;
use App\IdentityAndAccess\Users\Domain\Events\UserNameUpdated;
use App\IdentityAndAccess\Users\Domain\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

test('profile information can be updated', function () {
    $this->actingAs($user = User::factory()->create());

    $this->put('/user/profile-information', [
        'name' => 'Test Name',
        'email' => 'test@example.com',
    ]);

    expect($user->fresh())
        ->name->toEqual('Test Name')
        ->email->toEqual('test@example.com');
});

test('changing the name publishes UserNameUpdated', function () {
    Event::fake([UserNameUpdated::class, UserEmailUpdated::class]);

    $this->actingAs($user = User::factory()->create());

    $this->put('/user/profile-information', [
        'name' => 'Test Name',
        'email' => $user->email,
    ]);

    Event::assertDispatched(
        UserNameUpdated::class,
        fn (UserNameUpdated $event): bool => $event->user === $user->id
    );
    Event::assertNotDispatched(UserEmailUpdated::class);
});

test('changing the email publishes UserEmailUpdated and unverifies the address', function () {
    Event::fake([UserEmailUpdated::class]);

    $this->actingAs($user = User::factory()->create());

    $this->put('/user/profile-information', [
        'name' => $user->name,
        'email' => 'new@example.com',
    ]);

    Event::assertDispatched(
        UserEmailUpdated::class,
        fn (UserEmailUpdated $event): bool => $event->user === $user->id
    );
    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('the verification notice is sent by the UserEmailUpdated listener', function () {
    Notification::fake();

    $this->actingAs($user = User::factory()->create());

    $this->put('/user/profile-information', [
        'name' => $user->name,
        'email' => 'new@example.com',
    ]);

    Notification::assertSentTo($user->fresh(), VerifyEmail::class);
});

test('an unchanged profile publishes nothing', function () {
    Event::fake([UserNameUpdated::class, UserEmailUpdated::class]);

    $this->actingAs($user = User::factory()->create());

    $this->put('/user/profile-information', [
        'name' => $user->name,
        'email' => $user->email,
    ]);

    Event::assertNotDispatched(UserNameUpdated::class);
    Event::assertNotDispatched(UserEmailUpdated::class);
});
