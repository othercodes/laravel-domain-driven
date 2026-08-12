<?php

namespace App\IdentityAndAccess\Users\Application;

use App\IdentityAndAccess\Users\Domain\Contracts\UserRepository;
use App\IdentityAndAccess\Users\Domain\PasswordValidationRules;
use App\IdentityAndAccess\Users\Domain\User;
use ComplexHeart\Domain\Contracts\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Class CreateUser
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final readonly class CreateUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private UserRepository $repository,
        private EventBus $eventBus,
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => $this->passwordRules(),
            'terms' => ['accepted', 'required'],
        ])->validate();

        // Events are published inside the transaction: listeners run
        // synchronously, so a failing one must not leave a persisted user
        // behind a request that never completed.
        return DB::transaction(function () use ($input): User {
            $user = $this->repository->save(User::new([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]));

            $user->publishDomainEvents($this->eventBus);

            return $user;
        });
    }
}
