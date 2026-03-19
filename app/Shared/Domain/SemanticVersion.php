<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use InvalidArgumentException;

/**
 * Class SemanticVersion
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class SemanticVersion
{
    public function __construct(
        public readonly string $major,
        public readonly string $minor,
        public readonly string $patch,
        public readonly string $status = '',
    ) {}

    public static function fromString(string $version): self
    {
        $version = ltrim(trim($version), 'vV');

        if ($version === '') {
            throw new InvalidArgumentException('Version string cannot be empty.');
        }

        [$main, $status] = array_pad(explode('-', $version, 2), 2, '');
        [$major, $minor, $patch] = array_pad(explode('.', $main, 3), 3, '0');

        return new self($major, $minor, $patch, $status);
    }

    public function __toString(): string
    {
        $version = "{$this->major}.{$this->minor}.{$this->patch}";

        if ($this->status !== '') {
            $version .= "-{$this->status}";
        }

        return $version;
    }
}
