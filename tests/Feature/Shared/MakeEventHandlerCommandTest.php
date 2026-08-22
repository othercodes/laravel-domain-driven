<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
 * Every test starts from a freshly generated context and aggregate, and the
 * teardown only deletes a directory this file created: in a starter kit these
 * tests run inside somebody else's application, and a fixture name that
 * happened to collide would otherwise take their context with it.
 */
beforeEach(function () {
    $this->providers = scaffold_into_a_copy_of_the_app();

    expect(app_path('HandlerFixture'))->not->toBeDirectory(
        'app/HandlerFixture already exists; refusing to run so the teardown cannot delete it.'
    );

    // Set before anything can fail: a fixture half-built by a run that threw
    // is still a bounded context left in app/, and every later run of the
    // suite then refuses at the guard above.
    $this->createdFixture = true;

    $this->artisan('ldd:make:bounded-context', ['name' => 'HandlerFixture'])->assertSuccessful();
    $this->artisan('ldd:make:aggregate', ['context' => 'HandlerFixture', 'name' => 'Widget', '--events' => true])
        ->assertSuccessful();

    $this->provider = app_path('HandlerFixture/HandlerFixtureServiceProvider.php');
});

afterEach(function () {
    // The whole copy, which is where every fixture this file makes now
    // lives. Left behind, /tmp collected one per worker per run.
    File::deleteDirectory(dirname($this->providers));
});

test('it creates the handler and says how to declare it', function () {
    $this->artisan('ldd:make:event-handler', [
        'context' => 'HandlerFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting',
    ])
        // The declaration is the point: a handler missing from $events is
        // never called and nothing anywhere says so. It is printed rather
        // than appended because $events is keyed by the event, and what
        // should happen when the key is taken is not this command's call.
        ->expectsOutputToContain('in $events')
        ->expectsOutputToContain('\App\HandlerFixture\Widgets\Domain\Events\WidgetCreated::class => \App\HandlerFixture\Widgets\Application\EventHandlers\NotifyAccounting::class,')
        ->assertSuccessful();

    $file = app_path('HandlerFixture/Widgets/Application/EventHandlers/NotifyAccounting.php');

    expect($file)->toBeFile()
        ->and(php_parses($file))->toBeTrue();
});

test('the report says it did not read the files, and how a second handler is added', function () {
    // The headings are imperatives about files this run neither read nor
    // wrote, so the report says that once, over all of them. Without it a
    // heading reads as a statement about the file it names.
    //
    // $events is the one where following the imperative blindly loses data:
    // the key is the event, and PHP keeps only the last entry under it, so a
    // second handler pasted in stops the first from running with nothing said
    // anywhere. This repository ships one such mapping already.
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:event-handler', [
        'context' => 'HandlerFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting',
    ]);

    expect(Artisan::output())
        ->toContain('Nothing below was wired.')
        ->toContain('neither read nor written by this run')
        ->toContain('PHP keeps only the last')
        // The form that loses neither handler. bootEvents() listens for every
        // entry when the value is a list.
        ->toContain('\App\HandlerFixture\Widgets\Domain\Events\WidgetCreated::class => [')
        ->toContain('// the handler already mapped to it, first');
});

test('it leaves the provider exactly as it found it', function () {
    // The invariant the whole redesign rests on. A provider is loaded from
    // bootstrap/providers.php, so a bad edit here took down every request and
    // every artisan call, including the one needed to undo it, from a command
    // that had just printed `wired` in green. Nothing this command does can
    // reach that file any more.
    $before = File::get($this->provider);

    $this->artisan('ldd:make:event-handler', [
        'context' => 'HandlerFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting',
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
        'context' => 'HandlerFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting',
    ])->assertSuccessful();

    // The handler itself, which is what the name of this test is about.
    // Neither a green exit nor a parseable provider can observe it: handle()
    // returns SUCCESS unconditionally and never opens the provider, so both
    // assertions below held with nothing written at all.
    $handler = app_path('HandlerFixture/Widgets/Application/EventHandlers/NotifyAccounting.php');

    expect($handler)->toBeFile()
        ->and(php_parses($handler))->toBeTrue()
        ->and(php_parses($this->provider))->toBeTrue();
});

test('queued makes the handler implement ShouldQueue', function () {
    $this->artisan('ldd:make:event-handler', [
        'context' => 'HandlerFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting', '--queued' => true,
    ])->assertSuccessful();

    $file = app_path('HandlerFixture/Widgets/Application/EventHandlers/NotifyAccounting.php');

    expect(File::get($file))
        ->toContain('use Illuminate\Contracts\Queue\ShouldQueue;')
        ->toContain('final readonly class NotifyAccounting implements ShouldQueue')
        ->and(php_parses($file))->toBeTrue();
});

test('it defaults to the Created event and accepts another', function () {
    $this->artisan('ldd:make:aggregate', [
        'context' => 'HandlerFixture', 'name' => 'Gadget', '--events' => true,
    ])->assertSuccessful();

    File::copy(
        app_path('HandlerFixture/Gadgets/Domain/Events/GadgetCreated.php'),
        $paid = app_path('HandlerFixture/Gadgets/Domain/Events/GadgetPaid.php')
    );
    File::put($paid, str_replace('GadgetCreated', 'GadgetPaid', File::get($paid)));

    $this->artisan('ldd:make:event-handler', [
        'context' => 'HandlerFixture', 'aggregate' => 'Gadget', 'name' => 'OnPaid', '--event' => 'GadgetPaid',
    ])
        ->expectsOutputToContain('\App\HandlerFixture\Gadgets\Domain\Events\GadgetPaid::class => \App\HandlerFixture\Gadgets\Application\EventHandlers\OnPaid::class,')
        ->assertSuccessful();
});

test('it refuses an event the aggregate does not have', function () {
    // Prerequisites cascade and nothing is generated upwards: the event
    // belongs to the aggregate, and the command that owns the aggregate is
    // the one that makes it.
    $this->artisan('ldd:make:event-handler', [
        'context' => 'HandlerFixture', 'aggregate' => 'Widget', 'name' => 'OnPaid', '--event' => 'WidgetPaid',
    ])
        ->expectsOutputToContain('ldd:make:aggregate HandlerFixture Widget --events')
        ->assertFailed();

    expect(app_path('HandlerFixture/Widgets/Application/EventHandlers'))->not->toBeDirectory();
});

test('it refuses a mis-cased event name', function () {
    // The handler stub imports Domain\Events\{event} by the spelling given, so
    // a run that found the file through a case-insensitive filesystem writes
    // an import that resolves nowhere else.
    $this->artisan('ldd:make:event-handler', [
        'context' => 'HandlerFixture', 'aggregate' => 'Widget', 'name' => 'OnCreated', '--event' => 'widgetcreated',
    ])
        ->expectsOutputToContain('The event on disk is [WidgetCreated]')
        ->assertFailed();

    expect(app_path('HandlerFixture/Widgets/Application/EventHandlers/OnCreated.php'))->not->toBeFile();
});

test('it refuses an aggregate that does not exist', function () {
    $this->artisan('ldd:make:event-handler', [
        'context' => 'HandlerFixture', 'aggregate' => 'Nope', 'name' => 'OnThing',
    ])
        ->expectsOutputToContain('ldd:make:aggregate HandlerFixture Nope')
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
    $args = ['context' => 'HandlerFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting'];

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
    $args = ['context' => 'HandlerFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting'];

    $this->artisan('ldd:make:event-handler', $args)->assertSuccessful();

    // Nothing rewrites a file that is already there, so --queued on a handler
    // this run did not write cannot make it queued.
    $this->artisan('ldd:make:event-handler', $args + ['--queued' => true])
        // One expectation per emitted line: both of these live on the same
        // heading, and each is consumed as it matches. The wording is pinned
        // in full by the Artisan::output() test above.
        ->expectsOutputToContain('already existed and was left as it is')
        ->assertSuccessful();

    expect(File::get(app_path('HandlerFixture/Widgets/Application/EventHandlers/NotifyAccounting.php')))
        ->not->toContain('ShouldQueue');
});

test('the queued note never asks for an import, and never says the handler is not queued', function () {
    $args = ['context' => 'HandlerFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting', '--queued' => true];

    $this->artisan('ldd:make:event-handler', $args)->assertSuccessful();

    // The second run reaches the note over a handler the first run already
    // made queued, and nothing reads it back to find that out. An import
    // pasted into a file that already has it is a fatal, in a class the
    // provider lists in $events.
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:event-handler', $args);

    expect(Artisan::output())
        ->toContain('If it is not queued already')
        ->toContain('\\Illuminate\\Contracts\\Queue\\ShouldQueue')
        ->not->toContain('use Illuminate\\Contracts\\Queue\\ShouldQueue;')
        // Not the clause either. Beside an interface the handler already has,
        // what is needed is a comma, not a second `implements`; the name alone
        // is right whether or not there is one.
        ->not->toContain('implements \\Illuminate\\Contracts\\Queue\\ShouldQueue')
        // The clause, not the declaration it goes on. Printing the whole line
        // is the one place the report told anybody to replace existing text,
        // written without reading the file: a handler given another interface,
        // or with readonly taken off to hold state, loses that when it is
        // followed.
        ->not->toContain('final readonly class NotifyAccounting implements');
});

test('it hedges the mapping for a handler that was already on disk', function () {
    // The handler was written against whatever event it was written against,
    // and nothing here reads its handle() signature. Mapping it to this event
    // compiles and then fails at dispatch, or silently does the wrong thing.
    $args = ['context' => 'HandlerFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting'];

    $this->artisan('ldd:make:event-handler', $args)->assertSuccessful();

    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:event-handler', $args);

    expect(Artisan::output())->toContain('check its handle() takes WidgetCreated before mapping it');
});

test('it never overwrites a handler that exists', function () {
    $args = ['context' => 'HandlerFixture', 'aggregate' => 'Widget', 'name' => 'NotifyAccounting'];

    $this->artisan('ldd:make:event-handler', $args)->assertSuccessful();

    $file = app_path('HandlerFixture/Widgets/Application/EventHandlers/NotifyAccounting.php');
    File::put($file, File::get($file)."\n// hand written\n");

    $this->artisan('ldd:make:event-handler', $args)->assertSuccessful();

    expect(File::get($file))->toContain('// hand written');
});
