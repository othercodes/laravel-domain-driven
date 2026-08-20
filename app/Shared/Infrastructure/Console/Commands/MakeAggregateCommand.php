<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

use App\Shared\Domain\BuildsFromAttributes;
use App\Shared\Infrastructure\Console\Support\SourceFile;
use Illuminate\Support\Str;

/**
 * Class MakeAggregateCommand
 *
 * Scaffolds an aggregate inside an existing bounded context, and wires it
 * into that context's service provider.
 *
 * Only the core is generated: the aggregate root, its repository contract
 * and Eloquent implementation, and its exceptions. Everything else is opt
 * in, because real aggregates rarely need every layer.
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class MakeAggregateCommand extends ScaffoldCommand
{
    protected $signature = 'ldd:make:aggregate
        {context : The bounded context it belongs to, e.g. Billing}
        {name : The aggregate root name, singular, e.g. Invoice}
        {--migration : Add a migration and register its path}
        {--factory : Add a model factory}
        {--seeder : Add a seeder and say how to register it}
        {--events : Add a Created domain event and record it}
        {--requests : Add a form request}
        {--web : Add an Inertia controller}
        {--api : Add an API controller}
        {--all : Everything above}
        {--mail=* : A mailable in Application/Mail, e.g. --mail=InvoicePaid}
        {--job=* : A queued job in Application/Jobs}
        {--notification=* : A notification in Application/Notifications}
        {--command=* : An Artisan command in Infrastructure/Console/Commands, wired into $commands}
        {--table= : Table name, defaults to the pluralised aggregate}';

    protected $description = 'Create an aggregate inside a bounded context';

    /**
     * The contract an aggregate declares so a factory can call new() on it.
     */
    private const BUILDS_FROM_ATTRIBUTES = BuildsFromAttributes::class;

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

        // Asked of the stubs as this run would render them, before anything
        // is written. The names that can collide are the ones interpolated
        // in, so an unrendered stub has nothing useful to compare.
        //
        // --factory adds HasFactory to the model after the stub is rendered,
        // so the stubs alone do not know about it.
        // seederUses is asked for whether or not --factory was passed, for the
        // same reason every stub is asked whether or not its flag was: the
        // guard answering differently depending on the flags is a worse thing
        // to reason about than a refused name nobody should want.
        if ($this->refusesCollidingNames('aggregate.*', $this->replacements([
            '{{ seederUses }}' => $this->seederUses(),
        ]), ['Illuminate\Database\Eloquent\Factories\HasFactory'])) {
            return self::FAILURE;
        }

        if ($this->context === self::SHARED_CONTEXT) {
            $this->components->error('[Shared] is the foundation layer, not a bounded context that can own aggregates.');

            return self::FAILURE;
        }

        // A directory alone is not a context: it also has to be wired by a
        // provider named after it, which is what this command edits.
        if (! $this->files->exists(app_path("{$this->context}/{$this->context}ServiceProvider.php"))) {
            $this->components->error("The bounded context [{$this->context}] does not exist.");
            $this->components->bulletList([
                "Create it with: php artisan ldd:make:bounded-context {$this->context}",
            ]);

            return self::FAILURE;
        }

        $path = app_path("{$this->context}/{$this->plural}");

        // Running this again is how you add an option you skipped, so nothing
        // already on disk is rewritten: the model may have been worked on and
        // the migration may already have been applied.
        $modelExisted = $this->files->exists("{$path}/Domain/{$this->aggregate}.php");

        // A file on disk is not the same as an aggregate that can be read.
        // Every question below answers false for a file that does not parse
        // and for one that holds no class at all, and false read off either is
        // not a fact about the aggregate: the table guard would wave a
        // mismatched migration through, and the hints would advise adding what
        // is already there. So the question asked is whether the class was
        // found, not whether the file parsed.
        $model = SourceFile::at("{$path}/Domain/{$this->aggregate}.php");

        if ($modelExisted && ! $model->declaresClass($this->aggregate)) {
            $reason = $model->parsed()
                ? "declares no class {$this->aggregate}"
                : 'does not parse';

            $this->components->error("[{$this->aggregate}] could not be read: {$this->relative("{$path}/Domain/{$this->aggregate}.php")} {$reason}.");
            $this->components->bulletList([
                'Fix it, then run this again.',
            ]);

            return self::FAILURE;
        }

        // The model is never rewritten, so a migration for a table it does not
        // declare creates one nothing reads, next to the one it does use.
        if ($this->wants('migration') && $modelExisted) {
            $declared = $model->propertyString('table');

            if ($declared !== null && $declared !== $this->table) {
                $this->components->error("[{$this->aggregate}] declares table [{$declared}], not [{$this->table}].");
                $this->components->bulletList([
                    "Pass --table={$declared}, or change the model's \$table by hand first.",
                ]);

                return self::FAILURE;
            }
        }

        if ($this->wants('migration') && ($owner = $this->tableOwnedElsewhere()) !== null) {
            $this->components->error("Table [{$this->table}] already has a create migration in [{$owner}].");
            $this->components->bulletList([
                'Pass a different name with: --table='.Str::snake($this->context)."_{$this->table}",
            ]);

            return self::FAILURE;
        }

        if (! $this->writeCore($path)) {
            return self::FAILURE;
        }

        $this->writeOptional($path);

        // Threaded through rather than kept on the command: Artisan reuses the
        // instance between calls in the same process, so a property here would
        // carry one run's console commands into the next.
        $delegated = $this->writeDelegated();
        $wired = $this->wireProvider($delegated['commands']);

        $this->newLine();
        $this->components->info("Aggregate [{$this->aggregate}] ".($modelExisted ? 'updated' : 'created')." in [{$this->context}].");

        $this->printModelHints($model, $modelExisted);
        $this->printEventHints($path);
        $this->printSeederHints();
        $this->printRouteHints();

        // The files exist but the context does not know about them, so a
        // script chaining on this command must not treat it as done. A name
        // this command refused counts the same: something was asked for and
        // is not there.
        return $wired && $delegated['complete'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The model carries the wiring for --events and --factory, and it is not
     * rewritten once it exists. Adding either option later costs a line or
     * two by hand, which is the price of never losing what the model grew.
     */
    private function printModelHints(SourceFile $model, bool $modelExisted): void
    {
        if (! $modelExisted) {
            return;
        }

        // What the model already carries decides this, not the flags: running
        // the same command twice would otherwise advise redeclaring
        // newFactory(), which is fatal, and registering the event twice. It is
        // the instance handle() already read, so a model that does not parse
        // never reaches here to be read as carrying nothing.
        $lines = [];

        if ($this->wants('events') && ! $model->calls('registerDomainEvent')) {
            $lines[] = "in new(): \${$this->variable}->registerDomainEvent({$this->aggregate}Created::new(\${$this->variable}->id));";
        }

        if ($this->wants('factory') && ! $model->declaresMethod('newFactory')) {
            $lines[] = 'use HasFactory; (imported from Illuminate\Database\Eloquent\Factories)';
            $lines[] = "protected static function newFactory(): {$this->aggregate}Factory { return {$this->aggregate}Factory::new(); }";
        }

        // An aggregate written before the factory base class existed declares
        // new(): self and implements nothing, and the factory just generated
        // for it does not type check against AggregateFactory's template. Both
        // halves are named because they only work together: the interface
        // returns static, so self would not satisfy it.
        if ($this->wants('factory') && ! $model->implementsInterface(self::BUILDS_FROM_ATTRIBUTES)) {
            $lines[] = 'implements BuildsFromAttributes (imported from App\Shared\Domain)';
            $lines[] = 'and change new() to return static, building with new static(...)';
        }

        if ($lines === []) {
            return;
        }

        $this->newLine();
        $this->line("  <fg=yellow>{$this->aggregate} was left as it is. Add to it by hand:</>");

        foreach ($lines as $line) {
            $this->line("  <fg=gray>{$line}</>");
        }
    }

    /**
     * A recorded domain event does nothing until something publishes it, and
     * in this application that is the job of a use case: the repository only
     * persists. Nothing generated here closes that loop, and an event that is
     * never published fails the way everything else in these commands is
     * built to prevent: silently.
     */
    private function printEventHints(string $path): void
    {
        if (! $this->wants('events')) {
            return;
        }

        // What the aggregate's application layer already does decides this,
        // asked through the shared helper so this command and ldd:make:use-case
        // cannot answer it differently.
        $answer = $this->publishesDomainEvents("{$path}/Application");

        if ($answer['publishes']) {
            return;
        }

        // One of them may well be the use case that publishes, so the advice
        // below would be telling somebody to write what they already have.
        if ($answer['unreadable'] !== []) {
            $this->newLine();
            $this->line("  <fg=yellow>Could not tell whether anything publishes {$this->aggregate}Created: these do not parse:</>");

            foreach ($answer['unreadable'] as $file) {
                $this->line("  <fg=gray>{$file}</>");
            }

            return;
        }

        $this->newLine();
        $this->line("  <fg=yellow>Nothing publishes {$this->aggregate}Created. Add a use case in {$this->plural}/Application that does:</>");
        $this->line("  <fg=gray>\${$this->variable} = \$this->repository->save({$this->aggregate}::new(\$input));</>");
        $this->line("  <fg=gray>\${$this->variable}->publishDomainEvents(\$this->eventBus);  // ComplexHeart\\Domain\\Contracts\\Events\\EventBus</>");
        $this->line('  <fg=gray>// both inside DB::transaction, so a failing listener cannot leave the aggregate persisted</>');
    }

    /**
     * A seeder nothing lists never runs, and db:seed reports success either
     * way. This is printed rather than wired because seeders run in the order
     * DatabaseSeeder lists them, and appending to the end is a guess at where
     * this one belongs: reference data another seeder depends on has to go
     * first, and only the developer knows whether this is that.
     */
    private function printSeederHints(): void
    {
        if (! $this->wants('seeder')) {
            return;
        }

        $file = app_path('Shared/Infrastructure/Persistence/Seeders/DatabaseSeeder.php');
        $class = "App\\{$this->context}\\{$this->plural}\\Infrastructure\\Persistence\\Seeders\\{$this->aggregate}Seeder";

        $seeder = SourceFile::at($file);

        // What DatabaseSeeder already lists decides this, not the flag, so
        // re-running to add another option does not advise a second entry.
        if ($seeder->references($class)) {
            return;
        }

        $this->newLine();

        // It may already list the entry, so say what is wrong rather than
        // advise adding a duplicate to a file that has to be fixed first.
        if ($this->files->exists($file) && ! $seeder->parsed()) {
            $this->line("  <fg=yellow>{$this->relative($file)} does not parse, so whether it lists {$this->aggregate}Seeder could not be checked.</>");

            return;
        }

        if (! $this->files->exists($file)) {
            $this->line("  <fg=yellow>{$this->aggregate}Seeder will not run: no DatabaseSeeder at {$this->relative($file)}.</>");

            return;
        }

        $this->line("  Add to <options=bold>{$this->relative($file)}</>:");
        $this->line("  <fg=gray>{$this->aggregate}Seeder::class,  // in \$seeders, or \$fixtures if it is sample data</>");
    }

    /**
     * Controllers are generated, but neither the route nor the Vue page is:
     * routes live in a file the developer owns, and the page lives outside
     * app/ under a path that vite.config.js decides. So they get printed.
     */
    private function printRouteHints(): void
    {
        $dir = app_path("{$this->context}/Shared/Infrastructure/Http/Routes");
        $slug = Str::kebab($this->plural);

        // Web and api must differ in both URI and name. RouteCollection keys
        // routes by method and URI, so reusing the URI replaces the web route
        // outright instead of adding a second one; and a route name is global,
        // so sharing that resolves route() to whichever came first.
        //
        // The api URI is kept distinct by the prefix group the context's api
        // file declares, which is the convention the rest of the application
        // follows: bootRoutes() applies the middleware group but no prefix.
        $route = fn (string $name): string => "Route::get('/{$slug}/{id}', [{$this->aggregate}Controller::class, 'show'])->name('{$name}');";

        $controller = "App\\{$this->context}\\{$this->plural}\\Infrastructure\\Http\\Controllers\\{$this->aggregate}Controller";

        $snippets = [
            'web' => [
                'controller' => $controller,
                'lines' => [$route("{$slug}.show")],
            ],
            'api' => [
                'controller' => "App\\{$this->context}\\{$this->plural}\\Infrastructure\\Http\\Controllers\\API\\{$this->aggregate}Controller",
                'lines' => [
                    "Route::prefix('api')->group(function () {",
                    '    '.$route("api.{$slug}.show"),
                    '});',
                    "// the {$this->aggregate}Controller here is the one under Http\\Controllers\\API",
                ],
            ],
        ];

        foreach ($snippets as $kind => $snippet) {
            if (! $this->wants($kind)) {
                continue;
            }

            $file = "{$dir}/{$kind}.php";

            // What the route file already holds decides this, not the flags.
            // A developer who has declared the route may well have put it
            // behind middleware, and pasting the canonical one back replaces
            // it: RouteCollection keys by method and URI.
            //
            // Asked by its full name, which is what tells the web controller
            // from the one under API: they share a short name.
            $routes = SourceFile::at($file);

            if ($routes->references($snippet['controller'])) {
                continue;
            }

            $this->newLine();

            // It may already declare the route, so pasting the canonical one
            // back would replace whatever middleware it was wrapped in.
            if ($this->files->exists($file) && ! $routes->parsed()) {
                $this->line("  <fg=yellow>{$this->relative($file)} does not parse, so whether it declares the {$kind} route could not be checked.</>");

                continue;
            }

            $this->line("  Add to <options=bold>{$this->relative($file)}</>:");

            foreach ($snippet['lines'] as $line) {
                $this->line("  <fg=gray>{$line}</>");
            }

            // Both commands make route files opt in, so this one may well not
            // exist. Creating it is not enough either: a route file the
            // provider does not declare is never loaded, and the only symptom
            // is a 404.
            if (! $this->files->exists($file)) {
                $this->line("  <fg=yellow>That file does not exist yet: create it and declare it in {$this->context}ServiceProvider::\$routes.</>");
            }
        }

        $page = base_path("resources/templates/tailwindcss/js/Pages/{$this->plural}/Show.vue");

        if ($this->wants('web') && ! $this->files->exists($page)) {
            $this->newLine();
            $this->line("  Create the page at <options=bold>{$this->relative($page)}</>");
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

        foreach ($this->files->glob(app_path('*/*/Infrastructure/Persistence/Migrations')) ?: [] as $dir) {
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

    private function writeCore(string $path): bool
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

        foreach ($uses as $class) {
            $imported = $this->withImport($model, $class);

            // Two reasons reach here and the fix differs, so neither may be
            // guessed at. An aggregate named after something the stub already
            // imports is the reachable one: `hAs` studlies to `HAs`, whose
            // factory answers to the same name as Eloquent's HasFactory, and
            // PHP compares those without case.
            if ($imported === null) {
                $this->components->error("Could not add [{$class}] to the model.");
                $this->components->bulletList([
                    "Rename the aggregate if something the model already imports answers to the same short name as [{$class}].",
                    'Otherwise stubs/aggregate.model.stub has no namespace declaration; it is meant to be edited, so that is reachable too.',
                ]);

                return false;
            }

            $model = $imported;
        }

        $this->put("{$path}/Domain/{$this->aggregate}.php", $model);

        $this->put("{$path}/Domain/Contracts/{$this->aggregate}Repository.php", $this->stub('aggregate.repository', $this->replacements()));
        $this->put("{$path}/Domain/Exceptions/{$this->aggregate}Exception.php", $this->stub('aggregate.exception', $this->replacements()));
        $this->put("{$path}/Domain/Exceptions/{$this->aggregate}NotFound.php", $this->stub('aggregate.not-found', $this->replacements()));
        $this->put("{$path}/Infrastructure/Persistence/Eloquent{$this->aggregate}Repository.php", $this->stub('aggregate.eloquent-repository', $this->replacements()));

        return true;
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
                // App sorts before Illuminate, so this goes in ahead of the
                // Seeder import and Pint's ordered_imports has nothing to fix.
                '{{ seederUses }}' => $this->wants('factory') ? $this->seederUses() : '',
                '{{ seederBody }}' => $this->wants('factory')
                    ? "        {$this->aggregate}::factory()->count(10)->create();\n"
                    : "        // Nothing here yet. Generate a factory with --factory, then:\n        // {$this->aggregate}::factory()->count(10)->create();\n",
            ]));

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
     * Returns the console commands to declare in the provider, and whether
     * everything asked for is actually there. A name this command refused, or
     * a generator that declined, has to reach the exit code: printing an error
     * and answering success is how a chained script carries on regardless.
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

                // A console command Laravel does not autodiscover, since it
                // only ever scans app/Console/Commands, so the provider has to
                // declare it or the command simply never exists.
                if ($option === 'command') {
                    $consoleCommands[] = $written;
                }
            }
        }

        return ['commands' => $consoleCommands, 'complete' => $complete];
    }

    /**
     * Binds the repository and, when a migration was generated, registers its
     * path. Without this the aggregate would resolve nothing and its tables
     * would never be created, silently in both cases.
     *
     * @param  list<string>  $consoleCommands
     */
    private function wireProvider(array $consoleCommands): bool
    {
        $file = app_path("{$this->context}/{$this->context}ServiceProvider.php");

        $contract = "App\\{$this->context}\\{$this->plural}\\Domain\\Contracts\\{$this->aggregate}Repository";
        $eloquent = "App\\{$this->context}\\{$this->plural}\\Infrastructure\\Persistence\\Eloquent{$this->aggregate}Repository";
        // Every entry this method appends is written out in full rather than
        // imported under its short name. Aggregate and command names are free
        // text: two aggregates in one context may each want a SyncThings, and
        // an aggregate called Widget puts a WidgetRepository beside whatever
        // the provider already imports. Two imports resolving to one short
        // name is a compile-time fatal, and because the provider is loaded
        // from bootstrap/providers.php it takes down every request and every
        // artisan call, including the one needed to undo it.
        $binding = "        \\{$contract}::class => \\{$eloquent}::class,";

        $contents = $this->files->get($file);

        // Asked of the declaration itself: matching the generated line would
        // miss a reformatted provider and append the binding a second time,
        // silently overriding whatever the first one pointed at, while
        // matching the text alone would count one left in a comment.
        $declared = SourceFile::at($file);

        // Unreadable is not the same as empty: taking it as empty binds the
        // repository a second time and registers the migration path again.
        if (! $declared->parsed()) {
            $this->components->twoColumnDetail($this->relative($file), '<fg=red>could not be read</>');
            $this->components->warn("{$this->context}ServiceProvider does not parse. Fix it, then run this again.");

            return false;
        }

        if (! in_array($contract, $declared->propertyKeys('bindings'), true)) {
            $contents = $this->appendToArray($contents, 'bindings', $binding);
        }

        if ($contents !== null && $this->wants('migration')) {
            $path = "/{$this->plural}/Infrastructure/Persistence/Migrations";

            if (! in_array($path, $declared->propertyStrings('migrations'), true)) {
                $contents = $this->appendToArray($contents, 'migrations', "        __DIR__.'{$path}',");
            }
        }

        // array_unique because the same flag can be passed twice in one run,
        // and the check below only sees the file as it was before this one.
        foreach (array_unique($consoleCommands) as $class) {
            // Asked of the declaration rather than of the flag, so a re-run
            // that regenerates nothing does not declare the command twice and
            // register it with Artisan twice over.
            if ($contents === null || $declared->references($class)) {
                continue;
            }

            $contents = $this->appendToArray($contents, 'commands', "        \\{$class}::class,");
        }

        // Reporting a green "wired" after a rewrite that matched nothing is
        // how an unbound contract and an unregistered migration path reach
        // production unnoticed. Say so instead.
        if ($contents === null) {
            $this->components->twoColumnDetail($this->relative($file), '<fg=red>could not be wired</>');

            // The file is only written once, at the end, so a null here means
            // none of it landed. Naming all three matters: a console command
            // left out of $commands is one Artisan never registers.
            $this->components->warn("Nothing was written to {$this->context}ServiceProvider. Add the repository binding, the migration path if one was generated, and any console command, by hand.");

            return false;
        }

        $this->files->put($file, $contents);
        $this->components->twoColumnDetail($this->relative($file), '<fg=green>wired</>');

        return true;
    }

    private function appendToArray(string $contents, string $property, string $entry): ?string
    {
        return $this->appendToList($contents, "array \${$property} = [", $entry);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    /**
     * The import --factory adds to the seeder, ahead of Illuminate's Seeder.
     *
     * Written once because the collision guard has to render the stub exactly
     * as this run will: with the placeholder left in, `{{ seederUses }}use
     * Illuminate\Database\Seeder;` is one line whose `use` is not at column
     * zero, and the guard never saw it. `ldd:make:aggregate Billing Seeder
     * --all` wrote both Seeder imports and exited 0.
     */
    private function seederUses(): string
    {
        return "use App\\{$this->context}\\{$this->plural}\\Domain\\{$this->aggregate};\n";
    }

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
