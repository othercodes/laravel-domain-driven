<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

use Illuminate\Support\Str;

/**
 * Class MakeAggregateCommand
 *
 * Scaffolds an aggregate inside an existing bounded context.
 *
 * Only the core is generated: the aggregate root, its repository contract
 * and Eloquent implementation, and its exceptions. Everything else is opt
 * in, because real aggregates rarely need every layer.
 *
 * Nothing is wired. The files this writes are coherent among themselves, and
 * what has to be registered elsewhere is printed at the end: the repository
 * binding, the migrations path, any console command, the seeder, the routes.
 * The provider is a file this command did not create and does not read, and
 * that is what keeps a bad name here from taking the application down.
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class MakeAggregateCommand extends ScaffoldCommand
{
    protected $signature = 'ldd:make:aggregate
        {context : The bounded context it belongs to, e.g. Billing}
        {name : The aggregate root name, singular, e.g. Invoice}
        {--migration : Add a migration}
        {--factory : Add a model factory}
        {--seeder : Add a seeder}
        {--events : Add a Created domain event and record it}
        {--requests : Add a form request}
        {--web : Add an Inertia controller}
        {--api : Add an API controller}
        {--all : Everything above}
        {--mail=* : A mailable in Application/Mail, e.g. --mail=InvoicePaid}
        {--job=* : A queued job in Application/Jobs}
        {--notification=* : A notification in Application/Notifications}
        {--command=* : An Artisan command in Infrastructure/Console/Commands}
        {--table= : Table name, defaults to the pluralised aggregate}';

    protected $description = 'Create an aggregate inside a bounded context';

    private string $context;

    private string $aggregate;

    private string $plural;

    private string $variable;

    private string $table;

    public function handle(): int
    {
        $context = $this->identifier((string) $this->argument('context'), 'bounded context');

        // The name is taken as written. Singularising it turns Analysis into
        // Analysi, and no heuristic tells that apart from a real plural.
        $aggregate = $this->identifier((string) $this->argument('name'), 'aggregate');

        if ($context === null || $aggregate === null) {
            return self::FAILURE;
        }

        $this->context = $context;
        $this->aggregate = $aggregate;
        $this->plural = Str::plural($this->aggregate);
        $this->variable = Str::camel($this->aggregate);
        $this->table = (string) ($this->option('table') ?: Str::snake($this->plural));

        // The table name is interpolated into the model's $table property and
        // into Schema::create(), so a quote or a space in it produces two
        // files that do not parse. The migrations path is registered as a
        // directory, which takes `migrate` down with it.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->table) !== 1) {
            $this->components->error("The table name must be a valid identifier, [{$this->table}] is not.");

            return self::FAILURE;
        }

        if ($this->refuses($this->context, self::SHARED_CONTEXT)) {
            $this->components->error('[Shared] is the foundation layer, not a bounded context that can own aggregates.');

            return self::FAILURE;
        }

        // An aggregate needs a context the way a handler needs an aggregate.
        // Creating one from here would be generating upwards, and the context
        // is also the one thing this command never touches again: it only
        // prints what to add to its provider.
        if (! $this->files->exists(app_path("{$this->context}/{$this->context}ServiceProvider.php"))) {
            $this->components->error("The bounded context [{$this->context}] does not exist.");
            $this->components->bulletList([
                "Create it with: php artisan ldd:make:bounded-context {$this->context}",
            ]);

            return self::FAILURE;
        }

        if ($this->wants('migration') && ($owner = $this->tableOwnedElsewhere()) !== null) {
            $this->components->error("Table [{$this->table}] already has a create migration in [{$owner}].");
            $this->components->bulletList([
                'Pass a different name with: --table='.Str::snake($this->context)."_{$this->table}",
            ]);

            return self::FAILURE;
        }

        $path = app_path("{$this->context}/{$this->plural}");

        // Running this again is how you add an option you skipped, so nothing
        // already on disk is rewritten: the model may have been worked on and
        // the migration may already have been applied.
        $modelExisted = $this->files->exists("{$path}/Domain/{$this->aggregate}.php");

        $this->writeCore($path);
        $this->writeOptional($path);

        // Threaded through rather than kept on the command: Artisan reuses the
        // instance between calls in the same process, so a property here would
        // carry one run's console commands into the next.
        $delegated = $this->writeDelegated();

        $this->newLine();
        $this->components->info("Aggregate [{$this->aggregate}] ".($modelExisted ? 'updated' : 'created')." in [{$this->context}]. Nothing was wired.");

        $this->reportProvider($delegated['commands']);
        $this->reportModel($modelExisted);
        $this->reportSeeder();
        $this->reportEvents();
        $this->reportRoutes();

        $this->report();

        // A name this command refused counts as a failure: something was asked
        // for and is not there, and a chained script must not carry on.
        return $delegated['complete'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Everything that goes into the context's service provider.
     *
     * @param  list<string>  $consoleCommands
     */
    private function reportProvider(array $consoleCommands): void
    {
        $file = app_path("{$this->context}/{$this->context}ServiceProvider.php");

        $contract = "App\\{$this->context}\\{$this->plural}\\Domain\\Contracts\\{$this->aggregate}Repository";
        $eloquent = "App\\{$this->context}\\{$this->plural}\\Infrastructure\\Persistence\\Eloquent{$this->aggregate}Repository";

        // Without this the aggregate resolves nothing, and silently: the
        // container throws only when something first asks for the contract.
        $this->wire(
            "the [{$this->aggregate}] repository",
            $file,
            'bindings',
            'public array $bindings = [',
            ["\\{$contract}::class => \\{$eloquent}::class,"]
        );

        if ($this->wants('migration')) {
            // A migration in a directory nothing registers is never run, and
            // `migrate` reports nothing to migrate.
            $this->wire(
                'the migrations directory',
                $file,
                'migrations',
                'protected array $migrations = [',
                ["__DIR__.'/{$this->plural}/Infrastructure/Persistence/Migrations',"]
            );
        }

        // array_unique because the same flag can be passed twice in one run.
        $commands = array_values(array_unique($consoleCommands));

        if ($commands !== []) {
            // Laravel only autodiscovers app/Console/Commands, so a command
            // that is not declared here simply does not exist.
            $this->wire(
                'the console commands',
                $file,
                'commands',
                'protected array $commands = [',
                array_map(fn (string $class): string => "\\{$class}::class,", $commands)
            );
        }
    }

    /**
     * The model carries the wiring for --events and --factory, and it is not
     * rewritten once it exists. Adding either option later costs a line or
     * two by hand, which is the price of never losing what the model grew.
     */
    private function reportModel(bool $modelExisted): void
    {
        if (! $modelExisted) {
            return;
        }

        // Every class written out in full. These lines go into a model this
        // run did not write, so the imports it carries are not ours to
        // predict, and the classes named live in Domain\Events and
        // Domain\Factories rather than beside it: pasted short, they resolve
        // to the model's own namespace and to nothing at all.
        $event = "App\\{$this->context}\\{$this->plural}\\Domain\\Events\\{$this->aggregate}Created";
        $factory = "App\\{$this->context}\\{$this->plural}\\Domain\\Factories\\{$this->aggregate}Factory";

        $lines = [];

        if ($this->wants('events')) {
            $lines[] = "in new(): \${$this->variable}->registerDomainEvent(\\{$event}::new(\${$this->variable}->id));";
        }

        if ($this->wants('factory')) {
            $lines[] = 'in the class body: use \Illuminate\Database\Eloquent\Factories\HasFactory;';
            $lines[] = "protected static function newFactory(): \\{$factory} { return \\{$factory}::new(); }";
            // An aggregate written before the factory base class existed
            // declares new(): self and implements nothing, and the factory just
            // generated for it does not type check against AggregateFactory's
            // template. Both halves only work together: the interface returns
            // static, so self would not satisfy it.
            $lines[] = 'and, if it does not already: implements \App\Shared\Domain\BuildsFromAttributes,';
            $lines[] = 'with new() returning static and building with new static(...)';
        }

        if ($this->wants('migration')) {
            // The model is never rewritten, so a migration for a table it does
            // not declare creates one nothing reads, beside the one it uses.
            $lines[] = "and check it declares: protected \$table = '{$this->table}';";
        }

        if ($lines === []) {
            return;
        }

        $this->note(
            "[{$this->aggregate}] already existed and was left as it is. Add to app/{$this->context}/{$this->plural}/Domain/{$this->aggregate}.php what it does not already have:",
            $lines
        );
    }

    /**
     * A seeder nothing lists never runs, and db:seed reports success either
     * way. Which of the two lists it belongs in is a decision: reference data
     * another seeder depends on has to come first, and sample data has no
     * business running in production at all.
     */
    private function reportSeeder(): void
    {
        if (! $this->wants('seeder')) {
            return;
        }

        $class = "App\\{$this->context}\\{$this->plural}\\Infrastructure\\Persistence\\Seeders\\{$this->aggregate}Seeder";

        $this->wire(
            "[{$this->aggregate}Seeder]",
            app_path('Shared/Infrastructure/Persistence/Seeders/DatabaseSeeder.php'),
            'seeders',
            'private array $seeders = [',
            ["\\{$class}::class,  // or \$fixtures, if it is sample data"]
        );
    }

    /**
     * A recorded domain event does nothing until something publishes it, and
     * in this application that is the job of a use case: the repository only
     * persists. Nothing generated here closes that loop, and an event that is
     * never published fails the way everything else is built to prevent:
     * silently.
     */
    private function reportEvents(): void
    {
        if (! $this->wants('events')) {
            return;
        }

        $this->note(
            "Nothing publishes {$this->aggregate}Created until a use case does. Add one with:",
            [
                "php artisan ldd:make:use-case {$this->context} {$this->aggregate} Create{$this->aggregate} --publishes",
                '',
                "// or handle it: php artisan ldd:make:event-handler {$this->context} {$this->aggregate} <Handler>",
            ]
        );
    }

    /**
     * Controllers are generated, but neither the route nor the Vue page is:
     * routes live in a file the developer owns, and the page lives outside
     * app/ under a path that vite.config.js decides. So they get printed.
     */
    private function reportRoutes(): void
    {
        $slug = Str::kebab($this->plural);

        // Web and api must differ in both URI and name. RouteCollection keys
        // routes by method and URI, so reusing the URI replaces the web route
        // outright instead of adding a second one; and a route name is global,
        // so sharing that resolves route() to whichever came first.
        //
        // The api URI is kept distinct by the prefix group the context's api
        // file declares, which is the convention the rest of the application
        // follows: bootRoutes() applies the middleware group but no prefix.
        //
        // The controller is written out in full, no import, for the reason
        // every other line in this report is: the route file keeps an import
        // list of its own, and the one this context ships opens with four
        // controllers. It also tells the two apart on sight, since web and API
        // share a short name.
        $route = fn (string $name, string $fqcn): string => "Route::get('/{$slug}/{id}', [\\{$fqcn}::class, 'show'])->name('{$name}');";

        $controller = "App\\{$this->context}\\{$this->plural}\\Infrastructure\\Http\\Controllers\\{$this->aggregate}Controller";
        $apiController = "App\\{$this->context}\\{$this->plural}\\Infrastructure\\Http\\Controllers\\API\\{$this->aggregate}Controller";

        $snippets = [
            'web' => [$route("{$slug}.show", $controller)],
            'api' => [
                // Guarded, unlike the web snippet above. bootRoutes() applies
                // the api middleware group, which in Laravel is
                // SubstituteBindings and nothing else, so a pasted route
                // answers with no token. A web show page is often meant to be
                // public; an api one reached by id is not.
                "Route::prefix('api')->middleware('auth:sanctum')->group(function () {",
                '    '.$route("api.{$slug}.show", $apiController),
                '});',
            ],
        ];

        foreach ($snippets as $kind => $lines) {
            if (! $this->wants($kind)) {
                continue;
            }

            $file = app_path("{$this->context}/Shared/Infrastructure/Http/Routes/{$kind}.php");

            // The route file is opt in on both commands, so it may well not be
            // there. Creating it is not enough either: a route file the
            // provider does not declare is loaded by nothing, and the only
            // symptom is a 404.
            if (! $this->files->exists($file)) {
                $lines[] = '';
                $lines[] = "// That file does not exist yet. Create it with: php artisan ldd:make:bounded-context {$this->context} --{$kind}";
                $lines[] = "// which also declares it in {$this->context}ServiceProvider::\$routes.";
            }

            $this->note("Declare the {$kind} route in {$this->relative($file)}:", $lines);
        }

        $page = base_path("resources/templates/tailwindcss/js/Pages/{$this->plural}/Show.vue");

        if ($this->wants('web') && ! $this->files->exists($page)) {
            $this->note("Create the page at {$this->relative($page)}", []);
        }
    }

    /**
     * Reusing an aggregate name across contexts is normal in DDD, but the
     * table name is global: two create migrations for the same table abort
     * `migrate` on a fresh database. Returns the context that already owns it.
     */
    private function tableOwnedElsewhere(): ?string
    {
        $mine = app_path("{$this->context}/{$this->plural}/Infrastructure/Persistence/Migrations");

        // Both depths. Aggregates keep their migrations two segments in, and
        // Shared keeps the framework's own one segment in: cache, jobs,
        // failed_jobs, job_batches. Scanning only the first meant an aggregate
        // called Job passed the guard and put a second create_jobs_table
        // beside Shared's, which is exactly what this exists to stop.
        $dirs = array_merge(
            $this->files->glob(app_path('*/*/Infrastructure/Persistence/Migrations')) ?: [],
            $this->files->glob(app_path('*/Infrastructure/Persistence/Migrations')) ?: [],
        );

        foreach ($dirs as $dir) {
            if ($dir === $mine) {
                continue;
            }

            if (($this->files->glob("{$dir}/*_create_{$this->table}_table.php") ?: []) !== []) {
                return $this->relative($dir);
            }
        }

        return null;
    }

    private function wants(string $option): bool
    {
        return (bool) ($this->option($option) || $this->option('all'));
    }

    private function writeCore(string $path): void
    {
        $uses = [];

        if ($this->wants('events')) {
            $uses[] = "App\\{$this->context}\\{$this->plural}\\Domain\\Events\\{$this->aggregate}Created";
        }

        if ($this->wants('factory')) {
            // Laravel looks for a factory in Database\Factories and this one
            // is not there, so the model points at it explicitly. It lives in
            // Domain beside the aggregate it builds, which keeps that pointer
            // inside one layer: Domain already depends on Eloquent, since the
            // aggregate root is a Model, so nothing new is being let in.
            $uses[] = "App\\{$this->context}\\{$this->plural}\\Domain\\Factories\\{$this->aggregate}Factory";
            $uses[] = 'Illuminate\Database\Eloquent\Factories\HasFactory';
        }

        $model = $this->stub('aggregate.model', $this->replacements([
            '{{ modelUses }}' => '',
            '{{ modelTraits }}' => $this->wants('factory') ? "    use HasFactory;\n" : '',
            '{{ modelRegisterEvent }}' => $this->wants('events')
                ? "        \${$this->variable}->registerDomainEvent({$this->aggregate}Created::new(\${$this->variable}->id));\n"
                : '',
            '{{ modelFactoryMethod }}' => $this->wants('factory')
                ? "\n    protected static function newFactory(): {$this->aggregate}Factory\n    {\n        return {$this->aggregate}Factory::new();\n    }\n"
                : '',
        ]));

        $this->put("{$path}/Domain/{$this->aggregate}.php", $this->withImports($model, ...$uses));

        $this->put("{$path}/Domain/Contracts/{$this->aggregate}Repository.php", $this->stub('aggregate.repository', $this->replacements()));
        $this->put("{$path}/Domain/Exceptions/{$this->aggregate}Exception.php", $this->stub('aggregate.exception', $this->replacements()));
        $this->put("{$path}/Domain/Exceptions/{$this->aggregate}NotFound.php", $this->stub('aggregate.not-found', $this->replacements()));
        $this->put("{$path}/Infrastructure/Persistence/Eloquent{$this->aggregate}Repository.php", $this->stub('aggregate.eloquent-repository', $this->replacements()));
    }

    private function writeOptional(string $path): void
    {
        if ($this->wants('events')) {
            $this->put("{$path}/Domain/Events/{$this->aggregate}Created.php", $this->stub('aggregate.event', $this->replacements()));
        }

        if ($this->wants('factory')) {
            $this->put("{$path}/Domain/Factories/{$this->aggregate}Factory.php", $this->stub('aggregate.factory', $this->replacements()));
        }

        if ($this->wants('seeder')) {
            // A seeder that calls a factory nobody generated is a fatal error
            // the first time somebody runs db:seed, so the body follows what
            // was actually asked for rather than assuming the happy path.
            $seeder = $this->stub('aggregate.seeder', $this->replacements([
                '{{ seederUses }}' => '',
                '{{ seederBody }}' => $this->wants('factory')
                    ? "        {$this->aggregate}::factory()->count(10)->create();\n"
                    : "        // Nothing here yet. Generate a factory with --factory, then:\n        // {$this->aggregate}::factory()->count(10)->create();\n",
            ]));

            if ($this->wants('factory')) {
                $seeder = $this->withImports($seeder, "App\\{$this->context}\\{$this->plural}\\Domain\\{$this->aggregate}");
            }

            $this->put("{$path}/Infrastructure/Persistence/Seeders/{$this->aggregate}Seeder.php", $seeder);
        }

        if ($this->wants('migration')) {
            $dir = "{$path}/Infrastructure/Persistence/Migrations";

            // The filename carries a timestamp, so regenerating would drop a
            // second create-table migration beside the first and break
            // `migrate` with "table already exists". Reuse the existing one.
            $existing = $this->files->glob("{$dir}/*_create_{$this->table}_table.php") ?: [];

            $this->put(
                $existing[0] ?? "{$dir}/".date('Y_m_d_His')."_create_{$this->table}_table.php",
                $this->stub('aggregate.migration', $this->replacements())
            );
        }

        if ($this->wants('requests')) {
            $this->put("{$path}/Infrastructure/Http/Requests/Store{$this->aggregate}Request.php", $this->stub('aggregate.request', $this->replacements()));
        }

        if ($this->wants('web')) {
            $this->put("{$path}/Infrastructure/Http/Controllers/{$this->aggregate}Controller.php", $this->stub('aggregate.web-controller', $this->replacements()));
        }

        if ($this->wants('api')) {
            $this->put("{$path}/Infrastructure/Http/Controllers/API/{$this->aggregate}Controller.php", $this->stub('aggregate.api-controller', $this->replacements()));
        }

        // Both controllers publish through it, so it belongs to either flag.
        // Neither should be handing out the model itself: everything on it
        // would go over the wire, including whatever the next migration adds.
        if ($this->wants('web') || $this->wants('api')) {
            $this->put("{$path}/Infrastructure/Http/Resources/{$this->aggregate}Resource.php", $this->stub('aggregate.resource', $this->replacements()));
        }
    }

    /**
     * Generates the pieces Laravel already has a generator for, in the place
     * this architecture puts them rather than the one the generator defaults
     * to. Nothing here needs a stub of our own: what makes a mailable belong
     * to an aggregate is where it lives, not what is in it.
     *
     * These take names instead of being on or off, so --all does not cover
     * them. There is no obvious name for an aggregate's mailable, and one
     * aggregate often wants several.
     *
     * Returns the console commands to report, and whether everything asked for
     * is actually there. A name this command refused, or a generator that
     * declined, has to reach the exit code: printing an error and answering
     * success is how a chained script carries on regardless.
     *
     * @return array{commands: list<string>, complete: bool}
     */
    private function writeDelegated(): array
    {
        $base = "App\\{$this->context}\\{$this->plural}";
        $consoleCommands = [];
        $complete = true;

        $delegations = [
            ['mail', 'make:mail', "{$base}\\Application\\Mail"],
            ['job', 'make:job', "{$base}\\Application\\Jobs"],
            ['notification', 'make:notification', "{$base}\\Application\\Notifications"],
            ['command', 'make:command', "{$base}\\Infrastructure\\Console\\Commands"],
        ];

        foreach ($delegations as [$option, $generator, $namespace]) {
            /** @var list<string> $names */
            $names = (array) $this->option($option);

            foreach ($names as $name) {
                // Same guard as every other name this command takes: the
                // generators interpolate it straight into a class declaration.
                $class = $this->identifier($name, $option);

                if ($class === null) {
                    $complete = false;

                    continue;
                }

                $written = $this->generate($generator, "{$namespace}\\{$class}");

                if ($written === null) {
                    $complete = false;

                    continue;
                }

                if ($option === 'command') {
                    $consoleCommands[] = $written;
                }
            }
        }

        return ['commands' => $consoleCommands, 'complete' => $complete];
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function replacements(array $extra = []): array
    {
        return array_merge([
            '{{ context }}' => $this->context,
            '{{ aggregate }}' => $this->aggregate,
            '{{ plural }}' => $this->plural,
            '{{ variable }}' => $this->variable,
            '{{ pluralVariable }}' => Str::camel($this->plural),
            '{{ table }}' => $this->table,
        ], $extra);
    }
}
