<?php

declare(strict_types=1);

namespace App\IdentityAndAccess\Users\Infrastructure\Persistence;

use App\IdentityAndAccess\Users\Domain\Contracts\UserRepository;
use App\IdentityAndAccess\Users\Domain\User;
use Illuminate\Support\Collection;

/**
 * Class EloquentUserRepository
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class EloquentUserRepository implements UserRepository
{
    public function find(string $id): ?User
    {
        return User::find($id);
    }

    public function match(array $criteria): Collection
    {
        return new Collection;
    }

    public function save(User $user): User
    {
        $user->save();

        return $user;
    }

    public function delete(User $user): void
    {
        $user->deleteProfilePhoto();
        $user->delete();
    }
}
