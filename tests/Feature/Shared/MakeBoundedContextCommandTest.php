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
    $this->providers = base_path('bootstrap/providers.php');
    $this->providersBackup = File::get($this->providers);

    foreach (['ScaffoldFixture', 'NestedScaffoldFixture'] as $fixture) {
        expect(app_path($fixture))->not->toBeDirectory(
            "app/{$fixture} already exists; refusing to run so the teardown cannot delete it."
        );
    }

    $this->createdFixture = true;
});

afterEach(function () {
    File::put($this->providers, $this->providersBackup);

    // Removed here rather than in the test body: an assertion that fails
    // leaves whatever the body had not reached yet, and a leaked context
    // makes every later run of the suite fail too.
    if ($this->createdFixture ?? false) {
        File::deleteDirectory(app_path('ScaffoldFixture'));
        File::deleteDirectory(app_path('NestedScaffoldFixture'));
    }
});

test('it scaffolds the context and registers its provider', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])
        ->assertSuccessful();

    expect(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php'))->toBeFile();

    expect(File::get($this->providers))
        ->toContain('use App\ScaffoldFixture\ScaffoldFixtureServiceProvider;')
        ->toContain('ScaffoldFixtureServiceProvider::class,');
});

test('it keeps the provider imports alphabetical', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();

    preg_match_all('/^use (.+);$/m', File::get($this->providers), $matches);

    $sorted = $matches[1];
    sort($sorted);

    expect($matches[1])->toBe($sorted);
});

test('it creates no layer directories', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();

    expect(app_path('ScaffoldFixture/Domain'))->not->toBeDirectory()
        ->and(app_path('ScaffoldFixture/Application'))->not->toBeDirectory()
        ->and(app_path('ScaffoldFixture/Infrastructure'))->not->toBeDirectory();
});

test('route files are opt in', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();

    expect(app_path('ScaffoldFixture/Shared/Infrastructure/Http/Routes/web.php'))->not->toBeFile();

    // The convention is documented as a comment, never as a live property
    // pointing at a file that does not exist.
    expect(File::get(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php')))
        ->toContain('// protected array $routes')
        ->not->toMatch('/^\s+protected array \$routes/m');
});

test('it declares the route files it generates', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture', '--web' => true, '--api' => true])
        ->assertSuccessful();

    expect(app_path('ScaffoldFixture/Shared/Infrastructure/Http/Routes/web.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Shared/Infrastructure/Http/Routes/api.php'))->toBeFile();

    expect(File::get(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php')))
        ->toContain("'web' => [__DIR__.'/Shared/Infrastructure/Http/Routes/web.php']")
        ->toContain("'api' => [__DIR__.'/Shared/Infrastructure/Http/Routes/api.php']");
});

test('re-running never rewrites the provider', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true])
        ->assertSuccessful();

    $provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');
    $before = File::get($provider);

    // Asking for route files on a context already in use is the natural way
    // to run this twice; rewriting the provider from the stub would drop
    // every binding and migration path it has accumulated.
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture', '--web' => true])
        ->assertSuccessful();

    expect(File::get($provider))->toBe($before);
});

test('re-running creates the missing route file and says how to declare it', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();

    // The file alone is never loaded: the provider has to declare it, and
    // the provider is the one thing this command will not touch.
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture', '--web' => true])
        ->expectsOutputToContain("'web' => [__DIR__.'/Shared/Infrastructure/Http/Routes/web.php'],")
        ->assertSuccessful();

    expect(app_path('ScaffoldFixture/Shared/Infrastructure/Http/Routes/web.php'))->toBeFile();
});

test('it normalises the context name', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'scaffold_fixture'])->assertSuccessful();

    expect(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php'))->toBeFile();
});

test('it registers the provider however the list is written', function () {
    File::put($this->providers, "<?php\n\nreturn [App\Shared\SharedServiceProvider::class];\n");

    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();

    expect(File::get($this->providers))->toContain('ScaffoldFixtureServiceProvider::class,')
        ->and(php_parses($this->providers))->toBeTrue();
});

test('it fails loudly when the provider list cannot be found', function () {
    File::put($this->providers, "<?php\n\nreturn array(App\Shared\SharedServiceProvider::class);\n");

    // Reporting success here leaves a context whose bindings, migrations and
    // routes are never loaded, and a stale import would make every later run
    // answer "already registered".
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertFailed();

    expect(File::get($this->providers))->not->toContain('ScaffoldFixture');
});

test('a provider already listed fully qualified is not added again', function () {
    // Laravel generates bootstrap/providers.php with no imports at all, so
    // this is the shape a fresh application actually ships.
    File::put($this->providers, "<?php\n\nreturn [\n    App\Shared\SharedServiceProvider::class,\n    App\ScaffoldFixture\ScaffoldFixtureServiceProvider::class,\n];\n");

    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();

    expect(substr_count(File::get($this->providers), 'ScaffoldFixtureServiceProvider::class'))->toBe(1);
});

test('a context is not mistaken for one whose name ends with it', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'NestedScaffoldFixture'])->assertSuccessful();

    // NestedScaffoldFixtureServiceProvider::class contains
    // ScaffoldFixtureServiceProvider::class, so a substring check would call
    // this one already registered and never add it.
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();

    expect(File::get($this->providers))
        ->toContain("\n    ScaffoldFixtureServiceProvider::class,")
        ->and(php_parses($this->providers))->toBeTrue();
});

test('registering an already known provider does not duplicate it', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();

    expect(substr_count(File::get($this->providers), 'ScaffoldFixtureServiceProvider::class'))->toBe(1);
});
