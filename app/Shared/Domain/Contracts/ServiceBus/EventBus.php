<?php

declare(strict_types=1);

namespace App\Shared\Domain\Contracts\ServiceBus;

/**
 * Interface EventBus
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
interface EventBus
{
    public function publish(Event ...$events): void;
}
