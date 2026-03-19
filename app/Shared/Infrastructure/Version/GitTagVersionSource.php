<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Version;

use App\Shared\Domain\Contracts\VersionSource;
use App\Shared\Domain\SemanticVersion;
use Symfony\Component\Process\Process;

/**
 * Class GitTagVersionSource
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class GitTagVersionSource implements VersionSource
{
    private const string FALLBACK = 'v0.0.0-dev';

    public function version(): SemanticVersion
    {
        $process = new Process(['git', 'describe', '--tags', '--abbrev=0']);
        $process->run();

        $tag = trim($process->getOutput());

        return SemanticVersion::fromString($tag !== '' ? $tag : self::FALLBACK);
    }
}
