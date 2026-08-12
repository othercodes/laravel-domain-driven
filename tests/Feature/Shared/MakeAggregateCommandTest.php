<?php

use Illuminate\Support\Facades\File;

/*
 * The command writes into app/ and edits the context provider, so every test
 * starts from a freshly generated context and removes it afterwards.
 *
 * The teardown only ever deletes a directory this file created: in a starter
 * kit these tests run inside somebody else's application, and a fixture name
 * that happened to collide would otherwise take their context with it.
 */
beforeEach(function () {
    $this->providers = base_path('bootstrap/providers.php');
    $this->providersBackup = File::get($this->providers);

    expect(app_path('ScaffoldFixture'))->not->toBeDirectory(
        'app/ScaffoldFixture already exists; refusing to run so the teardown cannot delete it.'
    );

    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();
    $this->createdFixture = true;
});

afterEach(function () {
    File::put($this->providers, $this->providersBackup);

    if ($this->createdFixture ?? false) {
        File::deleteDirectory(app_path('ScaffoldFixture'));
    }
});

test('it generates only the core by default', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])
        ->assertSuccessful();

    expect(app_path('ScaffoldFixture/Widgets/Domain/Widget.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Domain/Contracts/WidgetRepository.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Domain/Exceptions/WidgetNotFound.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/EloquentWidgetRepository.php'))->toBeFile();

    expect(app_path('ScaffoldFixture/Widgets/Domain/Events'))->not->toBeDirectory()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Migrations'))->not->toBeDirectory()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Http'))->not->toBeDirectory();
});

test('it binds the repository in the context provider', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])
        ->assertSuccessful();

    expect(File::get(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php')))
        ->toContain('use App\ScaffoldFixture\Widgets\Domain\Contracts\WidgetRepository;')
        ->toContain('use App\ScaffoldFixture\Widgets\Infrastructure\Persistence\EloquentWidgetRepository;')
        ->toContain('WidgetRepository::class => EloquentWidgetRepository::class,');
});

test('it registers the migration path only when a migration is generated', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])->assertSuccessful();

    expect(File::get(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php')))
        ->not->toContain('Widgets/Infrastructure/Persistence/Migrations');

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Gadget', '--migration' => true])
        ->assertSuccessful();

    expect(File::get(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php')))
        ->toContain("__DIR__.'/Gadgets/Infrastructure/Persistence/Migrations',");
});

test('the aggregate records its creation only with the events flag', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])->assertSuccessful();
    expect(File::get(app_path('ScaffoldFixture/Widgets/Domain/Widget.php')))
        ->not->toContain('registerDomainEvent');

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Gadget', '--events' => true])
        ->assertSuccessful();

    expect(app_path('ScaffoldFixture/Gadgets/Domain/Events/GadgetCreated.php'))->toBeFile();
    expect(File::get(app_path('ScaffoldFixture/Gadgets/Domain/Gadget.php')))
        ->toContain('registerDomainEvent(GadgetCreated::new($gadget->id))');
});

test('the factory is routed through the aggregate factory method', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--factory' => true])
        ->assertSuccessful();

    expect(File::get(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/WidgetFactory.php')))
        ->toContain('return Widget::new($attributes);');

    // Laravel would not find a factory outside Database\Factories, so the
    // model has to point at it explicitly.
    expect(File::get(app_path('ScaffoldFixture/Widgets/Domain/Widget.php')))
        ->toContain('protected static function newFactory(): WidgetFactory');
});

test('the web flag generates an Inertia controller', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true])
        ->assertSuccessful();

    // Rendering goes through the shared base, not Inertia::render directly,
    // so the page passes through its rendering callbacks.
    expect(File::get(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Controllers/WidgetController.php')))
        ->toContain('extends InertiaController')
        ->toContain("\$this->render(\$request, 'Widgets/Show'");

    expect(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Controllers/API/WidgetController.php'))
        ->not->toBeFile();
});

test('generated controllers do not ship an unbounded read', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true, '--api' => true])
        ->assertSuccessful();

    foreach (['Controllers/WidgetController.php', 'Controllers/API/WidgetController.php'] as $controller) {
        expect(File::get(app_path("ScaffoldFixture/Widgets/Infrastructure/Http/{$controller}")))
            ->not->toContain('match([])');
    }
});

test('it refuses names that are not valid PHP identifiers', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => '2024Report'])
        ->assertFailed();

    expect(app_path('ScaffoldFixture/2024Reports'))->not->toBeDirectory();

    // The provider must be untouched: a mangled binding there takes the whole
    // application down, not just the generated aggregate.
    expect(File::get(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php')))
        ->not->toContain('2024');
});

test('it refuses PHP reserved words as aggregate names', function () {
    foreach (['Case', 'Match', 'List'] as $reserved) {
        $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => $reserved])
            ->assertFailed();
    }

    expect(app_path('ScaffoldFixture/Cases'))->not->toBeDirectory();
});

test('it takes the aggregate name as written', function () {
    // Singularising unconditionally turns Analysis into Analysi.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Analysis'])
        ->assertSuccessful();

    expect(app_path('ScaffoldFixture/Analyses/Domain/Analysis.php'))->toBeFile();
});

test('it wires a provider whose array holds only a comment', function () {
    $provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');

    File::put($provider, str_replace(
        'public array $bindings = [];',
        'public array $bindings = [/* add bindings here */];',
        File::get($provider)
    ));

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])
        ->assertSuccessful();

    // A comment is not an element: emitting it as one produces `[,` and a
    // provider that does not parse, while the command reports success.
    expect(php_parses($provider))->toBeTrue();
});

test('it does not duplicate imports when the provider has several groups', function () {
    $provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');

    File::put($provider, str_replace(
        'use ComplexHeart\Infrastructure\Laravel\BoundedContextServiceProvider;',
        "use ComplexHeart\Infrastructure\Laravel\BoundedContextServiceProvider;\n\n// the developer's own group\nuse Illuminate\Support\Str;",
        File::get($provider)
    ));

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])
        ->assertSuccessful();

    expect(substr_count(File::get($provider), 'use Illuminate\Support\Str;'))->toBe(1)
        ->and(php_parses($provider))->toBeTrue();
});

test('it refuses a table another context already creates', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true])
        ->assertSuccessful();

    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldOther'])->assertSuccessful();

    // Both would Schema::create('widgets') and abort migrate on a fresh database.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldOther', 'name' => 'Widget', '--migration' => true])
        ->assertFailed();

    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldOther', 'name' => 'Widget', '--migration' => true, '--table' => 'other_widgets',
    ])->assertSuccessful();

    File::deleteDirectory(app_path('ScaffoldOther'));
});

test('it does not bind twice when the provider was reformatted', function () {
    $provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])->assertSuccessful();

    File::put($provider, str_replace(
        '        WidgetRepository::class => EloquentWidgetRepository::class,',
        '            WidgetRepository::class   => EloquentWidgetRepository::class,',
        File::get($provider)
    ));

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--force' => true])
        ->assertSuccessful();

    // EloquentWidgetRepository::class contains WidgetRepository::class, so
    // count the mapping rather than the bare class reference.
    expect(substr_count(File::get($provider), '=> EloquentWidgetRepository::class'))->toBe(1);
});

test('it refuses to scaffold into the Shared foundation layer', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Shared', 'name' => 'Widget'])
        ->assertFailed();

    expect(app_path('Shared/Widgets'))->not->toBeDirectory();
});

test('it reuses the migration filename instead of duplicating it', function () {
    $args = ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true];

    $this->artisan('ldd:make:aggregate', $args)->assertSuccessful();
    $this->artisan('ldd:make:aggregate', $args + ['--force' => true])->assertSuccessful();

    // Two create-table migrations would abort `migrate` on a fresh database.
    expect(File::glob(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Migrations/*_create_widgets_table.php')))
        ->toHaveCount(1);
});

test('it wires a provider whose arrays are declared inline', function () {
    $provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');

    File::put($provider, str_replace(
        'public array $bindings = [];',
        'public array $bindings = [FooRepository::class => EloquentFooRepository::class];',
        File::get($provider)
    ));

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])
        ->assertSuccessful();

    expect(File::get($provider))
        ->toContain('FooRepository::class => EloquentFooRepository::class,')
        ->toContain('WidgetRepository::class => EloquentWidgetRepository::class,');
});

test('it pluralises the aggregate directory and studlies the class', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'widget'])
        ->assertSuccessful();

    expect(app_path('ScaffoldFixture/Widgets/Domain/Widget.php'))->toBeFile();
});

test('it refuses an unknown bounded context', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Nope', 'name' => 'Widget'])
        ->assertFailed();
});

test('it refuses to overwrite an existing aggregate', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])->assertSuccessful();
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])->assertFailed();
});

test('all generates every optional piece', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--all' => true])
        ->assertSuccessful();

    expect(app_path('ScaffoldFixture/Widgets/Domain/Events/WidgetCreated.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/WidgetFactory.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Requests/StoreWidgetRequest.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Controllers/WidgetController.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Controllers/API/WidgetController.php'))->toBeFile()
        ->and(File::glob(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Migrations/*_create_widgets_table.php')))
        ->toHaveCount(1);
});
