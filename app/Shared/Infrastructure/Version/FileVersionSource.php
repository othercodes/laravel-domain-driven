<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Version;

use App\Shared\Domain\Contracts\VersionSource;
use App\Shared\Domain\SemanticVersion;

/**
 * Class FileVersionSource
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class FileVersionSource implements VersionSource
{
    private const string FALLBACK = 'v0.0.0-dev';

    public function __construct(
        private readonly string $path = 'VERSION',
    ) {}

    public function version(): SemanticVersion
    {
        $fullPath = base_path($this->path);

        $value = is_file($fullPath) ? trim((string) file_get_contents($fullPath)) : '';

        return SemanticVersion::fromString($value !== '' ? $value : self::FALLBACK);
    }
}
