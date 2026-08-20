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

    $this->createdFixture = true;
});

afterEach(function () {
    File::put($this->providers, $this->providersBackup);

    if ($this->createdFixture ?? false) {
        File::deleteDirectory(app_path('ScaffoldFixture'));
    }
});

test('a function import in a stub does not take a class name', function () {
    // Functions and constants have their own symbol tables, so neither can
    // collide with a class. The skip for them was written but applied only to
    // the side the comparison was asked about, so a `use function` line
    // reaching it as the candidate lost its prefix along with its namespace
    // and read as taken by an earlier Money import. Every use case name was
    // then refused, over a file PHP compiles without complaint, and no name
    // the caller could pick would have helped.
    //
    // stubs/ is the one directory here meant to be edited, which is the whole
    // premise of reading the guard's vocabulary out of it.
    $stub = base_path('stubs/use-case.stub');
    $original = File::get($stub);

    File::put($stub, str_replace(
        'use App\{{ context }}\{{ plural }}\Domain\{{ aggregate }};',
        "use App\Shared\Domain\Money;\nuse function App\Shared\Domain\money;\nuse App\{{ context }}\{{ plural }}\Domain\{{ aggregate }};",
        $original
    ));

    try {
        $this->artisan('ldd:make:use-case', [
            'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'CreateWidget',
        ])->assertSuccessful();

        // And the real collision is still caught with that stub in place.
        $this->artisan('ldd:make:use-case', [
            'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'Widget',
        ])->assertFailed();
    } finally {
        File::put($stub, $original);
    }
});

test('it refuses a use case named after the aggregate it acts on', function (string $name) {
    // Both use-case stubs import the aggregate and its repository, so a use
    // case under either name lands on its own import: two things under one
    // short name, which PHP refuses to compile.
    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => $name,
    ])
        ->expectsOutputToContain('would not compile, so nothing was kept')
        ->assertFailed();

    expect(app_path('ScaffoldFixture/Widgets/Application/'.$name.'.php'))->not->toBeFile();
})->with(['the aggregate' => 'Widget', 'its repository' => 'WidgetRepository']);

test('it creates the use case in the aggregate application layer', function () {
    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'FindWidget',
    ])->assertSuccessful();

    $file = app_path('ScaffoldFixture/Widgets/Application/FindWidget.php');

    expect($file)->toBeFile()
        ->and(File::get($file))
        ->toContain('namespace App\ScaffoldFixture\Widgets\Application;')
        ->toContain('final readonly class FindWidget')
        // The application layer reaches the domain through the contract, which
        // is what the architecture rules check and what keeps it testable.
        ->toContain('private WidgetRepository $repository')
        ->and(php_parses($file))->toBeTrue();
});

test('publishes scaffolds the transaction and the publish call', function () {
    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'CreateWidget', '--publishes' => true,
    ])->assertSuccessful();

    $file = app_path('ScaffoldFixture/Widgets/Application/CreateWidget.php');

    // An aggregate only records its events. Without this the recorded events
    // pile up on the instance and vanish with it.
    expect(File::get($file))
        ->toContain('private EventBus $eventBus')
        ->toContain('DB::transaction(')
        ->toContain('$widget->publishDomainEvents($this->eventBus);')
        ->and(php_parses($file))->toBeTrue();
});

test('it points at publishes when the aggregate records events', function () {
    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'FindWidget',
    ])
        ->expectsOutputToContain('Widget records domain events.')
        ->assertSuccessful();
});

test('it stops advising publishes once something publishes', function () {
    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'CreateWidget', '--publishes' => true,
    ])->assertSuccessful();

    // The loop is closed, and a read use case should not publish anyway.
    // ldd:make:aggregate asks the same question; the two must agree.
    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'FindWidget',
    ])
        ->doesntExpectOutputToContain('records domain events')
        ->assertSuccessful();
});

test('it stays quiet when the aggregate has no events to lose', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Gadget'])
        ->assertSuccessful();

    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Gadget', 'name' => 'FindGadget',
    ])
        ->doesntExpectOutputToContain('records domain events')
        ->assertSuccessful();
});

test('it refuses an aggregate that does not exist', function () {
    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Nope', 'name' => 'DoThing',
    ])->assertFailed();

    expect(app_path('ScaffoldFixture/Nopes'))->not->toBeDirectory();
});

test('it refuses a context that does not exist', function () {
    $this->artisan('ldd:make:use-case', [
        'context' => 'Nope', 'aggregate' => 'Widget', 'name' => 'DoThing',
    ])->assertFailed();
});

test('it does not claim to have created a file it skipped', function () {
    $args = ['context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'FindWidget'];

    $this->artisan('ldd:make:use-case', $args)->assertSuccessful();

    // Saying "created" over the top of its own "exists, skipped" is how a run
    // that did nothing reads like one that did.
    $this->artisan('ldd:make:use-case', $args)
        ->expectsOutputToContain('exists, skipped')
        ->expectsOutputToContain('[FindWidget] left as it is')
        ->assertSuccessful();
});

test('it never overwrites a use case that exists', function () {
    $args = ['context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'FindWidget'];

    $this->artisan('ldd:make:use-case', $args)->assertSuccessful();

    $file = app_path('ScaffoldFixture/Widgets/Application/FindWidget.php');
    File::put($file, File::get($file)."\n// hand written\n");

    $this->artisan('ldd:make:use-case', $args)->assertSuccessful();

    expect(File::get($file))->toContain('// hand written');
});
