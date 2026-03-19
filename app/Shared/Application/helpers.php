<?php

use App\Shared\Application\VersionManager;
use App\Shared\Domain\SemanticVersion;

if (! function_exists('version')) {
    /**
     * Get the application semantic version.
     */
    function version(): SemanticVersion
    {
        return app(VersionManager::class)->version();
    }
}
