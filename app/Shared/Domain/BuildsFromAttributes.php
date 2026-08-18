<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Interface BuildsFromAttributes
 *
 * An aggregate that is built by calling new() on the class, rather than by
 * constructing it directly. Going through new() is what assigns the
 * identifier up front, so domain events can carry it before anything is
 * persisted.
 *
 * Declared so the routing in AggregateFactory is checked rather than assumed:
 * a factory whose model has no new() is a mistake worth catching statically.
 *
 * The return type is static, not self, so the aggregate a subclass builds is
 * that subclass. An implementation has to declare static too, since PHP will
 * not let it narrow.
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
