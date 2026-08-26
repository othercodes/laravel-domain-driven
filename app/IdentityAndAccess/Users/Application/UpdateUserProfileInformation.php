<?php

namespace App\IdentityAndAccess\Users\Application;

use App\IdentityAndAccess\Users\Domain\Contracts\UserRepository;
use App\IdentityAndAccess\Users\Domain\User;
use ComplexHeart\Domain\Contracts\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * Class UpdateUserProfileInformation
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final readonly class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    public function __construct(
        private UserRepository $repository,
        private EventBus $eventBus,
    ) {}

    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique($user->getTable())->ignore($user->id)],
            'photo' => ['nullable', 'mimes:jpg,jpeg,png', 'max:1024'],
        ])->validateWithBag('updateProfileInformation');

        if (isset($input['photo'])) {
            $user->updateProfilePhoto($input['photo']);
        }

        // The aggregate decides what changed and records the matching events;
        // resetting email_verified_at and sending the verification notice are
        // consequences of UserEmailUpdated, not of this use case.
        DB::transaction(function () use ($user, $input): void {
            $this->repository->save(
                $user->updateName($input['name'])->updateEmail($input['email'])
            );

            $user->publishDomainEvents($this->eventBus);
        });
    }
}
