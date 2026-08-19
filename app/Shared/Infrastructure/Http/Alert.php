<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * Class Alert
 *
 * The shape a flashed alert takes on its way to the front end, in one place,
 * for the same reason Banner has one: neither side can report a mismatch.
 *
 * An alert carries a title as well as a message, which is what separates it
 * from a banner. The title defaults to an empty string rather than null so
 * the payload has one shape whether or not a caller supplies it, and it is
 * left empty rather than filled with wording here: what an untitled alert
 * should say is the front end's to decide, in the front end's language.
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class Alert
{
    /**
     * @return array{message: string, title: string, style: string}
     */
    public static function of(string $style, string $message, string $title = ''): array
    {
        return ['message' => $message, 'title' => $title, 'style' => $style];
    }
}
