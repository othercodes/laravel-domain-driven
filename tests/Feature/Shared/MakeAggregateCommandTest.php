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
        '        WidgetRepository::class => EloquentWidgetRepository::class,',
        '            WidgetRepository::class   => EloquentWidgetRepository::class,',
        File::get($provider)
    ));

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])
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
        ->expectsOutputToContain("Route::get('/widgets/{id}', [WidgetController::class, 'show'])->name('widgets.show');")
        ->expectsOutputToContain("Route::prefix('api')->group(function () {")
        ->expectsOutputToContain("Route::get('/widgets/{id}', [WidgetController::class, 'show'])->name('api.widgets.show');")
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

    expect(substr_count(File::get($provider), '=> EloquentWidgetRepository::class'))->toBe(1);
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
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--all' => true])
        ->assertSuccessful();

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
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/WidgetFactory.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Seeders/WidgetSeeder.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Requests/StoreWidgetRequest.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Controllers/WidgetController.php'))->toBeFile()
        ->and(app_path('ScaffoldFixture/Widgets/Infrastructure/Http/Controllers/API/WidgetController.php'))->toBeFile()
        ->and(File::glob(app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Migrations/*_create_widgets_table.php')))
        ->toHaveCount(1);
});
