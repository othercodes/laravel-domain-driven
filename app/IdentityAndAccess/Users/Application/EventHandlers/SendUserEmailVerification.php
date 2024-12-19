<?php

declare(strict_types=1);

namespace App\IdentityAndAccess\Users\Application\EventHandlers;

use App\IdentityAndAccess\Users\Application\FindUser;
use App\IdentityAndAccess\Users\Domain\Contracts\UserRepository;
use App\IdentityAndAccess\Users\Domain\Events\UserEmailUpdated;
use App\IdentityAndAccess\Users\Domain\Exceptions\UserNotFound;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Class SendUserEmailVerification
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final readonly class SendUserEmailVerification implements ShouldQueue
{
    private FindUser $finder;

    public function __construct(UserRepository $repository)
    {
        $this->finder = new FindUser($repository);
    }

    /**
     * @throws UserNotFound
     */
    public function handle(UserEmailUpdated $event): void
    {
        $this->finder
            ->byId($event->user->id)
            ->sendEmailVerificationNotification();
    }
}
