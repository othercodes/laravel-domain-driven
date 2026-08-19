<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
 * The banner is a contract between a redirect and a Vue component that cannot
 * report a mismatch: hand it a shape it does not recognise and the page simply
 * renders without a banner, and nothing anywhere says why.
 *
 * The two sides drifted and stayed that way. The macros agreed with each other
 * the whole time, so only the second test below would have caught it: the
 * first pins the payload, the second is the one that reads both sides.
 */
test('every banner macro flashes what the component reads', function (string $macro, string $style) {
    Route::middleware('web')->get('/_banner', fn () => redirect('/')->{$macro}('Invoice paid.'));

    $this->get('/_banner')->assertSessionHas('flash', [
        'banner' => ['message' => 'Invoice paid.', 'style' => $style],
    ]);
})->with([
    'success' => ['withSuccessBanner', 'success'],
    'info' => ['withInfoBanner', 'info'],
    'warning' => ['withWarningBanner', 'warning'],
    'danger' => ['withDangerBanner', 'danger'],
]);

test('the component reads the keys the macros write, and styles every one', function () {
    $component = File::get(base_path('resources/templates/tailwindcss/js/Components/Banner.vue'));

    // Named here because no test can render the component: the two sides have
    // to agree on these two keys and there is nothing else to notice if they
    // stop agreeing.
    expect($component)
        ->toContain('flash?.banner?.style')
        ->toContain('flash?.banner?.message');

    // A style the palette does not carry renders unstyled, which is how info
    // and warning shipped: reachable from PHP, invisible on the page, and the
    // same was true of Jetstream upstream.
    foreach (['success', 'info', 'warning', 'danger'] as $style) {
        expect($component)->toContain("{$style}: { bar:");
    }
});
