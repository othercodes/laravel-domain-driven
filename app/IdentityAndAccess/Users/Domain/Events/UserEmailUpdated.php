<?php

declare(strict_types=1);

namespace App\IdentityAndAccess\Users\Domain\Events;

use App\IdentityAndAccess\Users\Domain\User;
use App\Shared\Domain\Contracts\ServiceBus\Event;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

/**
 * Class UserEmailUpdated
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class UserEmailUpdated implements Event
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public User $user) {}
}
