<?php

declare(strict_types=1);

namespace App\IdentityAndAccess\Users\Domain\Events;

use ComplexHeart\Domain\Contracts\Events\Event;
use ComplexHeart\Domain\Events\IsDomainEvent;

/**
 * Class UserDeleted
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class UserDeleted implements Event
{
    use IsDomainEvent;

    /**
     * @param  string  $user  The deleted user uuid
     */
    public function __construct(public string $user) {}
}
