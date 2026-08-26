<?php

use Illuminate\Support\Facades\File;

/*
 * The command writes into app/ and bootstrap/providers.php, so every test
 * restores both afterwards.
 *
 * The teardown only deletes directories that were absent when the test
 * started: these tests run inside somebody else's application, and a context
 * name that happened to collide would otherwise be destroyed.
 */
beforeEach(function () {
    $this->providers = scaffold_into_a_copy_of_the_app();

    // BoundedContext is only ever created if the guard on that name stops
    // working, and a leaked context joins $contexts in the arch suite, so it is
    // guarded and torn down like the two this file means to create.
    foreach (['ContextFixture', 'NestedContextFixture', 'BoundedContext'] as $fixture) {
        expect(app_path($fixture))->not->toBeDirectory(
            "app/{$fixture} already exists; refusing to run so the teardown cannot delete it."
        );
    }

    $this->createdFixture = true;
});

afterEach(function () {
    // Removed here rather than in the test body: an assertion that fails
    // leaves whatever the body had not reached yet, and a leaked context
    // makes every later run of the suite fail too.

    // The whole copy, which is where every fixture this file makes now
    // lives. Left behind, /tmp collected one per worker per run.
    File::deleteDirectory(dirname($this->providers));
});

test('it scaffolds the context and registers its provider', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])
        ->assertSuccessful();

    expect(app_path('ContextFixture/ContextFixtureServiceProvider.php'))->toBeFile();

    // Registered through Laravel's own helper, the one make:provider uses.
    // It writes every entry fully qualified with no import, which is why this
    // is the one place these commands still edit a file they did not create.
    expect(File::get($this->providers))
        ->toContain('App\ContextFixture\ContextFixtureServiceProvider::class,')
        ->and(php_parses($this->providers))->toBeTrue();
});

test('it refuses the one name whose provider would extend itself', function () {
    // The provider stub imports the base provider and declares
    // <Context>ServiceProvider beside it, and this is the only command that
    // registers what it writes: the fatal would be in a file loaded from
    // bootstrap/providers.php, taking down artisan along with the app.
    $this->artisan('ldd:make:bounded-context', ['name' => 'BoundedContext', 'prefix' => 'bnd'])
        ->expectsOutputToContain('would produce a provider that extends itself')
        ->assertFailed();

    expect(app_path('BoundedContext'))->not->toBeDirectory()
        ->and(File::get($this->providers))->not->toContain('BoundedContext');
});

test('the names it refuses are refused however they are typed', function (string $name, string $reason) {
    // PHP resolves class names without case, so `boundedcontext` studlies to
    // `Boundedcontext` and still declares the same class as `BoundedContext`,
    // and Str::studly leaves inner case alone. Compared with ===, both guards
    // let an ordinary lowercase name straight through, and the first one ends
    // with an unbootable provider registered in bootstrap/providers.php.
    $this->artisan('ldd:make:bounded-context', ['name' => $name, 'prefix' => 'ctx'])
        ->expectsOutputToContain($reason)
        ->assertFailed();

    expect(File::get($this->providers))->not->toContain('Boundedcontext');
})->with([
    'lowercase reserved' => ['boundedcontext', 'would produce a provider that extends itself'],
    'mixed case reserved' => ['bOundedContext', 'would produce a provider that extends itself'],
    'mixed case Shared' => ['sHared', 'already exists as the application foundation layer'],
]);

test('the provider stub imports nothing but the base provider', function () {
    // What makes one reserved name enough. A second import in the stub is a
    // second name that would collide, and it would be found the way the first
    // one was: by taking the application down.
    preg_match_all('/^use (.+);$/m', File::get(base_path('stubs/bounded-context.provider.stub')), $imports);

    expect($imports[1])->toBe(['ComplexHeart\Infrastructure\Laravel\BoundedContextServiceProvider']);
});

test('the provider stub accepts the list form its own report tells you to write', function () {
    // ldd:make:event-handler prints the array form when an event already has a
    // handler, and ComplexHeart's bootEvents() listens for every entry in it.
    // Annotated more narrowly than the parent, PHPStan then rejects a provider
    // this same toolchain generated four steps earlier.
    expect(File::get(base_path('stubs/bounded-context.provider.stub')))
        ->toContain('@var array<class-string, class-string|array<int, class-string>>');
});

test('it refuses a context whose real spelling on disk is different', function () {
    // The filesystem answers yes to any casing on macOS, so this found the
    // real IdentityAndAccess, called its provider "exists, skipped", and then
    // registered App\Identityandaccess\IdentityandaccessServiceProvider beside
    // the real entry: the same context booted twice here, and nothing that
    // resolves on Linux.
    $this->artisan('ldd:make:bounded-context', ['name' => 'identityandaccess', 'prefix' => 'iaa'])
        ->expectsOutputToContain('The bounded context on disk is [IdentityAndAccess]')
        ->assertFailed();

    expect(File::get($this->providers))->not->toContain('Identityandaccess');
});

test('it creates no layer directories', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])->assertSuccessful();

    expect(app_path('ContextFixture/Domain'))->not->toBeDirectory()
        ->and(app_path('ContextFixture/Application'))->not->toBeDirectory()
        ->and(app_path('ContextFixture/Infrastructure'))->not->toBeDirectory();
});

test('route files are opt in', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])->assertSuccessful();

    expect(app_path('ContextFixture/Shared/Infrastructure/Http/Routes/web.php'))->not->toBeFile();

    // The convention is documented as a comment, never as a live property
    // pointing at a file that does not exist.
    expect(File::get(app_path('ContextFixture/ContextFixtureServiceProvider.php')))
        ->toContain('// protected array $routes')
        ->not->toMatch('/^\s+protected array \$routes/m');
});

test('it declares the route files it generates', function () {
    // A context comes out fully wired, which is what earns every later
    // command the right to wire nothing: the provider is written in the same
    // run, from the same options, so it already declares them.
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx', '--web' => true, '--api' => true])
        ->doesntExpectOutputToContain('Register the route files')
        ->assertSuccessful();

    expect(app_path('ContextFixture/Shared/Infrastructure/Http/Routes/web.php'))->toBeFile()
        ->and(app_path('ContextFixture/Shared/Infrastructure/Http/Routes/api.php'))->toBeFile();

    expect(File::get(app_path('ContextFixture/ContextFixtureServiceProvider.php')))
        ->toContain("'web' => [__DIR__.'/Shared/Infrastructure/Http/Routes/web.php']")
        ->toContain("'api' => [__DIR__.'/Shared/Infrastructure/Http/Routes/api.php']");
});

test('re-running never rewrites the provider', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])->assertSuccessful();

    $provider = app_path('ContextFixture/ContextFixtureServiceProvider.php');

    File::put($provider, str_replace(
        'public array $bindings = [];',
        "public array \$bindings = [\n        Something::class => SomethingElse::class,\n    ];",
        File::get($provider)
    ));

    $before = File::get($provider);

    // Asking for route files on a context already in use is the natural way
    // to run this twice; rewriting the provider from the stub would drop
    // every binding and migration path it has accumulated.
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx', '--web' => true])
        ->assertSuccessful();

    expect(File::get($provider))->toBe($before);
});

test('re-running creates the missing route file and says how to declare it', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])->assertSuccessful();

    // The file alone is never loaded: the provider has to declare it, and
    // the provider written by an earlier run is one this run will not touch.
    // The only symptom of getting this wrong is a 404.
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx', '--web' => true])
        ->expectsOutputToContain('in $routes')
        ->expectsOutputToContain("'web' => [__DIR__.'/Shared/Infrastructure/Http/Routes/web.php'],")
        ->assertSuccessful();

    expect(app_path('ContextFixture/Shared/Infrastructure/Http/Routes/web.php'))->toBeFile();
});

test('it asks for the $routes entry even when it wrote nothing at all', function () {
    // The state that most needs saying, and the one keying this on what put()
    // wrote used to skip in silence: the provider and the route file are both
    // there, and nothing knows whether $routes names the file. Reached by
    // creating the route file by hand, exactly as the comment the provider
    // stub ships instructs, and then running this to have the tool sort it
    // out. Undeclared, bootRoutes() never loads it and every route in it 404s,
    // over a run that printed green.
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])->assertSuccessful();

    $file = app_path('ContextFixture/Shared/Infrastructure/Http/Routes/web.php');
    File::ensureDirectoryExists(dirname($file));
    File::put($file, "<?php\n");

    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx', '--web' => true])
        ->expectsOutputToContain('exists, skipped')
        ->expectsOutputToContain('in $routes')
        ->expectsOutputToContain("'web' => [__DIR__.'/Shared/Infrastructure/Http/Routes/web.php'],")
        // The one property in the whole report that may not be declared at
        // all: this context was created without a route flag, so it carries
        // $routes as a commented example. An entry pasted into a class body
        // with no array around it is a parse error.
        ->expectsOutputToContain('replace it with a real')
        ->assertSuccessful();
});

test('it says nothing about routes when no route file was asked for', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])->assertSuccessful();

    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])
        ->expectsOutputToContain('exists, skipped')
        ->doesntExpectOutputToContain('in $routes')
        ->assertSuccessful();
});

test('it normalises the context name', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'context_fixture', 'prefix' => 'ctx'])->assertSuccessful();

    expect(app_path('ContextFixture/ContextFixtureServiceProvider.php'))->toBeFile();
});

test('it registers the provider however the list is written', function () {
    File::put($this->providers, "<?php\n\nreturn [App\Shared\SharedServiceProvider::class];\n");

    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])->assertSuccessful();

    expect(File::get($this->providers))->toContain('ContextFixtureServiceProvider::class,')
        ->and(php_parses($this->providers))->toBeTrue();
});

test('it fails loudly when there is no provider list to register in', function () {
    // The one test that removes the file rather than adding to it, so it is
    // also the one that keeps a copy: everything else in the suite boots
    // through it, and the teardown only ever takes entries out.
    $original = File::get($this->providers);

    File::delete($this->providers);

    try {
        // Reporting success here leaves a context whose bindings, migrations
        // and routes are never loaded.
        $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])
            ->expectsOutputToContain('by hand')
            ->assertFailed();
    } finally {
        File::put($this->providers, $original);
    }
});

test('a context is not mistaken for one whose name ends with it', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'NestedContextFixture', 'prefix' => 'ncf'])->assertSuccessful();

    // NestedContextFixtureServiceProvider::class contains
    // ContextFixtureServiceProvider::class, so a substring check would call
    // this one already registered and never add it.
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])->assertSuccessful();

    expect(File::get($this->providers))
        ->toContain('App\ContextFixture\ContextFixtureServiceProvider::class,')
        ->and(php_parses($this->providers))->toBeTrue();
});

test('registering an already known provider does not duplicate it', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])->assertSuccessful();
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'ctx'])->assertSuccessful();

    expect(substr_count(File::get($this->providers), 'ContextFixtureServiceProvider::class'))->toBe(1);
});

test('the provider carries the code its tables will be stamped with', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => 'cse'])
        ->assertSuccessful();

    // Asked for, never derived. Which abbreviation reads back to a context is
    // a judgement: CompanyRegistry is filed under cse here, and no rule
    // reaches that.
    expect(File::get(app_path('ContextFixture/ContextFixtureServiceProvider.php')))
        ->toContain("public string \$tablePrefix = 'cse';");
});

test('it refuses a table prefix that would not read as a code', function (string $prefix) {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ContextFixture', 'prefix' => $prefix])
        ->expectsOutputToContain('must be two or three lowercase letters')
        ->assertFailed();

    // Nothing written, because the prefix reaches a table name and every
    // aggregate this context ever holds inherits it.
    expect(app_path('ContextFixture'))->not->toBeDirectory();
})->with([
    'one letter' => ['i'],
    'four letters' => ['iaaa'],
    'uppercase' => ['IAA'],
    'a digit' => ['ia2'],
    'not letters at all' => ['i-a'],
]);

test('a name that cannot exist is refused before its prefix is looked at', function () {
    // Both wrong at once. Answering about the prefix first sends somebody off
    // to fix that and come back for the refusal that was always going to land,
    // which is two runs for a name that was never going to work.
    $this->artisan('ldd:make:bounded-context', ['name' => 'Shared', 'prefix' => 'iaaa'])
        ->expectsOutputToContain('already exists as the application foundation layer')
        ->assertFailed();
});
