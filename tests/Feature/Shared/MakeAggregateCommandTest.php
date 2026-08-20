<?php

use Illuminate\Support\Facades\Artisan;
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

    foreach (['ScaffoldFixture', 'ScaffoldOther'] as $fixture) {
        expect(app_path($fixture))->not->toBeDirectory(
            "app/{$fixture} already exists; refusing to run so the teardown cannot delete it."
        );
    }

    // Lives outside app/, so it needs the same guard: the suite refuses to
    // run rather than risk deleting a page directory it did not create.
    $this->pages = base_path('resources/templates/tailwindcss/js/Pages/Widgets');

    expect($this->pages)->not->toBeDirectory(
        "{$this->pages} already exists; refusing to run so the teardown cannot delete it."
    );

    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture'])->assertSuccessful();
    $this->createdFixture = true;
});

afterEach(function () {
    File::put($this->providers, $this->providersBackup);

    if ($this->createdFixture ?? false) {
        File::deleteDirectory($this->pages);
    }

    // Both fixtures are removed here rather than in the test body: an
    // assertion that fails leaves whatever the body had not reached yet, and
    // a leaked context makes every later run of the suite fail too.
    if ($this->createdFixture ?? false) {
        File::deleteDirectory(app_path('ScaffoldFixture'));
        File::deleteDirectory(app_path('ScaffoldOther'));
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
        ->toContain('\App\ScaffoldFixture\Widgets\Domain\Contracts\WidgetRepository::class => \App\ScaffoldFixture\Widgets\Infrastructure\Persistence\EloquentWidgetRepository::class,');
});

/*
 * Two things answering to one short name in one file is the same fatal whether
 * they are two imports or an import and the class the file declares, and a
 * generated file reaches it from either side.
 *
 * What is asserted is the invariant itself, from outside: either the command
 * refuses, or everything it wrote compiles. An earlier version of this test
 * asserted that every name a stub imports is refused, which is a different and
 * wrong claim: an aggregate called Exception generates ExceptionException
 * under `use Exception`, and Schema appears only in the migration stub, which
 * declares an anonymous class. Both are fine, and a guard that refused them
 * would be answering a question nobody asked.
 */
test('for every name the stubs import, the command refuses or writes files that compile', function () {
    $imported = collect(File::glob(base_path('stubs/aggregate.*.stub')))
        ->flatMap(function (string $stub): array {
            // Not anchored at column zero alone: aggregate.seeder.stub opens
            // its import line with a placeholder, and that is exactly the one
            // name this sweep could not reach. Still not matching an indented
            // `use`, which inside a class body is a trait, not an import.
            preg_match_all('/(?:^|\}\})use ([^;\n]+);$/m', File::get($stub), $matches);

            return $matches[1];
        })
        ->reject(fn (string $import): bool => str_contains($import, '{{'))
        ->map(fn (string $import): string => class_basename($import))
        ->unique()
        ->values();

    expect($imported)->not->toBeEmpty();

    foreach ($imported as $name) {
        $this->artisan('ldd:make:aggregate', [
            'context' => 'ScaffoldFixture', 'name' => $name, '--all' => true,
        ])->run();

        foreach (File::allFiles(app_path('ScaffoldFixture')) as $file) {
            expect(php_parses($file->getPathname()))->toBeTrue(
                "ldd:make:aggregate ScaffoldFixture {$name} --all wrote {$file->getFilename()}, which does not compile."
            );
        }

        File::deleteDirectory(app_path('ScaffoldFixture/'.Str::plural($name)));
    }
});

/*
 * Eleven of the thirteen aggregate stubs declare a name derived from the
 * aggregate rather than the aggregate itself, and the collision follows the
 * derived name. Comparing the argument alone caught Model, whose stub declares
 * it as written, and waved these three through: that is the half-fixed shape
 * this branch exists to end.
 */
test('every table Shared creates is refused, not just the ones its files are named after', function () {
    // Derived from the same migrations the guard reads, because the guard used
    // to match filenames: create_jobs_table.php creates jobs, job_batches and
    // failed_jobs, and create_cache_table.php creates cache and cache_locks,
    // so matching the name saw two of those five and let the rest through.
    $tables = collect(File::glob(app_path('Shared/Infrastructure/Persistence/Migrations/*.php')))
        ->flatMap(function (string $migration): array {
            preg_match_all("/Schema::create\(\s*'([a-z_]+)'/", File::get($migration), $found);

            return $found[1];
        })
        ->unique()
        ->values();

    expect($tables)->toHaveCount(5);

    foreach ($tables as $table) {
        $this->artisan('ldd:make:aggregate', [
            'context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true, '--table' => $table,
        ])->assertFailed();
    }

    expect(app_path('ScaffoldFixture/Widgets'))->not->toBeDirectory();
});

test('a migration the parser cannot read is still owned by its name', function () {
    // Neither signal covers the other. The parser sees a literal
    // Schema::create and nothing else: not a table named by a constant, not
    // Schema::connection('tenant')->create(), and not a file mid-edit that
    // does not parse at all. Trading the filename for the content scan made
    // this guard catch strictly less than it did before, and the failure it
    // let through is a silent duplicate that only surfaces as a migrate abort
    // on a fresh database.
    $dir = app_path('ScaffoldFixture/Tenants/Infrastructure/Persistence/Migrations');

    File::ensureDirectoryExists($dir);
    File::put("{$dir}/2025_03_01_000000_create_tenants_table.php", <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::connection('tenant')->create('tenants', fn ($table) => null);
            }
        };
        PHP);

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true, '--table' => 'tenants'])
        ->expectsOutputToContain('already has a create migration')
        ->assertFailed();
});

test('a migration that drops a table does not own the one its down restores', function () {
    // A reversible drop restores its table in down(), and counting that made
    // the guard refuse an aggregate over a table nothing creates, name a drop
    // migration as the owner, and offer --table as the way out. Retiring an
    // aggregate and keeping the cleanup migration is ordinary, and the
    // create migration is gone by then, so the filename signal is quiet too.
    $dir = app_path('ScaffoldFixture/Ledgers/Infrastructure/Persistence/Migrations');

    File::ensureDirectoryExists($dir);
    File::put("{$dir}/2026_01_10_000000_drop_entries_table.php", <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::dropIfExists('entries');
            }

            public function down(): void
            {
                Schema::create('entries', fn ($table) => null);
            }
        };
        PHP);

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Entry', '--migration' => true])
        ->assertSuccessful();
});

test('a Schema::create left behind a comment owns nothing', function () {
    // The guard reads the parsed source, not the text. Reading the text is
    // what this codebase refuses everywhere else, and here it would refuse an
    // aggregate over a table no migration creates, sending the developer to
    // the --table escape hatch for a collision that is not there.
    $dir = app_path('ScaffoldFixture/Orders/Infrastructure/Persistence/Migrations');

    File::ensureDirectoryExists($dir);
    File::put("{$dir}/2025_02_01_000000_create_orders_table.php", <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('orders', function (Blueprint $table) {
                    $table->uuid('id')->primary();
                });

                // Schema::create('quotes', function (Blueprint $table) {
                //     $table->uuid('id')->primary();
                // });
            }
        };
        PHP);

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Quote', '--migration' => true])
        ->assertSuccessful();

    // And the live one in the same file still owns its table.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true, '--table' => 'orders'])
        ->assertFailed();
});

test('it refuses a table Shared already creates a migration for', function () {
    // The guard globbed app/*/*/.../Migrations, where aggregates keep theirs.
    // Shared keeps the framework's own one segment shallower, so cache, jobs,
    // failed_jobs and job_batches were invisible to it, and an aggregate
    // called Job put a second create_jobs_table beside Shared's. Two create
    // migrations for one table abort migrate on a fresh database.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Job', '--migration' => true])
        ->expectsOutputToContain('app/Shared/Infrastructure/Persistence/Migrations')
        ->assertFailed();

    expect(app_path('ScaffoldFixture/Jobs'))->not->toBeDirectory();
});

test('the snippets it prints name every class in full', function () {
    // Pasted without it, the seeder entry resolves inside Database\Seeders,
    // which composer maps to two directories that do not hold it, so db:seed
    // aborts. The route line resolves to a global controller instead, which
    // registers fine and 500s on the first request.
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget',
        '--seeder' => true, '--factory' => true, '--web' => true, '--api' => true,
    ]);

    // Nothing it prints asks for an import. Both targets have an import list
    // of their own: DatabaseSeeder is the one file every context adds to and
    // already imports a UserSeeder, and the shipped route file opens with four
    // controllers. A pasted import under a name either already holds is a
    // compile-time fatal, and bootRoutes() compiles route files on every boot,
    // so that one takes down every request and every artisan call.
    //
    // Both controllers in full, too: web and API share a short name and only
    // the full one tells them apart.
    expect(Artisan::output())
        ->toContain('\\App\\ScaffoldFixture\\Widgets\\Infrastructure\\Persistence\\Seeders\\WidgetSeeder::class,')
        ->toContain('[\\App\\ScaffoldFixture\\Widgets\\Infrastructure\\Http\\Controllers\\WidgetController::class,')
        ->toContain('[\\App\\ScaffoldFixture\\Widgets\\Infrastructure\\Http\\Controllers\\API\\WidgetController::class,')
        ->not->toContain('use App\\ScaffoldFixture\\Widgets');
});

test('it says when the route file exists but the provider does not declare it', function () {
    // The reminder was skipped precisely when the file existed, which is the
    // state that most needs saying: written by an earlier run, never declared,
    // loaded by nothing, and this hint printing routes into it.
    $file = app_path('ScaffoldFixture/Shared/Infrastructure/Http/Routes/web.php');

    File::ensureDirectoryExists(dirname($file));
    File::put($file, "<?php\n");

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true])
        ->expectsOutputToContain('does not declare')
        ->assertSuccessful();

    // And it goes on saying so once the route has been pasted in. Behind the
    // early return that skips the snippet, the reminder stopped the moment it
    // was acted on, which is the one state where the 404 is already waiting.
    File::append($file, "\nuse App\\ScaffoldFixture\\Widgets\\Infrastructure\\Http\\Controllers\\WidgetController;\n"
        ."Route::get('/widgets/{id}', [WidgetController::class, 'show'])->name('widgets.show');\n");

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true])
        ->expectsOutputToContain('does not declare')
        ->assertSuccessful();
});

test('it refuses an aggregate whose derived class name collides', function (string $name, array $flags) {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => $name] + $flags)
        ->expectsOutputToContain('would put two things under the name')
        ->assertFailed();

    expect(File::directories(app_path('ScaffoldFixture')))->toBeEmpty();
})->with([
    // {{ aggregate }}Resource lands on Illuminate's JsonResource.
    'Json' => ['Json', ['--api' => true]],
    // {{ aggregate }}Factory lands on the shared AggregateFactory.
    'Aggregate' => ['Aggregate', ['--factory' => true]],
    // {{ aggregate }}Controller lands on the shared InertiaController.
    'Inertia' => ['Inertia', ['--web' => true]],
]);

test('it refuses an aggregate named after an import the command adds itself', function () {
    // Not every import a generated file ends up with comes from its stub.
    // --factory adds HasFactory to the model afterwards, so a guard reading
    // only stubs/ waves `class HasFactory` through, under its own import.
    // Read back rather than asserted through doesntExpectOutputToContain,
    // which matches per write call and never sees what the components print.
    $this->withoutMockingConsoleOutput();

    $exit = $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'HasFactory', '--factory' => true]);

    expect($exit)->toBe(1)
        // No stub is blamed for it: --factory adds this import to the model,
        // so the stub the sweep happens to reach first never carries it, and
        // naming one sends the reader to a file that is not the problem, in
        // the one directory here meant to be edited.
        ->and(Artisan::output())
        ->toContain('under the name [HasFactory]')
        ->not->toContain('.stub');

    expect(File::directories(app_path('ScaffoldFixture')))->toBeEmpty();
});

test('the refusal names the stub the collision came from', function () {
    // Naming the wrong file is how somebody spends an afternoon editing a stub
    // that was never the problem.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Model'])
        ->expectsOutputToContain('aggregate.model.stub')
        ->assertFailed();
});

test('it binds a repository whose short name the provider already imports', function () {
    // Same fatal as the event handler, reached through the binding instead:
    // an aggregate called Widget wants a WidgetRepository, and nothing stops
    // the provider already importing one from somewhere else.
    $provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');

    File::put($provider, str_replace(
        'use ComplexHeart',
        "use App\Elsewhere\WidgetRepository;\nuse ComplexHeart",
        File::get($provider)
    ));

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])
        ->assertSuccessful();

    expect(php_parses($provider))->toBeTrue();
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

    // The routing lives in the base class, so the generated factory carries no
    // newModel() of its own to forget or delete.
    expect(File::get(app_path('ScaffoldFixture/Widgets/Domain/Factories/WidgetFactory.php')))
        ->toContain('class WidgetFactory extends AggregateFactory')
        ->not->toContain('newModel');

    // Laravel would not find a factory outside Database\Factories, so the
    // model has to point at it explicitly.
    expect(File::get(app_path('ScaffoldFixture/Widgets/Domain/Widget.php')))
        ->toContain('protected static function newFactory(): WidgetFactory');
});

test('it says what an older aggregate needs before a factory type checks', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])
        ->assertSuccessful();

    // The shape an aggregate generated before AggregateFactory existed has.
    $model = app_path('ScaffoldFixture/Widgets/Domain/Widget.php');

    File::put($model, str_replace(
        [
            "use App\Shared\Domain\BuildsFromAttributes;\n",
            'class Widget extends Model implements BuildsFromAttributes',
            'public static function new(array $attributes = []): static',
        ],
        [
            '',
            'class Widget extends Model',
            'public static function new(array $attributes = []): self',
        ],
        File::get($model)
    ));

    expect(File::get($model))->not->toContain('BuildsFromAttributes');

    // The generated factory binds its template to the contract, so without
    // this the aggregate gets a factory that does not pass static analysis
    // and nothing says which two lines are missing.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--factory' => true])
        ->expectsOutputToContain('implements BuildsFromAttributes')
        ->expectsOutputToContain('new() to return static')
        ->assertSuccessful();
});

test('the aggregate declares the contract its factory builds through', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])
        ->assertSuccessful();

    // AggregateFactory calls new() on whatever model it is given, so a model
    // without one is a mistake worth catching before it reaches a seeder.
    expect(File::get(app_path('ScaffoldFixture/Widgets/Domain/Widget.php')))
        ->toContain('class Widget extends Model implements BuildsFromAttributes')
        ->toContain('public static function new(array $attributes = []): static');
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

    // An Inertia page publishes the same way an API does, so the resource is
    // not an --api concern.
    expect(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Resources/WidgetResource.php'))->toBeFile();
});

test('the controllers publish through a resource, not the model', function () {
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true, '--api' => true,
    ])->assertSuccessful();

    $resource = app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Resources/WidgetResource.php');

    // Closed to begin with: an attribute is published because somebody listed
    // it, not because it happens to sit on the model.
    expect(File::get($resource))
        ->toContain('class WidgetResource extends JsonResource')
        ->toContain("'id' => \$this->id,")
        ->and(php_parses($resource))->toBeTrue();

    expect(File::get(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Controllers/API/WidgetController.php')))
        ->toContain('return new WidgetResource(');

    // resolve() rather than the resource itself: Inertia resolves an Arrayable
    // by calling toArray(), which skips the filtering when() depends on and
    // leaves a MissingValue object in the props.
    expect(File::get(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Controllers/WidgetController.php')))
        ->toContain('(new WidgetResource($widget))->resolve($request)');
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
});

test('it refuses a table name that is not a valid identifier', function () {
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true, '--table' => "odd'name",
    ])->assertFailed();

    // The name reaches both the model's $table property and Schema::create(),
    // so an unchecked quote ships two files that do not parse.
    expect(app_path('ScaffoldFixture/Widgets'))->not->toBeDirectory();
});

test('it does not bind twice when the provider was reformatted', function () {
    $provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])->assertSuccessful();

    File::put($provider, str_replace(
        '        \App\ScaffoldFixture\Widgets\Domain\Contracts\WidgetRepository::class => \App\ScaffoldFixture\Widgets\Infrastructure\Persistence\EloquentWidgetRepository::class,',
        '            \App\ScaffoldFixture\Widgets\Domain\Contracts\WidgetRepository::class   => \App\ScaffoldFixture\Widgets\Infrastructure\Persistence\EloquentWidgetRepository::class,',
        File::get($provider)
    ));

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])
        ->assertSuccessful();

    // EloquentWidgetRepository::class contains WidgetRepository::class, so
    // count the mapping rather than the bare class reference.
    expect(substr_count(File::get($provider), '=> \App\ScaffoldFixture\Widgets\Infrastructure\Persistence\EloquentWidgetRepository::class'))->toBe(1);
});

test('it refuses to scaffold into the Shared foundation layer', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Shared', 'name' => 'Widget'])
        ->assertFailed();

    expect(app_path('Shared/Widgets'))->not->toBeDirectory();
});

test('it reuses the migration filename instead of duplicating it', function () {
    $args = ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true];

    $this->artisan('ldd:make:aggregate', $args)->assertSuccessful();

    $migration = File::glob(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Migrations/*_create_widgets_table.php'))[0];
    File::put($migration, str_replace(
        '$table->timestamps();',
        "\$table->string('reference')->unique();\n            \$table->timestamps();",
        File::get($migration)
    ));

    $this->artisan('ldd:make:aggregate', $args)->assertSuccessful();

    // Two create-table migrations would abort `migrate` on a fresh database,
    // and rewriting the one that is there loses schema that may already have
    // been applied: Laravel records it as run by filename, so it never replays.
    expect(File::glob(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Migrations/*_create_widgets_table.php')))
        ->toHaveCount(1)
        ->and(File::get($migration))->toContain("\$table->string('reference')->unique();");
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
        ->toContain('\App\ScaffoldFixture\Widgets\Domain\Contracts\WidgetRepository::class => \App\ScaffoldFixture\Widgets\Infrastructure\Persistence\EloquentWidgetRepository::class,');
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

test('the generated model has a place to hide attributes', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--api' => true])
        ->assertSuccessful();

    // The API controller returns the model straight into a JsonResponse, so
    // whatever reaches $fillable is published unless $hidden says otherwise.
    // Anchored to a real property, not a mention in a comment.
    expect(File::get(app_path('ScaffoldFixture/Widgets/Domain/Widget.php')))
        ->toMatch('/^    protected \$hidden = \[/m');
});

test('it says that nothing publishes the generated event', function () {
    // The aggregate records the event; only a use case publishes it. Without
    // one the events pile up on the instance and vanish with it, which is the
    // silent failure these commands exist to prevent.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--events' => true])
        ->expectsOutputToContain('Nothing publishes WidgetCreated.')
        ->expectsOutputToContain('publishDomainEvents($this->eventBus)')
        ->assertSuccessful();
});

test('it stays quiet when the application layer already publishes', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--events' => true])
        ->assertSuccessful();

    $application = app_path('ScaffoldFixture/Widgets/Application');
    File::ensureDirectoryExists($application);
    // A real call, not a mention of one: the command reads this file rather
    // than searching it, so a commented line would not count and should not.
    File::put("{$application}/CreateWidget.php", "<?php\n\n\$widget->publishDomainEvents(\$this->eventBus);\n");

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--events' => true])
        ->doesntExpectOutputToContain('Nothing publishes WidgetCreated.')
        ->assertSuccessful();

    // One it cannot read may be the use case that publishes, and both commands
    // ask this through one helper so they cannot answer it differently.
    File::put("{$application}/CreateWidget.php", "<?php\n\nclass CreateWidget {\n");

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--events' => true])
        ->expectsOutputToContain('Could not tell whether anything publishes')
        ->doesntExpectOutputToContain('Nothing publishes WidgetCreated.')
        ->assertSuccessful();

    $this->artisan('ldd:make:use-case', ['context' => 'ScaffoldFixture', 'aggregate' => 'Widget', 'name' => 'UpdateWidget'])
        ->expectsOutputToContain('Could not tell whether anything publishes')
        ->doesntExpectOutputToContain('records domain events')
        ->assertSuccessful();
});

test('it does not re-advise a route the file already declares', function () {
    $args = ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true];

    $this->artisan('ldd:make:aggregate', $args)->assertSuccessful();

    $routes = app_path('ScaffoldFixture/Shared/Infrastructure/Http/Routes');
    File::ensureDirectoryExists($routes);
    // Imported, the way a route file that actually loads has to be: without
    // the use statement WidgetController::class means the global one, and
    // the command is right to say the controller is not wired here.
    File::put("{$routes}/web.php", implode("\n", [
        '<?php',
        '',
        'use App\ScaffoldFixture\Widgets\Infrastructure\Http\Controllers\WidgetController;',
        '',
        "Route::middleware('auth')->get('/widgets/{id}', [WidgetController::class, 'show'])->name('widgets.show');",
        '',
    ]));

    // Pasting the canonical route back replaces the customised one, since
    // RouteCollection keys by method and URI, and the auth middleware is
    // gone with no error anywhere.
    $this->artisan('ldd:make:aggregate', $args)
        ->doesntExpectOutputToContain("->name('widgets.show')")
        ->assertSuccessful();
});

test('it does not advise creating a page that already exists', function () {
    File::ensureDirectoryExists($this->pages);
    File::put("{$this->pages}/Show.vue", '<template><div /></template>');

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true])
        ->doesntExpectOutputToContain('Create the page at')
        ->assertSuccessful();
});

test('re-running with the same flags advises nothing', function () {
    $args = ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--events' => true, '--factory' => true];

    $this->artisan('ldd:make:aggregate', $args)->assertSuccessful();

    // The model already carries both. Repeating the advice would redeclare
    // newFactory(), which is fatal, and register the event a second time.
    $this->artisan('ldd:make:aggregate', $args)
        ->doesntExpectOutputToContain('Add to it by hand')
        ->assertSuccessful();
});

test('it refuses a migration for a table the model does not declare', function () {
    $args = ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true];

    $this->artisan('ldd:make:aggregate', $args)->assertSuccessful();

    // The model keeps pointing at widgets, so this migration would create a
    // second table that nothing reads.
    $this->artisan('ldd:make:aggregate', $args + ['--table' => 'renamed'])->assertFailed();

    expect(File::glob(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Migrations/*.php')))
        ->toHaveCount(1);
});

/*
 * Every question this command asks of an existing model answers false when it
 * does not parse, so unreadable would otherwise read as "declares no table",
 * which is exactly what the guard above takes as permission to proceed.
 */
test('it refuses to reason about a model it cannot read', function (string $contents, string $reason) {
    $args = ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true];

    $this->artisan('ldd:make:aggregate', $args)->assertSuccessful();

    File::put(app_path('ScaffoldFixture/Widgets/Domain/Widget.php'), $contents);

    $this->artisan('ldd:make:aggregate', $args + ['--table' => 'renamed'])
        ->expectsOutputToContain($reason)
        ->assertFailed();

    // The mismatched migration an unread model would have waved through: a
    // second create table for an aggregate that already has one.
    expect(File::glob(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Migrations/*.php')))
        ->toHaveCount(1);
})->with([
    // Answers false to every question because it never parsed.
    'unparseable' => ["<?php\n\nclass Widget extends Model {\n", 'does not parse'],
    // Parses perfectly well, and holds no aggregate to answer for.
    'empty' => ["<?php\n", 'declares no class Widget'],
    // Parses, holds a class, but not the one being reasoned about.
    'another class' => ["<?php\n\nclass Gadget {}\n", 'declares no class Widget'],
]);

test('it says a DatabaseSeeder it cannot read is unread, rather than advising a duplicate', function () {
    $seeder = app_path('Shared/Infrastructure/Persistence/Seeders/DatabaseSeeder.php');
    $backup = File::get($seeder);

    File::put($seeder, "<?php\n\nnamespace Database\Seeders;\n\nclass DatabaseSeeder {\n");

    try {
        $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--seeder' => true])
            ->expectsOutputToContain('does not parse')
            ->doesntExpectOutputToContain('WidgetSeeder::class,')
            ->assertSuccessful();
    } finally {
        File::put($seeder, $backup);
    }
});

test('re-running adds the missing piece without touching the model', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])->assertSuccessful();

    $model = app_path('ScaffoldFixture/Widgets/Domain/Widget.php');
    File::put($model, str_replace("        //\n    ];", "        'reference',\n    ];", File::get($model)));

    // Adding an option you skipped is the reason to run this twice, and the
    // model is where hand-written work accumulates.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--events' => true])
        ->expectsOutputToContain('Widget was left as it is. Add to it by hand:')
        ->assertSuccessful();

    expect(app_path('ScaffoldFixture/Widgets/Domain/Events/WidgetCreated.php'))->toBeFile()
        ->and(File::get($model))->toContain("'reference',")
        ->and(File::get($model))->not->toContain('registerDomainEvent');
});

test('it hints a distinct route for the api controller', function () {
    // Sharing a URI does not add a second route: RouteCollection keys by
    // method and URI, so the api route would replace the web one and take
    // its name with it. Sharing a name resolves route() to whichever came
    // first. Both have to differ.
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true, '--api' => true,
    ])
        // One expectation per emitted line: each is consumed as it matches,
        // so URI and name go in the same assertion rather than two. The api
        // URI is kept apart by the prefix group, the convention the rest of
        // the application already follows.
        ->expectsOutputToContain("Route::get('/widgets/{id}', [\\App\\ScaffoldFixture\\Widgets\\Infrastructure\\Http\\Controllers\\WidgetController::class, 'show'])->name('widgets.show');")
        // Guarded. This assertion pinned the guardless form, so every
        // scaffolded aggregate shipped a public read endpoint and the suite
        // called it correct.
        ->expectsOutputToContain("Route::prefix('api')->middleware('auth:sanctum')->group(function () {")
        ->expectsOutputToContain("Route::get('/widgets/{id}', [\\App\\ScaffoldFixture\\Widgets\\Infrastructure\\Http\\Controllers\\API\\WidgetController::class, 'show'])->name('api.widgets.show');")
        ->assertSuccessful();
});

test('it does not register the migration path twice', function () {
    $args = ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true];

    $this->artisan('ldd:make:aggregate', $args)->assertSuccessful();
    $this->artisan('ldd:make:aggregate', $args)->assertSuccessful();

    // A second entry would load the same directory twice.
    expect(substr_count(
        File::get(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php')),
        "__DIR__.'/Widgets/Infrastructure/Persistence/Migrations'"
    ))->toBe(1);
});

test('it recognises a migration path however the provider is formatted', function () {
    $args = ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true];
    $provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');

    $this->artisan('ldd:make:aggregate', $args)->assertSuccessful();

    // The entry is read from the declaration, not matched as a formatted line,
    // so re-indenting it no longer hides it and earn a duplicate.
    File::put($provider, str_replace(
        "        __DIR__.'/Widgets/Infrastructure/Persistence/Migrations',",
        "            __DIR__ . '/Widgets/Infrastructure/Persistence/Migrations',",
        File::get($provider)
    ));

    $this->artisan('ldd:make:aggregate', $args)->assertSuccessful();

    expect(substr_count(File::get($provider), "/Widgets/Infrastructure/Persistence/Migrations'"))->toBe(1);
});

test('it refuses to wire into a provider that does not parse', function () {
    $provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])->assertSuccessful();

    File::put($provider, str_replace(
        'class ScaffoldFixtureServiceProvider',
        'class ScaffoldFixtureServiceProvider(',
        File::get($provider)
    ));

    // Reading it as empty would bind the repository a second time.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])
        ->assertFailed();

    expect(substr_count(File::get($provider), '=> \App\ScaffoldFixture\Widgets\Infrastructure\Persistence\EloquentWidgetRepository::class'))->toBe(1);
});

test('it fails when the context provider has nothing to wire', function () {
    $provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');

    File::put($provider, "<?php\n\nnamespace App\ScaffoldFixture;\n\nuse ComplexHeart\Infrastructure\Laravel\BoundedContextServiceProvider;\n\nclass ScaffoldFixtureServiceProvider extends BoundedContextServiceProvider\n{\n}\n");

    // Reporting success would leave an aggregate whose repository contract
    // resolves to nothing, which is the whole point of the wiring step.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])
        ->assertFailed();

    // A provider it could not wire must be left exactly as it was, not
    // carrying imports for a binding that never landed.
    expect(File::get($provider))->not->toContain('WidgetRepository');
});

test('it warns when the route file the hint points at does not exist', function () {
    // The fixture context was created without --web, so following the hint
    // literally yields a route file nothing loads and a silent 404.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true])
        ->expectsOutputToContain('That file does not exist yet')
        ->assertSuccessful();
});

test('everything it generates parses', function () {
    // The delegated flags are named here too: --all does not cover them, and
    // the provider they edit is the file most likely to end up unparseable.
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture',
        'name' => 'Widget',
        '--all' => true,
        '--mail' => ['WidgetShipped'],
        '--job' => ['RebuildWidgetIndex'],
        '--notification' => ['WidgetReady'],
        '--command' => ['SyncWidgets'],
    ])->assertSuccessful();

    // The fixture is deleted in teardown, so CI's Pint, PHPStan and arch legs
    // never see the generated code. Without this, a typo in any stub ships
    // green: every other test asserts on substrings and file existence.
    $files = File::allFiles(app_path('ScaffoldFixture'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(php_parses($file->getPathname()))
            ->toBeTrue("{$file->getRelativePathname()} does not parse");
    }
});

/*
 * The delegated flags hand the writing to Laravel's own generators and keep
 * only the placement. What is worth asserting is the placement, since passing
 * anything less than the full class name puts the file in app/Mail instead.
 */
test('the delegated flags place Laravel generators inside the aggregate', function () {
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture',
        'name' => 'Widget',
        '--mail' => ['WidgetShipped'],
        '--job' => ['RebuildWidgetIndex'],
        '--notification' => ['WidgetReady'],
        '--command' => ['SyncWidgets'],
    ])->assertSuccessful();

    expect(app_path('ScaffoldFixture/Widgets/Application/Mail/WidgetShipped.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Application/Jobs/RebuildWidgetIndex.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Application/Notifications/WidgetReady.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Console/Commands/SyncWidgets.php'))->toBeFile();

    // Nothing landed in the namespace the generators default to.
    expect(app_path('Mail/ScaffoldFixture'))->not->toBeDirectory()
        ->and(File::get(app_path('ScaffoldFixture/Widgets/Application/Mail/WidgetShipped.php')))
        ->toContain('namespace App\ScaffoldFixture\Widgets\Application\Mail;');
});

/*
 * Laravel only autodiscovers app/Console/Commands, so a console command
 * anywhere else exists as a file and as nothing else until the provider says
 * so. That is the failure this flag is really for.
 */
test('a delegated console command is declared in the provider', function () {
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--command' => ['SyncWidgets'],
    ])->assertSuccessful();

    // Declared by its full name and deliberately not imported, so that a
    // command sharing a short name with anything else here cannot collide.
    expect(File::get(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php')))
        ->toContain('\App\ScaffoldFixture\Widgets\Infrastructure\Console\Commands\SyncWidgets::class,')
        ->not->toContain('use App\ScaffoldFixture\Widgets\Infrastructure\Console\Commands\SyncWidgets;');
});

test('it declares a console command an earlier run left unwired', function () {
    $provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');

    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--command' => ['SyncWidgets'],
    ])->assertSuccessful();

    // The file stays, the wiring goes: the command has to register what is on
    // disk, not only what this run happened to write.
    File::put($provider, str_replace(
        '        \App\ScaffoldFixture\Widgets\Infrastructure\Console\Commands\SyncWidgets::class,'."\n",
        '',
        File::get($provider)
    ));

    // The revert is the whole premise, so it is asserted rather than assumed:
    // a str_replace that matched nothing would make this test vacuous.
    expect(File::get($provider))->not->toContain('SyncWidgets');

    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--command' => ['SyncWidgets'],
    ])->assertSuccessful();

    expect(File::get($provider))->toContain('SyncWidgets::class,');
});

test('it does not declare a console command twice', function () {
    foreach ([1, 2] as $run) {
        $this->artisan('ldd:make:aggregate', [
            'context' => 'ScaffoldFixture', 'name' => 'Widget', '--command' => ['SyncWidgets'],
        ])->assertSuccessful();
    }

    expect(substr_count(File::get(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php')), 'SyncWidgets::class,'))
        ->toBe(1);
});

test('it does not declare a repeated console command flag twice', function () {
    // Repeating the flag is the case the re-run guard cannot see: it reads the
    // provider as it was before this run, not what this run is adding.
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--command' => ['SyncWidgets', 'SyncWidgets'],
    ])->assertSuccessful();

    expect(substr_count(File::get(app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php')), 'SyncWidgets::class,'))
        ->toBe(1);
});

test('it fails when a delegated name is refused', function () {
    // Case is a PHP reserved word, so no mailable is written. The command has
    // to say so in the exit code: a script chaining on it would otherwise
    // carry on as though the mailable were there.
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--mail' => ['Case'],
    ])->assertFailed();

    // Failure is about what is missing, not a rollback: the aggregate it did
    // manage to write stays, so re-running fills in the rest.
    expect(app_path('ScaffoldFixture/Widgets/Domain/Widget.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Application/Mail'))->not->toBeDirectory();
});

/*
 * A console command's name is free text, so nothing stops it colliding with
 * another aggregate's or with something the provider already imports. Two use
 * statements resolving to one short name is fatal, and it takes down the whole
 * application rather than just this context.
 */
test('console commands sharing a short name do not collide in the provider', function () {
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--command' => ['SyncThings'],
    ])->assertSuccessful();

    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Gadget', '--command' => ['SyncThings'],
    ])->assertSuccessful();

    // Named after the repository contract the command imports for itself.
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--command' => ['WidgetRepository'],
    ])->assertSuccessful();

    $provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');

    expect(php_parses($provider))->toBeTrue('the provider does not parse')
        ->and(substr_count(File::get($provider), 'SyncThings::class,'))->toBe(2);
});

/*
 * The seeder is the one generated file whose body depends on another flag,
 * and the only hint pointing at a file outside the context.
 */
test('the seeder flag generates a seeder and says to register it', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--seeder' => true])
        ->expectsOutputToContain('WidgetSeeder::class,')
        ->assertSuccessful();

    // Without --factory the call is commented out: Widget::factory() would be
    // fatal the first time somebody ran db:seed.
    expect(File::get(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Seeders/WidgetSeeder.php')))
        ->toContain('// Widget::factory()->count(10)->create();')
        ->not->toContain("\n        Widget::factory()");
});

test('the seeder calls the factory when one was generated', function () {
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--seeder' => true, '--factory' => true,
    ])->assertSuccessful();

    expect(File::get(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Seeders/WidgetSeeder.php')))
        ->toContain('use App\ScaffoldFixture\Widgets\Domain\Widget;')
        ->toContain('Widget::factory()->count(10)->create();');
});

test('it does not re-advise a seeder DatabaseSeeder already lists', function () {
    $databaseSeeder = app_path('Shared/Infrastructure/Persistence/Seeders/DatabaseSeeder.php');
    $backup = File::get($databaseSeeder);

    File::put($databaseSeeder, str_replace(
        'use Illuminate\Database\Seeder;',
        "use App\ScaffoldFixture\Widgets\Infrastructure\Persistence\Seeders\WidgetSeeder;\nuse Illuminate\Database\Seeder;",
        str_replace('private array $seeders = [];', 'private array $seeders = [WidgetSeeder::class];', $backup)
    ));

    try {
        $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--seeder' => true])
            ->doesntExpectOutputToContain('WidgetSeeder::class,')
            ->assertSuccessful();
    } finally {
        File::put($databaseSeeder, $backup);
    }
});

test('all generates every optional piece', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--all' => true])
        ->assertSuccessful();

    expect(app_path('ScaffoldFixture/Widgets/Domain/Events/WidgetCreated.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Domain/Factories/WidgetFactory.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Seeders/WidgetSeeder.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Requests/StoreWidgetRequest.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Controllers/WidgetController.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Controllers/API/WidgetController.php'))->toBeFile()
        ->and(File::glob(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Migrations/*_create_widgets_table.php')))
        ->toHaveCount(1);
});
