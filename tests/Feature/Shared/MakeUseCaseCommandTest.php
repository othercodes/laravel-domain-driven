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
    $this->providers = base_path('bootstrap/providers.php');
    $this->providersBackup = File::get($this->providers);

    expect(app_path('ScaffoldFixture'))->not->toBeDirectory(
        'app/ScaffoldFixture already exists; refusing to run so the teardown cannot delete it.'
    );

    // Set before anything can fail: a fixture half-built by a run that threw
    // is still a bounded context left in app/, and every later run of the
    // suite then refuses at the guard above.
    $this->createdFixture = true;

    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--events' => true])
        ->assertSuccessful();
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

test('it still points at publishes over a use case that already exists', function () {
    // The path ldd:make:aggregate --events sends you down: it prints the very
    // command to run, and running it over a use case already on disk reported
    // "left as it is" and said nothing, while the recorded events still went
    // nowhere. What the domain holds decides this, not what this run wrote.
    $args = ['context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'FindWidget'];

    $this->artisan('ldd:make:use-case', $args)->assertSuccessful();

    $this->artisan('ldd:make:use-case', $args)
        ->expectsOutputToContain('exists, skipped')
        ->expectsOutputToContain('Widget records domain events.')
        ->assertSuccessful();
});

test('it says nothing about publishing when publishes was asked for', function () {
    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'CreateWidget', '--publishes' => true,
    ])
        ->doesntExpectOutputToContain('records domain events')
        ->assertSuccessful();
});

test('publishes over a use case that already exists still says what to do', function () {
    // The path ldd:make:aggregate --events sends you down. Passing the flag it
    // asks for used to leave you with less than omitting it: put() skips the
    // file, the rendered stub is discarded, and the note was behind the flag.
    $args = ['context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'CreateWidget'];

    $this->artisan('ldd:make:use-case', $args)->assertSuccessful();

    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:use-case', $args + ['--publishes' => true]);

    expect(Artisan::output())
        ->toContain('--publishes changed nothing')
        ->toContain('publishDomainEvents($this->eventBus)');
});

test('the publish note names what the constructor has to gain', function () {
    // A use case this run did not write has neither $eventBus nor an import of
    // DB, so the idiom alone does not compile where it is pasted.
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'FindWidget',
    ]);

    expect(Artisan::output())
        ->toContain('add to the constructor: private \ComplexHeart\Domain\Contracts\Events\EventBus $eventBus,')
        ->toContain('\Illuminate\Support\Facades\DB::transaction(');
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

test('the publish idiom it prints is the one the stub writes', function () {
    // Printed as a shape of the idiom rather than the idiom, it does not work:
    // the aggregate is __invoke's parameter, so a closure with no `use` leaves
    // it undefined and saves null against a typed parameter, from inside an
    // open transaction, and __invoke declares a return type the block has to
    // satisfy.
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'FindWidget',
    ]);

    expect(Artisan::output())
        ->toContain('return \Illuminate\Support\Facades\DB::transaction(function () use ($widget): Widget {')
        ->toContain('return $widget;');
});

test('it refuses an aggregate with no repository contract', function () {
    // The aggregate root existing is not enough: both use-case stubs import
    // the contract and type the constructor against it. This repository ships
    // an aggregate without one, APITokens, so the run wrote a use case whose
    // constructor names a class no file declares. It parses, so nothing caught
    // it until the container resolved it.
    $this->artisan('ldd:make:use-case', [
        'context' => 'IdentityAndAccess', 'aggregate' => 'APIToken', 'name' => 'ArchiveToken',
    ])
        ->expectsOutputToContain('has no repository contract')
        ->assertFailed();

    expect(app_path('IdentityAndAccess/APITokens/Application/ArchiveToken.php'))->not->toBeFile();
})->after(function () {
    // Defensive: the command refuses before writing, and if it ever stops
    // refusing this must not leave a file inside a real bounded context.
    File::delete(app_path('IdentityAndAccess/APITokens/Application/ArchiveToken.php'));
});

test('it refuses an aggregate directory whose real spelling is different', function () {
    // The same trap as the context, one level down: app/IdentityAndAccess/APITokens
    // answers to ApiTokens on a case-insensitive filesystem, and ApiToken is
    // what Str::studly makes of api-token.
    $this->artisan('ldd:make:use-case', [
        'context' => 'IdentityAndAccess', 'aggregate' => 'ApiToken', 'name' => 'IssueToken',
    ])
        ->expectsOutputToContain('The aggregate directory on disk is [APITokens]')
        ->assertFailed();

    expect(app_path('IdentityAndAccess/APITokens/Application/IssueToken.php'))->not->toBeFile();
})->skip(
    ! is_dir(dirname(__DIR__, 3).'/app/IdentityAndAccess/apitokens'),
    'the filesystem is case-sensitive, so the name cannot collide'
)->after(function () {
    File::delete(app_path('IdentityAndAccess/APITokens/Application/IssueToken.php'));
});

test('publishes is refused on a root that cannot publish', function () {
    // --publishes writes a body calling Aggregate::new() and then
    // publishDomainEvents() on the result. An aggregate root can be on disk
    // answering neither: this repository ships one, APIToken, which extends
    // Sanctum's PersonalAccessToken and declares only $table. The generated
    // file parses and the class loads, so the first call threw inside an open
    // transaction and nothing saw it earlier.
    //
    // Reproduced on a name of this suite's own, both to keep it out of a real
    // bounded context and because method_exists() autoloads: a name another
    // test has already loaded would answer from the class in memory, not from
    // the file this one just wrote.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Gizmo'])
        ->assertSuccessful();

    $model = app_path('ScaffoldFixture/Gizmos/Domain/Gizmo.php');

    File::put($model, str_replace(
        ["use App\Shared\Domain\HasDomainEvents;\n", "    use HasDomainEvents;\n"],
        '',
        File::get($model)
    ));

    // Asserted, not assumed: a str_replace that matched nothing would leave
    // this test passing against a model that can publish perfectly well.
    expect(File::get($model))->not->toContain('HasDomainEvents');

    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Gizmo', 'name' => 'CreateGizmo', '--publishes' => true,
    ])
        ->expectsOutputToContain('cannot publish domain events')
        ->assertFailed();

    expect(app_path('ScaffoldFixture/Gizmos/Application/CreateGizmo.php'))->not->toBeFile();
});

test('it refuses the plural of an aggregate that exists', function () {
    // Str::plural leaves a plural alone, so the directory check passed for an
    // aggregate that does not exist and the use case was generated against
    // WidgetsRepository and Widgets, neither of which is a class. It parses,
    // so nothing catches it until the container resolves it. The plural is the
    // natural thing to type: it is the directory name ls shows.
    $this->artisan('ldd:make:use-case', [
        'context' => 'ScaffoldFixture', 'aggregate' => 'Widgets', 'name' => 'ArchiveWidget',
    ])
        ->expectsOutputToContain('The aggregate [Widgets] does not exist')
        ->assertFailed();

    expect(app_path('ScaffoldFixture/Widgets/Application/ArchiveWidget.php'))->not->toBeFile();
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
