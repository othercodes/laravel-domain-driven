<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Class EloquentFactory
 *
 * @template TModel of Model
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
abstract class EloquentFactory extends Factory
{
    public function newModel(array $attributes = [])
    {
        $model = $this->modelName();

        return method_exists($model, 'new')
            ? $model::new($attributes)
            : new $model($attributes);
    }
}
