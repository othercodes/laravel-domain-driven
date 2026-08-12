<?php

use Illuminate\Support\Facades\File;

/*
 * The command writes into app/ and bootstrap/providers.php, so every test
 * restores both afterwards.
 */
beforeEach(function () {
    $this->providers = base_path('bootstrap/providers.php');
    $this->providersBackup = File::get($this->providers);
});

afterEach(function () {
    File::put($this->providers, $this->providersBackup);

    foreach (['ScaffoldFixture', 'Reporting'] as $context) {
        File::deleteDirectory(app_path($context));
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

test('it refuses to overwrite an existing context', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertFailed();
});

test('it normalises the context name', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'scaffoldfixture'])->assertSuccessful();

    expect(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php'))->toBeFile();
});

test('registering an already known provider does not duplicate it', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture', '--force' => true])->assertSuccessful();

    expect(substr_count(File::get($this->providers), 'ScaffoldFixtureServiceProvider::class'))->toBe(1);
});
