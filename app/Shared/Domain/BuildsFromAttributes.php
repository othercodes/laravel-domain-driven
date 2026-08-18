<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Interface BuildsFromAttributes
 *
 * An aggregate that is created through a named constructor rather than by
 * `new`. That is what assigns the identifier up front, so domain events can
 * carry it before anything is persisted.
 *
 * Declared so the routing in AggregateFactory is checked rather than assumed:
 * a factory whose model has no new() is a mistake worth catching statically.
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
interface BuildsFromAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function new(array $attributes = []): static;
}
