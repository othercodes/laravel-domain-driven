<?php

namespace App\IdentityAndAccess\Users\Application;

use App\IdentityAndAccess\Users\Domain\Contracts\UserRepository;
use App\IdentityAndAccess\Users\Domain\Exceptions\UserNotFound;
use App\Shared\Domain\Contracts\ServiceBus\EventBus;

/**
 * Class DeleteUser
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final readonly class DeleteUser
{
    private UserRepository $repository;

    private EventBus $eventBus;

    private FindUser $finder;

    public function __construct(UserRepository $repository, EventBus $eventBus)
    {
        $this->repository = $repository;
        $this->eventBus = $eventBus;
        $this->finder = new FindUser($this->repository);
    }

    public function delete(string $id): void
    {
        try {
            $user = $this->finder->byId($id);
        } catch (UserNotFound) {
            return;
        }

        $this->repository->delete($user->toBeDeleted());

        $user->publishDomainEvents($this->eventBus);
    }
}
