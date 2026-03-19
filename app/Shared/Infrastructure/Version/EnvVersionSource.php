<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Version;

use App\Shared\Domain\Contracts\VersionSource;
use App\Shared\Domain\SemanticVersion;

/**
 * Class EnvVersionSource
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class EnvVersionSource implements VersionSource
{
    private const string FALLBACK = 'v0.0.0-dev';

    public function __construct(
        private readonly string $configKey = 'version.value',
    ) {}

    public function version(): SemanticVersion
    {
        $value = trim((string) config($this->configKey));

        return SemanticVersion::fromString($value !== '' ? $value : self::FALLBACK);
    }
}
