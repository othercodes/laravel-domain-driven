<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ServiceBus;

use App\Shared\Domain\Contracts\ServiceBus\Event;
use App\Shared\Domain\Contracts\ServiceBus\EventBus;

/**
 * Class IlluminateEventBus
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class IlluminateEventBus implements EventBus
{
    public function publish(Event ...$events): void
    {
        if (empty($events)) {
            return;
        }

        event(...$events);
    }
}
