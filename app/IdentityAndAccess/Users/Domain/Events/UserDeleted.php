<?php

declare(strict_types=1);

namespace App\IdentityAndAccess\Users\Domain\Events;

use App\Shared\Domain\Contracts\ServiceBus\Event;
use Illuminate\Broadcasting\InteractsWithSockets;

/**
 * Class UserDeleted
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class UserDeleted implements Event
{
    use InteractsWithSockets;

    /**
     * @param  string  $user  The deleted user uuid
     */
    public function __construct(public string $user) {}
}
