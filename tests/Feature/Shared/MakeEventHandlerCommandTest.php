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

test('it creates the handler and says how to declare it', function () {
    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting',
    ])
        // The declaration is the point: a handler missing from $events is
        // never called and nothing anywhere says so. It is printed rather
        // than appended because $events is keyed by the event, and what
        // should happen when the key is taken is not this command's call.
        ->expectsOutputToContain('in $events')
        ->expectsOutputToContain('\App\ScaffoldFixture\Widgets\Domain\Events\WidgetCreated::class => \App\ScaffoldFixture\Widgets\Application\EventHandlers\NotifyAccounting::class,')
        ->assertSuccessful();

    $file = app_path('ScaffoldFixture/Widgets/Application/EventHandlers/NotifyAccounting.php');

    expect($file)->toBeFile()
        ->and(php_parses($file))->toBeTrue();
});

test('it leaves the provider exactly as it found it', function () {
    // The invariant the whole redesign rests on. A provider is loaded from
    // bootstrap/providers.php, so a bad edit here took down every request and
    // every artisan call, including the one needed to undo it, from a command
    // that had just printed `wired` in green. Nothing this command does can
    // reach that file any more.
    $before = File::get($this->provider);

    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting',
    ])->assertSuccessful();

    expect(File::get($this->provider))->toBe($before);
});

test('a name the provider already imports is still generated and still compiles', function () {
    // Handler names are free text, so one can land next to something the
    // provider already imports. That used to be a fatal, because the entry
    // was appended under its short name. The report writes it out in full, so
    // there is nothing to collide with.
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
    ])
        ->expectsOutputToContain('\App\ScaffoldFixture\Gadgets\Domain\Events\GadgetPaid::class => \App\ScaffoldFixture\Gadgets\Application\EventHandlers\OnPaid::class,')
        ->assertSuccessful();
});

test('it refuses an event the aggregate does not have', function () {
    // Prerequisites cascade and nothing is generated upwards: the event
    // belongs to the aggregate, and the command that owns the aggregate is
    // the one that makes it.
    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'OnPaid', '--event' => 'WidgetPaid',
    ])
        ->expectsOutputToContain('ldd:make:aggregate ScaffoldFixture Widget --events')
        ->assertFailed();

    expect(app_path('ScaffoldFixture/Widgets/Application/EventHandlers'))->not->toBeDirectory();
});

test('it refuses an aggregate that does not exist', function () {
    $this->artisan('ldd:make:event-handler', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Nope', 'name' => 'OnThing',
    ])
        ->expectsOutputToContain('ldd:make:aggregate ScaffoldFixture Nope')
        ->assertFailed();
});

test('it refuses a context that does not exist', function () {
    $this->artisan('ldd:make:event-handler', [
        'context' => 'Nope', 'aggregate' => 'Widget', 'name' => 'OnThing',
    ])
        ->expectsOutputToContain('ldd:make:bounded-context Nope')
        ->assertFailed();
});

test('it does not claim to have created a handler it skipped', function () {
    $args = ['context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting'];

    $this->artisan('ldd:make:event-handler', $args)->assertSuccessful();

    // Saying "created" over the top of its own "exists, skipped" is how a run
    // that did nothing reads like one that did. The mapping is still printed:
    // whether it was ever pasted is not something this command can know.
    $this->artisan('ldd:make:event-handler', $args)
        ->expectsOutputToContain('exists, skipped')
        ->expectsOutputToContain('[NotifyAccounting] left as it is')
        ->assertSuccessful();
});

test('queued leaves a handler it did not write alone, and says so', function () {
    $args = ['context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting'];

    $this->artisan('ldd:make:event-handler', $args)->assertSuccessful();

    // Nothing rewrites a file that is already there, so --queued on a handler
    // this run did not write cannot make it queued.
    $this->artisan('ldd:make:event-handler', $args + ['--queued' => true])
        ->expectsOutputToContain('already existed and was left as it is')
        ->expectsOutputToContain('implements ShouldQueue')
        ->assertSuccessful();

    expect(File::get(app_path('ScaffoldFixture/Widgets/Application/EventHandlers/NotifyAccounting.php')))
        ->not->toContain('ShouldQueue');
});

test('it never overwrites a handler that exists', function () {
    $args = ['context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting'];

    $this->artisan('ldd:make:event-handler', $args)->assertSuccessful();

    $file = app_path('ScaffoldFixture/Widgets/Application/EventHandlers/NotifyAccounting.php');
    File::put($file, File::get($file)."\n// hand written\n");

    $this->artisan('ldd:make:event-handler', $args)->assertSuccessful();

    expect(File::get($file))->toContain('// hand written');
});
