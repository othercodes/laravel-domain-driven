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
    'error' => ['withErrorBanner', 'danger'],
]);

test('every alert macro flashes the agreed shape', function (string $macro, string $style) {
    Route::middleware('web')->get('/_alert', fn () => redirect('/')->{$macro}('Card declined.', 'Payment failed'));

    $this->get('/_alert')->assertSessionHas('flash.alert', [
        'message' => 'Card declined.',
        'title' => 'Payment failed',
        'style' => $style,
    ]);
})->with([
    'success' => ['withSuccessAlert', 'success'],
    'info' => ['withInfoAlert', 'info'],
    'warning' => ['withWarningAlert', 'warning'],
    'error' => ['withErrorAlert', 'error'],
]);

test('an alert without a title carries an empty one rather than none', function () {
    // One shape whether or not a caller supplies a title, so the front end
    // reads a string every time instead of guarding for a missing key.
    Route::middleware('web')->get('/_untitled', fn () => redirect('/')->withInfoAlert('Saved.'));

    $this->get('/_untitled')->assertSessionHas('flash.alert', [
        'message' => 'Saved.',
        'title' => '',
        'style' => 'info',
    ]);
});

/*
 * Both live under `flash`, so how they are written decides whether they can
 * travel together. Flashing the whole `flash` key replaces it, which loses
 * whichever was set first and does so only in one of the two orders.
 */
test('a banner and an alert survive the same redirect, either order first', function (string $first, string $second) {
    Route::middleware('web')->get('/_both', fn () => redirect('/')->{$first}('First.')->{$second}('Second.'));

    $this->get('/_both')
        ->assertSessionHas('flash.banner')
        ->assertSessionHas('flash.alert');
})->with([
    'banner first' => ['withInfoBanner', 'withSuccessAlert'],
    'alert first' => ['withSuccessAlert', 'withInfoBanner'],
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

test('the alert dialog is handed text, never markup, message and title alike', function () {
    $component = File::get(base_path('resources/templates/tailwindcss/js/Components/Alert.vue'));

    // SweetAlert2 parses both `html` and `title` as markup and sanitises
    // neither. A flashed alert carries whatever a controller interpolated into
    // it, and a title is as likely to hold a filename as the message is: an
    // earlier version of this test guarded only the message, and the title
    // went out unescaped behind it.
    expect($component)
        ->toContain('text: alert.message')
        ->toContain('titleText: alert.title')
        ->not->toContain('${alert.message}')
        ->not->toContain('title: ');
});

test('the dialog opens inside a modal that is already open', function () {
    $component = File::get(base_path('resources/templates/tailwindcss/js/Components/Alert.vue'));

    // Modal.vue calls showModal(), which puts the dialog in the top layer and
    // makes the rest of the document inert. Firing onto the body from there
    // renders underneath it: invisible, unclickable, and it leaves the scroll
    // lock behind when the modal closes.
    expect($component)->toContain("target: document.querySelector('dialog[open]')");
});

test('an explicit alert is not destroyed by the validation dialog', function () {
    $component = File::get(base_path('resources/templates/tailwindcss/js/Components/Alert.vue'));

    // Only one SweetAlert2 dialog can be open, so firing a second destroys the
    // first. A response carrying both an alert and errors would otherwise show
    // the field names and swallow the sentence somebody wrote.
    expect($component)->toMatch('/if \(alert\) \{\s*showAlert\(alert\);\s*return;/');
});

test('the shared props are published under context', function () {
    // Renamed from `jetstream`: the package is not a dependency, and every
    // component that reads a feature flag reads it from here.
    $this->get('/login')->assertInertia(fn ($page) => $page->has('context.flash'));
});
