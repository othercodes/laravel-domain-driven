<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Contracts\ServiceBus\Event;
use App\Shared\Domain\Contracts\ServiceBus\EventBus;

/**
 * Trait HasDomainEvents
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
trait HasDomainEvents
{
    /**
     * List of registered domain events.
     *
     * @var Event[]
     */
    private array $domainEvents = [];

    /**
     * Publish the registered Domain Events.
     */
    final public function publishDomainEvents(EventBus $eventBus): void
    {
        $eventBus->publish(...$this->domainEvents);
        $this->domainEvents = [];
    }

    /**
     * Register a new DomainEvent.
     */
    final protected function registerDomainEvent(Event $domainEvent): void
    {
        $this->domainEvents[] = $domainEvent;
    }
}
