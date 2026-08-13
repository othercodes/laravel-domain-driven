<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

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
        {--events : Add a Created domain event and record it}
        {--requests : Add a form request}
        {--web : Add an Inertia controller}
        {--api : Add an API controller}
        {--all : Everything above}
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
        // files that do not parse — and the migrations path is registered as a
        // directory, which takes `migrate` down with it.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->table) !== 1) {
            $this->components->error("The table name must be a valid identifier, [{$this->table}] is not.");

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

        // The model is never rewritten, so a migration for a table it does not
        // declare creates one nothing reads, next to the one it does use.
        if ($this->wants('migration') && $modelExisted) {
            $declared = $this->tableDeclaredIn("{$path}/Domain/{$this->aggregate}.php");

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
        $wired = $this->wireProvider();

        $this->newLine();
        $this->components->info("Aggregate [{$this->aggregate}] ".($modelExisted ? 'updated' : 'created')." in [{$this->context}].");

        $this->printModelHints($path, $modelExisted);
        $this->printRouteHints();

        // The files exist but the context does not know about them, so a
        // script chaining on this command must not treat it as done.
        return $wired ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The model carries the wiring for --events and --factory, and it is not
     * rewritten once it exists. Adding either option later costs a line or
     * two by hand, which is the price of never losing what the model grew.
     */
    private function printModelHints(string $path, bool $modelExisted): void
    {
        if (! $modelExisted) {
            return;
        }

        // What the model already carries decides this, not the flags: running
        // the same command twice would otherwise advise redeclaring
        // newFactory(), which is fatal, and registering the event twice.
        $model = $this->files->get("{$path}/Domain/{$this->aggregate}.php");
        $lines = [];

        if ($this->wants('events') && ! str_contains($model, 'registerDomainEvent')) {
            $lines[] = "in new(): \${$this->variable}->registerDomainEvent({$this->aggregate}Created::new(\${$this->variable}->id));";
        }

        if ($this->wants('factory') && ! str_contains($model, 'newFactory')) {
            $lines[] = 'use HasFactory; (imported from Illuminate\Database\Eloquent\Factories)';
            $lines[] = "protected static function newFactory(): {$this->aggregate}Factory { return {$this->aggregate}Factory::new(); }";
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

        $snippets = [
            'web' => [$route("{$slug}.show")],
            'api' => [
                "Route::prefix('api')->group(function () {",
                '    '.$route("api.{$slug}.show"),
                '});',
                "// the {$this->aggregate}Controller here is the one under Http\\Controllers\\API",
            ],
        ];

        foreach ($snippets as $kind => $lines) {
            if (! $this->wants($kind)) {
                continue;
            }

            $file = "{$dir}/{$kind}.php";

            // What the route file already holds decides this, not the flags.
            // A developer who has declared the route may well have put it
            // behind middleware, and pasting the canonical one back replaces
            // it: RouteCollection keys by method and URI.
            if ($this->files->exists($file)
                && str_contains($this->files->get($file), "{$this->aggregate}Controller")) {
                continue;
            }

            $this->newLine();
            $this->line("  Add to <options=bold>{$this->relative($file)}</>:");

            foreach ($lines as $line) {
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

    private function tableDeclaredIn(string $model): ?string
    {
        return preg_match('/protected \$table = \'([^\']+)\';/', $this->files->get($model), $matches) === 1
            ? $matches[1]
            : null;
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
            // The factory lives in Infrastructure and Laravel would not find
            // it by convention, so the model points at it explicitly. This is
            // the coupling the architecture test exempts for aggregate roots.
            $uses[] = "App\\{$this->context}\\{$this->plural}\\Infrastructure\\Persistence\\{$this->aggregate}Factory";
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

            // stubs/ is meant to be edited, so a stub with no namespace line
            // is reachable. Say so rather than dying on a TypeError.
            if ($imported === null) {
                $this->components->error("Could not add [{$class}] to the model: stubs/aggregate.model.stub needs a namespace declaration.");

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
            $this->put("{$path}/Infrastructure/Persistence/{$this->aggregate}Factory.php", $this->stub('aggregate.factory', $this->replacements()));
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
    }

    /**
     * Binds the repository and, when a migration was generated, registers its
     * path. Without this the aggregate would resolve nothing and its tables
     * would never be created — silently, in both cases.
     */
    private function wireProvider(): bool
    {
        $file = app_path("{$this->context}/{$this->context}ServiceProvider.php");

        $contract = "App\\{$this->context}\\{$this->plural}\\Domain\\Contracts\\{$this->aggregate}Repository";
        $eloquent = "App\\{$this->context}\\{$this->plural}\\Infrastructure\\Persistence\\Eloquent{$this->aggregate}Repository";
        $binding = "        {$this->aggregate}Repository::class => Eloquent{$this->aggregate}Repository::class,";

        $contents = $this->files->get($file);

        // Matching the generated line verbatim would miss a reformatted or
        // re-indented provider and append the binding a second time, silently
        // overriding whatever the first one pointed at.
        $alreadyBound = preg_match(
            '/\b'.preg_quote("{$this->aggregate}Repository", '/').'::class\s*=>/',
            $contents
        ) === 1;

        if (! $alreadyBound) {
            $contents = $this->withImport($contents, $contract);
            $contents = $contents === null ? null : $this->withImport($contents, $eloquent);
            $contents = $contents === null ? null : $this->appendToArray($contents, 'bindings', $binding);
        }

        if ($contents !== null && $this->wants('migration')) {
            $entry = "        __DIR__.'/{$this->plural}/Infrastructure/Persistence/Migrations',";

            if (! str_contains($contents, $entry)) {
                $contents = $this->appendToArray($contents, 'migrations', $entry);
            }
        }

        // Reporting a green "wired" after a rewrite that matched nothing is
        // how an unbound contract and an unregistered migration path reach
        // production unnoticed. Say so instead.
        if ($contents === null) {
            $this->components->twoColumnDetail($this->relative($file), '<fg=red>could not be wired</>');
            $this->components->warn("Add the binding and, if generated, the migration path to {$this->context}ServiceProvider by hand.");

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
