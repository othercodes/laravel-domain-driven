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
     * Flashed through Inertia rather than shared as a prop, so it reaches the
     * page once and is gone: shared props are written into the history entry
     * and merged back on a partial reload, and a banner that outlives its
     * redirect reappears on the Back button.
     *
     * @return array{message: string, style: string}
     */
    public static function of(string $style, string $message): array
    {
        return ['message' => $message, 'style' => $style];
    }
}
