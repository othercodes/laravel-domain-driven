<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Illuminate\Support\Str;

/**
 * Trait CanGenerateIdentifiers
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
trait CanGenerateIdentifiers
{
    public function generateIdentifier(): string
    {
        return Str::orderedUuid()->toString();
    }
}
