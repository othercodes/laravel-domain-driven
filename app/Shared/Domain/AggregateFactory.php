<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class AggregateFactory
 *
 * Builds through the aggregate's own new() instead of Eloquent's constructor,
 * so seeded and tested instances get an identifier and record the same domain
 * events as the ones the application layer creates.
 *
 * A base class rather than a method each factory repeats: routing every
 * aggregate needs is not something a stub should keep restating, and one
 * factory quietly missing it produces rows no event ever announced.
 *
 * @template TModel of Model&BuildsFromAttributes
 *
 * @extends Factory<TModel>
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
abstract class AggregateFactory extends Factory
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function newModel(array $attributes = []): Model
    {
        return $this->modelName()::new($attributes);
    }
}
