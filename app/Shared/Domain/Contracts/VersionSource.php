<?php

declare(strict_types=1);

namespace App\Shared\Domain\Contracts;

use App\Shared\Domain\SemanticVersion;

/**
 * Interface VersionSource
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
interface VersionSource
{
    public function version(): SemanticVersion;
}
