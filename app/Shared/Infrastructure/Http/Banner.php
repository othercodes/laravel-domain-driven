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
     * Flashed at `flash.banner`, never as a whole `flash` array: the session
     * expands the dots into the same place, and it leaves room beside it for
     * an alert flashed by the same redirect. Writing `flash` entire replaces
     * whatever was there, so the two would silently take turns.
     *
     * @return array{message: string, style: string}
     */
    public static function of(string $style, string $message): array
    {
        return ['message' => $message, 'style' => $style];
    }
}
