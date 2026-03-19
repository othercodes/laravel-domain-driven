<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Contracts\VersionSource;
use App\Shared\Domain\SemanticVersion;

/**
 * Class VersionManager
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class VersionManager
{
    public function __construct(
        private readonly VersionSource $source,
    ) {}

    public function version(): SemanticVersion
    {
        return $this->source->version();
    }
}
