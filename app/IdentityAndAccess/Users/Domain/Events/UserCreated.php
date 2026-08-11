<?php

declare(strict_types=1);

namespace App\IdentityAndAccess\Users\Domain\Events;

use ComplexHeart\Domain\Contracts\Events\Event;
use ComplexHeart\Domain\Events\IsDomainEvent;

/**
 * Class UserCreated
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class UserCreated implements Event
{
    use IsDomainEvent;

    /**
     * @param  string  $user  The created user uuid
     */
    public function __construct(public string $user) {}
}
