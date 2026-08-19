<?php

use Illuminate\Support\Facades\File;

/*
 * Every test starts from a freshly generated context and aggregate, and the
 * teardown only deletes a directory this file created: in a starter kit these
 * tests run inside somebody else's application, and a fixture name that
 * happened to collide would otherwise take their context with it.
 */
beforeEach(function () {
    $this->providers = base_path('bootstrap/providers.php');
    $this->providersBackup = File::get($this->providers);

    expect(app_path('ScaffoldFixture'))->not->toBeDirectory(
        'app/ScaffoldFixture already exists; refusing to run so the teardown cannot delete it.'
    );

    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--events' => true])
        ->assertSuccessful();

    $this->provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');
    $this->createdFixture = true;
});

afterEach(function () {
    File::put($this->providers, $this->providersBackup);

    if ($this->createdFixture ?? false) {
        File::deleteDirectory(app_path('ScaffoldFixture'));
    }
});

test('it creates the handler and declares it in the provider', function () {
    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting',
    ])->assertSuccessful();

    expect(app_path('ScaffoldFixture/Widgets/Application/EventHandlers/NotifyAccounting.php'))->toBeFile();

    // The declaration is the point: a handler missing from $events is never
    // called and nothing anywhere says so.
    expect(File::get($this->provider))
        ->toContain('\App\ScaffoldFixture\Widgets\Domain\Events\WidgetCreated::class => \App\ScaffoldFixture\Widgets\Application\EventHandlers\NotifyAccounting::class,')
        ->and(php_parses($this->provider))->toBeTrue();
});

test('queued makes the handler implement ShouldQueue', function () {
    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting', '--queued' => true,
    ])->assertSuccessful();

    $file = app_path('ScaffoldFixture/Widgets/Application/EventHandlers/NotifyAccounting.php');

    expect(File::get($file))
        ->toContain('use Illuminate\Contracts\Queue\ShouldQueue;')
        ->toContain('final readonly class NotifyAccounting implements ShouldQueue')
        ->and(php_parses($file))->toBeTrue();
});

test('it wires a handler whose short name the provider already imports', function () {
    // Handler and event names are free text, so either can land next to
    // something the provider already imports. Two imports resolving to one
    // short name is a compile-time fatal, and this provider is loaded from
    // bootstrap/providers.php: it took down every request and every artisan
    // call, including the one needed to undo it, from a command that had just
    // printed `wired` in green.
    //
    // Nothing caught it because every other test here generates into a
    // provider this suite created a moment earlier, where what the command
    // means to write and what the file already holds cannot disagree.
    File::put($this->provider, str_replace(
        'use ComplexHeart',
        "use App\Elsewhere\NotifyAccounting;\nuse ComplexHeart",
        File::get($this->provider)
    ));

    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting',
    ])->assertSuccessful();

    expect(php_parses($this->provider))->toBeTrue();
});

test('it defaults to the Created event and accepts another', function () {
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Gadget', '--events' => true,
    ])->assertSuccessful();

    File::copy(
        app_path('ScaffoldFixture/Gadgets/Domain/Events/GadgetCreated.php'),
        $paid = app_path('ScaffoldFixture/Gadgets/Domain/Events/GadgetPaid.php')
    );
    File::put($paid, str_replace('GadgetCreated', 'GadgetPaid', File::get($paid)));

    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Gadget', 'name' => 'OnPaid', '--event' => 'GadgetPaid',
    ])->assertSuccessful();

    expect(File::get($this->provider))->toContain('\App\ScaffoldFixture\Gadgets\Domain\Events\GadgetPaid::class => \App\ScaffoldFixture\Gadgets\Application\EventHandlers\OnPaid::class,');
});

test('it refuses an event the aggregate does not have', function () {
    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'OnPaid', '--event' => 'WidgetPaid',
    ])->assertFailed();

    expect(app_path('ScaffoldFixture/Widgets/Application/EventHandlers'))->not->toBeDirectory();
});

test('it refuses a second handler for the same event', function () {
    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting',
    ])->assertSuccessful();

    // Two entries under one key: PHP keeps the last and drops the first, with
    // no error, so the first handler would quietly stop running.
    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'AlsoNotify',
    ])->assertFailed();

    expect(app_path('ScaffoldFixture/Widgets/Application/EventHandlers/AlsoNotify.php'))->not->toBeFile()
        ->and(substr_count(File::get($this->provider), 'WidgetCreated::class =>'))->toBe(1);
});

test('a commented out mapping does not block the command', function () {
    File::put($this->provider, str_replace(
        'protected array $events = [];',
        "protected array \$events = [\n        // WidgetCreated::class => SomeOldHandler::class,\n    ];",
        File::get($this->provider)
    ));

    // Something parked behind // is not a mapping, and refusing on it leaves
    // the developer with no way to add the handler at all.
    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting',
    ])->assertSuccessful();

    expect(File::get($this->provider))->toContain('\App\ScaffoldFixture\Widgets\Domain\Events\WidgetCreated::class => \App\ScaffoldFixture\Widgets\Application\EventHandlers\NotifyAccounting::class,');
});

test('queued leaves a handler it did not write alone', function () {
    $args = ['context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting'];

    $this->artisan('ldd:make:event-handler', $args)->assertSuccessful();

    // Reached by the command's own recovery path: the handler exists from a
    // run that could not wire it. Importing ShouldQueue into a class that
    // never gains the implements clause is an unused import Pint rejects.
    File::put($this->provider, str_replace(
        '        \App\ScaffoldFixture\Widgets\Domain\Events\WidgetCreated::class => \App\ScaffoldFixture\Widgets\Application\EventHandlers\NotifyAccounting::class,'."\n",
        '',
        File::get($this->provider)
    ));

    $this->artisan('ldd:make:event-handler', $args + ['--queued' => true])
        ->expectsOutputToContain('already existed and was left as it is')
        ->assertSuccessful();

    expect(File::get(app_path('ScaffoldFixture/Widgets/Application/EventHandlers/NotifyAccounting.php')))
        ->not->toContain('ShouldQueue');

    // A handler holding no such class says "implements nothing" just as loudly
    // as one that is simply not queued, and the advice for the two differs.
    File::put(app_path('ScaffoldFixture/Widgets/Application/EventHandlers/NotifyAccounting.php'), "<?php\n");

    // The run above wired the event back, and a second handler for one already
    // mapped is refused before the hint is ever reached.
    File::put($this->provider, str_replace(
        '        \App\ScaffoldFixture\Widgets\Domain\Events\WidgetCreated::class => \App\ScaffoldFixture\Widgets\Application\EventHandlers\NotifyAccounting::class,'."\n",
        '',
        File::get($this->provider)
    ));

    $this->artisan('ldd:make:event-handler', $args + ['--queued' => true])
        ->expectsOutputToContain('could not be read')
        ->doesntExpectOutputToContain('Add `implements ShouldQueue` to it by hand')
        ->assertSuccessful();
});

test('it does not claim to have created a handler it skipped', function () {
    $args = ['context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting'];

    $this->artisan('ldd:make:event-handler', $args)->assertSuccessful();

    File::put($this->provider, str_replace(
        '        \App\ScaffoldFixture\Widgets\Domain\Events\WidgetCreated::class => \App\ScaffoldFixture\Widgets\Application\EventHandlers\NotifyAccounting::class,'."\n",
        '',
        File::get($this->provider)
    ));

    // The run still does something worth reporting, it declares the mapping,
    // but it did not create the handler and must not say it did.
    $this->artisan('ldd:make:event-handler', $args)
        ->expectsOutputToContain('exists, skipped')
        ->expectsOutputToContain('[NotifyAccounting] declared in [ScaffoldFixture]')
        ->assertSuccessful();
});

test('queued says nothing about a handler that is already queued', function () {
    $args = ['context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting', '--queued' => true];

    $this->artisan('ldd:make:event-handler', $args)->assertSuccessful();

    File::put($this->provider, str_replace(
        '        \App\ScaffoldFixture\Widgets\Domain\Events\WidgetCreated::class => \App\ScaffoldFixture\Widgets\Application\EventHandlers\NotifyAccounting::class,'."\n",
        '',
        File::get($this->provider)
    ));

    // The file is skipped either way; the difference is that this one already
    // implements ShouldQueue, so there is nothing left to do by hand.
    $this->artisan('ldd:make:event-handler', $args)
        ->doesntExpectOutputToContain('already existed and was left as it is')
        ->assertSuccessful();
});

test('it refuses to guess at a provider that does not parse', function () {
    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting',
    ])->assertSuccessful();

    File::put($this->provider, str_replace(
        'class ScaffoldFixtureServiceProvider',
        'class ScaffoldFixtureServiceProvider(',
        File::get($this->provider)
    ));

    // Reading it as empty would add a second entry under a key that already
    // has one, and PHP keeps only the last: the first handler stops running
    // with nothing said anywhere.
    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'AlsoNotify',
    ])->assertFailed();

    expect(substr_count(File::get($this->provider), 'WidgetCreated::class =>'))->toBe(1)
        ->and(app_path('ScaffoldFixture/Widgets/Application/EventHandlers/AlsoNotify.php'))->not->toBeFile();
});

test('it fails when the provider has no events array to declare in', function () {
    File::put($this->provider, "<?php\n\nnamespace App\ScaffoldFixture;\n\nuse ComplexHeart\Infrastructure\Laravel\BoundedContextServiceProvider;\n\nclass ScaffoldFixtureServiceProvider extends BoundedContextServiceProvider\n{\n}\n");

    // The handler exists but nothing calls it, so this must not report success.
    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting',
    ])->assertFailed();

    expect(File::get($this->provider))->not->toContain('NotifyAccounting');
});
