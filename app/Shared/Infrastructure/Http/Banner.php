<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * Class Banner
 *
 * The shape a flashed banner takes on its way to Banner.vue, in one place.
 *
 * It exists because the shape is a contract with a component that cannot say
 * when it is broken: hand it something it does not recognise and the page
 * renders without a banner, and nothing anywhere says why. That is how the
 * two sides drifted apart unnoticed, so the PHP half now has one owner.
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class Banner
{
    /**
     * Returned whole so a caller flashes it under `flash` in one go. Passing
     * `flash.banner` instead would land the very same array, since the session
     * expands the dots, so this buys nothing at runtime: it puts the shape the
     * component reads in front of the reader rather than behind that rule.
     *
     * @return array{banner: array{message: string, style: string}}
     */
    public static function of(string $style, string $message): array
    {
        return ['banner' => ['message' => $message, 'style' => $style]];
    }
}
