<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
 * The command writes into app/ and nowhere else, so every test starts from a
 * freshly generated context and removes it afterwards.
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
    $this->provider = app_path('ScaffoldFixture/ScaffoldFixtureServiceProvider.php');
    $this->createdFixture = true;

    // Read after the fixture context is created: ldd:make:bounded-context is
    // the one command that registers anything, and it rewrites this file.
    $this->providersAfterFixture = File::get($this->providers);
});

afterEach(function () {
    File::put($this->providers, $this->providersBackup);

    // Both fixtures are removed here rather than in the test body: an
    // assertion that fails leaves whatever the body had not reached yet, and
    // a leaked context makes every later run of the suite fail too.
    if ($this->createdFixture ?? false) {
        File::deleteDirectory($this->pages);
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

test('it never touches the context provider', function () {
    // The invariant the whole design rests on. A provider is loaded from
    // bootstrap/providers.php, so a bad edit took the application and artisan
    // down together, from a command that had just printed `wired` in green.
    // Nothing this command does can reach that file any more, whatever it is
    // asked to generate.
    $before = File::get($this->provider);

    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture',
        'name' => 'Widget',
        '--all' => true,
        '--command' => ['SyncWidgets'],
    ])->assertSuccessful();

    expect(File::get($this->provider))->toBe($before)
        ->and(File::get($this->providers))->toBe($this->providersAfterFixture);
});

test('a name that collides with its own stub breaks only its own file', function (string $name, array $flags) {
    // These used to be refused, by a guard that rendered every stub and
    // compared short names, and that guard cost six review rounds and never
    // ran out of cases it did not cover. What made refusing feel necessary was
    // the provider edit: a class that does not compile, wired into a file
    // loaded on boot, takes down artisan itself.
    //
    // Nothing is wired now, so this is exactly what `make:model Model` leaves
    // behind in Laravel: one file in one directory that does not compile,
    // loaded by nothing, deleted by hand.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => $name] + $flags)
        ->assertSuccessful();

    expect(php_parses($this->provider))->toBeTrue('the context provider does not parse')
        ->and(php_parses($this->providers))->toBeTrue('bootstrap/providers.php does not parse');
})->with([
    // {{ aggregate }}Resource lands on Illuminate's JsonResource.
    'Json' => ['Json', ['--api' => true]],
    // {{ aggregate }}Factory lands on the shared AggregateFactory.
    'Aggregate' => ['Aggregate', ['--factory' => true]],
    // {{ aggregate }}Controller lands on the shared InertiaController.
    'Inertia' => ['Inertia', ['--web' => true]],
    // Added to the model after the stub is rendered, not by the stub.
    'HasFactory' => ['HasFactory', ['--factory' => true]],
    // Declared as written, under Eloquent's own import.
    'Model' => ['Model', []],
]);

test('it says what to register in the provider, fully qualified', function () {
    // Read back rather than asserted through the console expectations, which
    // match per write call and never see what the components print.
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true, '--command' => ['SyncWidgets'],
    ]);

    // Nothing it prints asks for an import. The provider keeps an import list
    // of its own, and two imports resolving to one short name is the fatal
    // this whole report exists to make unreachable: written out in full, a
    // pasted line cannot collide with anything.
    expect(Artisan::output())
        ->toContain('in $bindings')
        ->toContain('\App\ScaffoldFixture\Widgets\Domain\Contracts\WidgetRepository::class => \App\ScaffoldFixture\Widgets\Infrastructure\Persistence\EloquentWidgetRepository::class,')
        ->toContain('in $migrations')
        ->toContain("__DIR__.'/Widgets/Infrastructure/Persistence/Migrations',")
        // Laravel only autodiscovers app/Console/Commands, so a command that
        // is not declared exists as a file and as nothing else.
        ->toContain('in $commands')
        ->toContain('\App\ScaffoldFixture\Widgets\Infrastructure\Console\Commands\SyncWidgets::class,')
        ->not->toContain('use App\ScaffoldFixture\Widgets');
});

test('it asks for a migrations path only when it generated a migration', function () {
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget']);

    expect(Artisan::output())
        ->toContain('in $bindings')
        ->not->toContain('in $migrations');
});

test('the report names every class in a form that can be pasted', function () {
    // Pasted short, the seeder entry resolves inside Database\Seeders, which
    // composer maps to two directories that do not hold it, so db:seed aborts.
    // The route line resolves to a global controller instead, which registers
    // fine and 500s on the first request. Both targets keep an import list of
    // their own: DatabaseSeeder already imports a UserSeeder, and the shipped
    // route file opens with four controllers.
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget',
        '--seeder' => true, '--factory' => true, '--web' => true, '--api' => true,
    ]);

    // Both controllers in full, too: web and API share a short name and only
    // the full one tells them apart.
    expect(Artisan::output())
        ->toContain('\App\ScaffoldFixture\Widgets\Infrastructure\Persistence\Seeders\WidgetSeeder::class,')
        ->toContain('[\App\ScaffoldFixture\Widgets\Infrastructure\Http\Controllers\WidgetController::class,')
        ->toContain('[\App\ScaffoldFixture\Widgets\Infrastructure\Http\Controllers\API\WidgetController::class,')
        ->not->toContain('use App\ScaffoldFixture\Widgets');
});

test('it hints a distinct route for the api controller', function () {
    // Sharing a URI does not add a second route: RouteCollection keys by
    // method and URI, so the api route would replace the web one and take
    // its name with it. Sharing a name resolves route() to whichever came
    // first. Both have to differ.
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true, '--api' => true,
    ])
        ->expectsOutputToContain("Route::get('/widgets/{id}', [\\App\\ScaffoldFixture\\Widgets\\Infrastructure\\Http\\Controllers\\WidgetController::class, 'show'])->name('widgets.show');")
        // Guarded. bootRoutes() applies the api middleware group, which in
        // Laravel is SubstituteBindings and nothing else, so a pasted route
        // answers with no token.
        ->expectsOutputToContain("Route::prefix('api')->middleware('auth:sanctum')->group(function () {")
        ->expectsOutputToContain("Route::get('/widgets/{id}', [\\App\\ScaffoldFixture\\Widgets\\Infrastructure\\Http\\Controllers\\API\\WidgetController::class, 'show'])->name('api.widgets.show');")
        ->assertSuccessful();
});

test('it says how to create the route file the hint points at', function () {
    // The fixture context was created without --web, so following the hint
    // literally yields a route file nothing loads and a silent 404.
    // Read back rather than asserted through the console expectations: those
    // are consumed one per emitted line, and both of these are on one line.
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true]);

    expect(Artisan::output())
        ->toContain('That file does not exist yet')
        ->toContain('ldd:make:bounded-context ScaffoldFixture --web');
});

test('it says nothing about creating a route file that is already there', function () {
    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldFixture', '--web' => true])->assertSuccessful();

    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true]);

    // Anchored on something the run does print, so an empty output cannot
    // pass this by saying nothing at all.
    expect(Artisan::output())
        ->toContain('Declare the web route')
        ->not->toContain('That file does not exist yet');
});

test('the route hint is hedged and says what pasting it twice costs', function () {
    // Nothing reads the route file any more, and RouteCollection keys by
    // method and URI: a second copy of the canonical line replaces the first
    // outright, so somebody who wrapped theirs in middleware loses it, with
    // route:list showing one entry and no error anywhere.
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true]);

    expect(Artisan::output())
        ->toContain('if it is not there already')
        ->toContain('replaces the first, middleware and all')
        // The provider is already there, so that run cannot render $routes
        // into it. It prints the entry instead, and this must not say
        // otherwise.
        ->toContain('which that run prints for you')
        ->not->toContain('which also declares it');
});

test('it does not advise creating a page that already exists', function () {
    File::ensureDirectoryExists($this->pages);
    File::put("{$this->pages}/Show.vue", '<template><div /></template>');

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--web' => true])
        ->doesntExpectOutputToContain('Create the page at')
        ->assertSuccessful();
});

test('it says what a model it did not write still needs, naming every class in full', function () {
    // The model is never rewritten, so adding an option later costs a line or
    // two by hand. These lines go into a file this run did not write, and the
    // classes named live in Domain\Events and Domain\Factories rather than
    // beside it: pasted short, they resolve to the model's own namespace and
    // to nothing at all.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])->assertSuccessful();

    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--events' => true, '--factory' => true, '--migration' => true,
    ]);

    expect(Artisan::output())
        ->toContain('already existed and was left as it is')
        ->toContain('\App\ScaffoldFixture\Widgets\Domain\Events\WidgetCreated::new(')
        ->toContain('use \Illuminate\Database\Eloquent\Factories\HasFactory;')
        ->toContain(': \App\ScaffoldFixture\Widgets\Domain\Factories\WidgetFactory')
        // An aggregate written before the factory base class existed declares
        // new(): self and implements nothing, and the factory generated for it
        // does not type check against AggregateFactory's template.
        ->toContain('implements \App\Shared\Domain\BuildsFromAttributes')
        // The model keeps pointing at whatever table it declares, so a
        // migration for another one creates a table nothing reads.
        ->toContain("protected \$table = 'widgets';");
});

test('it says nothing about a model it wrote in this run', function () {
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--events' => true, '--factory' => true,
    ]);

    // The model this run wrote already carries both, so repeating the advice
    // would have somebody redeclare newFactory(), which is fatal.
    expect(Artisan::output())
        ->toContain('Aggregate [Widget] created')
        ->not->toContain('already existed and was left as it is');
});

test('re-running adds the missing piece without touching the model', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget'])->assertSuccessful();

    $model = app_path('ScaffoldFixture/Widgets/Domain/Widget.php');
    File::put($model, str_replace("        //\n    ];", "        'reference',\n    ];", File::get($model)));

    // Adding an option you skipped is the reason to run this twice, and the
    // model is where hand-written work accumulates.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--events' => true])
        ->assertSuccessful();

    expect(app_path('ScaffoldFixture/Widgets/Domain/Events/WidgetCreated.php'))->toBeFile()
        ->and(File::get($model))->toContain("'reference',")
        ->and(File::get($model))->not->toContain('registerDomainEvent');
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

test('it says that nothing publishes the generated event', function () {
    // The aggregate records the event; only a use case publishes it. Without
    // one the events pile up on the instance and vanish with it, which is the
    // silent failure these commands exist to prevent.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--events' => true])
        ->expectsOutputToContain('nothing publishes WidgetCreated')
        ->expectsOutputToContain('ldd:make:use-case ScaffoldFixture Widget CreateWidget --publishes')
        ->assertSuccessful();
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

test('the generated model has a place to hide attributes', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--api' => true])
        ->assertSuccessful();

    // The API controller returns the model straight into a JsonResponse, so
    // whatever reaches $fillable is published unless $hidden says otherwise.
    // Anchored to a real property, not a mention in a comment.
    expect(File::get(app_path('ScaffoldFixture/Widgets/Domain/Widget.php')))
        ->toMatch('/^    protected \$hidden = \[/m');
});

test('the seeder flag generates a seeder and says to register it', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--seeder' => true])
        // Which of the two lists it belongs in is a decision: reference data
        // another seeder depends on has to come first, and sample data has no
        // business running in production at all.
        ->expectsOutputToContain('in $seeders')
        ->expectsOutputToContain('or $fixtures, if it is sample data')
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

    $seeder = app_path('ScaffoldFixture/Widgets/Infrastructure/Persistence/Seeders/WidgetSeeder.php');

    expect(File::get($seeder))
        ->toContain('use App\ScaffoldFixture\Widgets\Domain\Widget;')
        ->toContain('Widget::factory()->count(10)->create();')
        ->and(php_parses($seeder))->toBeTrue();
});

test('it refuses names that are not valid PHP identifiers', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => '2024Report'])
        ->assertFailed();

    expect(app_path('ScaffoldFixture/2024Reports'))->not->toBeDirectory();
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

test('it pluralises the aggregate directory and studlies the class', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'widget'])
        ->assertSuccessful();

    expect(app_path('ScaffoldFixture/Widgets/Domain/Widget.php'))->toBeFile();
});

test('it refuses an unknown bounded context', function () {
    // Prerequisites cascade and nothing is generated upwards: the context
    // comes first, and it is the one thing that is wired on creation.
    $this->artisan('ldd:make:aggregate', ['context' => 'Nope', 'name' => 'Widget'])
        ->expectsOutputToContain('ldd:make:bounded-context Nope')
        ->assertFailed();
});

test('it refuses to scaffold into the Shared foundation layer', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'Shared', 'name' => 'Widget'])
        ->assertFailed();

    expect(app_path('Shared/Widgets'))->not->toBeDirectory();
});

test('it refuses the Shared foundation layer however it is typed', function () {
    // Str::studly leaves inner case alone, so `sHared` arrives as `SHared`,
    // which is not === 'Shared'. On a case-insensitive filesystem the
    // prerequisite check then finds app/SHared/SHaredServiceProvider.php and
    // the aggregate is scaffolded straight into the foundation layer.
    $this->artisan('ldd:make:aggregate', ['context' => 'sHared', 'name' => 'Widget'])
        ->assertFailed();

    // Asserted on the real directory, not on the spelling: app/SHared and
    // app/Shared are the same directory on a case-insensitive filesystem,
    // which is half of why this got through.
    expect(app_path('Shared/Widgets'))->not->toBeDirectory();
});

test('it refuses a table name that is not a valid identifier', function () {
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true, '--table' => "odd'name",
    ])->assertFailed();

    // The name reaches both the model's $table property and Schema::create(),
    // so an unchecked quote ships two files that do not parse.
    expect(app_path('ScaffoldFixture/Widgets'))->not->toBeDirectory();
});

test('it refuses a table another context already creates', function () {
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget', '--migration' => true])
        ->assertSuccessful();

    $this->artisan('ldd:make:bounded-context', ['name' => 'ScaffoldOther'])->assertSuccessful();

    // Both would Schema::create('widgets') and abort migrate on a fresh database.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldOther', 'name' => 'Widget', '--migration' => true])
        ->expectsOutputToContain('already has a create migration')
        ->assertFailed();

    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldOther', 'name' => 'Widget', '--migration' => true, '--table' => 'other_widgets',
    ])->assertSuccessful();
});

test('it refuses a table Shared already creates a migration for', function () {
    // Aggregates keep their migrations two segments into app/, and Shared
    // keeps the framework's own one segment in. Scanning only the first meant
    // an aggregate called Job put a second create_jobs_table beside Shared's,
    // and two create migrations for one table abort migrate on a fresh
    // database.
    //
    // The guard reads migration filenames, which is the convention every
    // migration this application generates follows. A hand-written migration
    // creating a table its filename does not name is not covered, and that
    // failure is loud: migrate stops and says which table it is.
    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Job', '--migration' => true])
        ->expectsOutputToContain('app/Shared/Infrastructure/Persistence/Migrations')
        ->assertFailed();

    expect(app_path('ScaffoldFixture/Jobs'))->not->toBeDirectory();
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

test('everything it generates parses', function () {
    // The delegated flags are named here too: --all does not cover them. With
    // no compile check left, this is the only thing standing between a typo in
    // a stub and a green run: every other test asserts on substrings and file
    // existence, and the fixture is deleted in teardown, so CI's Pint,
    // PHPStan and arch legs never see the generated code.
    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture',
        'name' => 'Widget',
        '--all' => true,
        '--mail' => ['WidgetShipped'],
        '--job' => ['RebuildWidgetIndex'],
        '--notification' => ['WidgetReady'],
        '--command' => ['SyncWidgets'],
    ])->assertSuccessful();

    $files = File::allFiles(app_path('ScaffoldFixture'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(php_parses($file->getPathname()))
            ->toBeTrue("{$file->getRelativePathname()} does not parse");
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

test('it does not ask for a console command twice when the flag is repeated', function () {
    // Artisan reuses the command instance between calls in one process, so
    // per-run state has to be reset before each run rather than accumulated.
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:aggregate', [
        'context' => 'ScaffoldFixture', 'name' => 'Widget', '--command' => ['SyncWidgets', 'SyncWidgets'],
    ]);

    expect(substr_count(Artisan::output(), 'SyncWidgets::class,'))->toBe(1);
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

test('the report does not carry from one run into the next', function () {
    // Artisan reuses the command instance between calls in one process, which
    // is how a per-run list on a property leaks: the second run would repeat
    // the first one's bindings alongside its own.
    $this->withoutMockingConsoleOutput();

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Widget']);

    Artisan::output();

    $this->artisan('ldd:make:aggregate', ['context' => 'ScaffoldFixture', 'name' => 'Gadget']);

    expect(Artisan::output())
        ->toContain('GadgetRepository::class')
        ->not->toContain('WidgetRepository::class');
});
