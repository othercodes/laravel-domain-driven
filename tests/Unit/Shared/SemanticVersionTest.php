<?php

use App\Shared\Domain\SemanticVersion;

describe('SemanticVersion', function () {
    describe('fromString', function () {
        it('parses a full semver string', function () {
            $version = SemanticVersion::fromString('1.2.3-beta');

            expect($version->major)->toBe('1')
                ->and($version->minor)->toBe('2')
                ->and($version->patch)->toBe('3')
                ->and($version->status)->toBe('beta');
        });

        it('parses a version with v prefix', function () {
            $version = SemanticVersion::fromString('v1.2.3');

            expect($version->major)->toBe('1')
                ->and($version->minor)->toBe('2')
                ->and($version->patch)->toBe('3')
                ->and($version->status)->toBe('');
        });

        it('parses a version with uppercase V prefix', function () {
            $version = SemanticVersion::fromString('V2.0.1-rc');

            expect($version->major)->toBe('2')
                ->and($version->minor)->toBe('0')
                ->and($version->patch)->toBe('1')
                ->and($version->status)->toBe('rc');
        });

        it('parses a version without patch', function () {
            $version = SemanticVersion::fromString('1.2');

            expect($version->major)->toBe('1')
                ->and($version->minor)->toBe('2')
                ->and($version->patch)->toBe('0');
        });

        it('parses a version with only major', function () {
            $version = SemanticVersion::fromString('3');

            expect($version->major)->toBe('3')
                ->and($version->minor)->toBe('0')
                ->and($version->patch)->toBe('0');
        });

        it('parses the dev fallback version', function () {
            $version = SemanticVersion::fromString('v0.0.0-dev');

            expect($version->major)->toBe('0')
                ->and($version->minor)->toBe('0')
                ->and($version->patch)->toBe('0')
                ->and($version->status)->toBe('dev');
        });

        it('throws on empty string', function () {
            SemanticVersion::fromString('');
        })->throws(InvalidArgumentException::class);

        it('throws on whitespace-only string', function () {
            SemanticVersion::fromString('  ');
        })->throws(InvalidArgumentException::class);
    });

    describe('__toString', function () {
        it('converts to string without status', function () {
            $version = new SemanticVersion('1', '2', '3');

            expect((string) $version)->toBe('1.2.3');
        });

        it('converts to string with status', function () {
            $version = new SemanticVersion('1', '0', '0', 'alpha');

            expect((string) $version)->toBe('1.0.0-alpha');
        });
    });
});
