<?php

declare(strict_types=1);

namespace App\IdentityAndAccess\Users\Application;

use App\IdentityAndAccess\Users\Domain\Contracts\UserRepository;
use App\IdentityAndAccess\Users\Domain\Exceptions\UserNotFound;
use App\IdentityAndAccess\Users\Domain\User;

/**
 * Class FindUser
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final readonly class FindUser
{
    public function __construct(private UserRepository $repository) {}

    /**
     * @throws UserNotFound
     */
    public function byId(string $id): User
    {
        if (! $user = $this->repository->find($id)) {
            throw new UserNotFound(strtr('User {id} not found.', [
                '{id}' => $id,
            ]));
        }

        return $user;
    }
}
