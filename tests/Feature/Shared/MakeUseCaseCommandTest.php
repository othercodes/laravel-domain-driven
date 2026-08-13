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

test('it never overwrites a use case that exists', function () {
    $args = ['context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'FindWidget'];

    $this->artisan('ldd:make:use-case', $args)->assertSuccessful();

    $file = app_path('ScaffoldFixture/Widgets/Application/FindWidget.php');
    File::put($file, File::get($file)."\n// hand written\n");

    $this->artisan('ldd:make:use-case', $args)->assertSuccessful();

    expect(File::get($file))->toContain('// hand written');
});
