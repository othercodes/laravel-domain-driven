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
    Route::middleware('web')->get('/_banner', fn () => redirect('/login')->{$macro}('Invoice paid.'));

    $this->followingRedirects()->get('/_banner')->assertInertia(
        fn ($page) => $page->hasFlash('banner', ['message' => 'Invoice paid.', 'style' => $style])
    );
})->with([
    'success' => ['withSuccessBanner', 'success'],
    'info' => ['withInfoBanner', 'info'],
    'warning' => ['withWarningBanner', 'warning'],
    'error' => ['withErrorBanner', 'danger'],
]);

test('every alert macro flashes the agreed shape', function (string $macro, string $style) {
    Route::middleware('web')->get('/_alert', fn () => redirect('/login')->{$macro}('Card declined.', 'Payment failed'));

    $this->followingRedirects()->get('/_alert')->assertInertia(fn ($page) => $page->hasFlash('alert', [
        'message' => 'Card declined.',
        'title' => 'Payment failed',
        'style' => $style,
    ]));
})->with([
    'success' => ['withSuccessAlert', 'success'],
    'info' => ['withInfoAlert', 'info'],
    'warning' => ['withWarningAlert', 'warning'],
    'error' => ['withErrorAlert', 'error'],
]);

test('an alert without a title carries an empty one rather than none', function () {
    // One shape whether or not a caller supplies a title, so the front end
    // reads a string every time instead of guarding for a missing key.
    Route::middleware('web')->get('/_untitled', fn () => redirect('/login')->withInfoAlert('Saved.'));

    $this->followingRedirects()->get('/_untitled')->assertInertia(fn ($page) => $page->hasFlash('alert', [
        'message' => 'Saved.',
        'title' => '',
        'style' => 'info',
    ]));
});

/*
 * Both are flashed into one array, so how they are written decides whether they
 * can travel together: each macro has to spread over what is already there
 * rather than replace it, or the first of the two goes missing, and only in
 * one of the two orders.
 */
test('a banner and an alert survive the same redirect, either order first', function (string $first, string $second) {
    Route::middleware('web')->get('/_both', fn () => redirect('/login')->{$first}('First.')->{$second}('Second.'));

    $this->followingRedirects()->get('/_both')->assertInertia(
        fn ($page) => $page->hasFlash('banner')->hasFlash('alert')
    );
})->with([
    'banner first' => ['withInfoBanner', 'withSuccessAlert'],
    'alert first' => ['withSuccessAlert', 'withInfoBanner'],
]);

test('the component reads the keys the macros write, and styles every one', function () {
    $component = File::get(base_path('resources/templates/tailwindcss/js/Components/Banner.vue'));

    // Named here because no test can render the component: the two sides have
    // to agree on these two keys and there is nothing else to notice if they
    // stop agreeing. `page.flash`, not `page.props`: the payload no longer
    // travels as a prop, and reading it from there compiles, renders nothing
    // and says nothing about why.
    expect($component)
        ->toContain('page.flash?.banner?.style')
        ->toContain('page.flash?.banner?.message');

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
        ->toContain('page.flash?.alert')
        ->toContain('text: alert.message')
        ->toContain('titleText: alert.title')
        ->not->toContain('${alert.message}')
        ->not->toContain('title: ')
        ->not->toContain('html:');
});

test('the dialog opens inside a modal that is already open', function () {
    $component = File::get(base_path('resources/templates/tailwindcss/js/Components/Alert.vue'));

    // Modal.vue calls showModal(), which puts the dialog in the top layer and
    // makes the rest of the document inert. Firing onto the body from there
    // renders underneath it: invisible, unclickable, and it leaves the scroll
    // lock behind when the modal closes.
    expect($component)->toContain("target: document.querySelector('dialog[open]')");

    // And resolved after the DOM settles. On the default flush the effect runs
    // while the outgoing page is still mounted, so the query can find a dialog
    // that is on its way out and mount into it.
    expect($component)->toContain("{ flush: 'post' }");
});

test('the alert component is mounted beside the page, not inside a layout', function () {
    // AppLayout wraps authenticated pages only, and the seven pages under
    // Auth use none of it. Mounted there, a flashed alert would reach a
    // logged-in user and silently do nothing on login or password reset,
    // where the props carrying it are shared just the same.
    expect(File::get(base_path('resources/templates/tailwindcss/js/app.js')))
        ->toContain('h(Alert)')
        ->and(File::get(base_path('resources/templates/tailwindcss/js/Layouts/AppLayout.vue')))
        ->not->toContain('Alert');
});

test('the dialog is driven by the flash alone, never by the errors prop', function () {
    $component = File::get(base_path('resources/templates/tailwindcss/js/Components/Alert.vue'));

    // The dialog used to fire for validation errors as well. Those are an
    // ordinary prop, so the browser kept them in the history entry and a
    // scoped reload merged them back: it reopened on the Back button and on
    // every tick of a router.reload(). No amount of backend work fixes that,
    // because going Back sends no request at all.
    //
    // Removing it cost nothing. Every form here posts through useForm, whose
    // onError fills form.errors, and every page renders those under the field
    // they belong to, so the dialog said the same thing twice and further from
    // where it could be fixed. A controller that wants an interruption asks
    // for one with withErrorAlert().
    //
    // Comments are stripped first, both kinds: this is a claim about the code,
    // and the next person to explain why errors are not read here should not
    // have to word it around a test.
    $code = preg_replace(['/\/\*.*?\*\//s', '/^\s*\/\/.*$/m'], '', $component);

    expect($code)->not->toContain('errors');
});

test('the shared props are published under context', function () {
    // Renamed from `jetstream`: the package is not a dependency, and every
    // component that reads a feature flag reads it from here.
    $this->get('/login')->assertInertia(fn ($page) => $page->has('context.canUpdatePassword'));
});

/*
 * The point of flashing through Inertia rather than sharing a prop. A shared
 * prop is written into the browser's history entry and merged back on a
 * partial reload of the same component, so the banner returns on Back and the
 * dialog re-opens on a page that never flashed it, once per router.reload().
 *
 * Neither half is visible from PHP, so what is pinned here is the arrangement
 * that rules them out: the payload is never a prop, and it is spent by the
 * response that carried it.
 */
test('the flash payload is not a prop, and does not outlive the response carrying it', function () {
    Route::middleware('web')->get('/_once', fn () => redirect('/login')->withSuccessBanner('Invoice paid.')->withInfoAlert('Saved.'));

    $this->followingRedirects()->get('/_once')->assertInertia(
        fn ($page) => $page->hasFlash('banner')->missing('context.flash')
    );

    $this->get('/login')->assertInertia(
        fn ($page) => $page->missingFlash('banner')->missingFlash('alert')
    );
});
