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
        {--force : Overwrite files that already exist}';

    protected $description = 'Create an aggregate inside a bounded context';

    private string $context;

    private string $aggregate;

    private string $plural;

    private string $variable;

    private string $table;

    public function handle(): int
    {
        $this->context = Str::studly((string) $this->argument('context'));
        $this->aggregate = Str::studly(Str::singular((string) $this->argument('name')));
        $this->plural = Str::plural($this->aggregate);
        $this->variable = Str::camel($this->aggregate);
        $this->table = Str::snake($this->plural);

        if (! $this->files->isDirectory(app_path($this->context))) {
            $this->components->error("The bounded context [{$this->context}] does not exist.");
            $this->components->bulletList([
                "Create it with: php artisan ldd:make:bounded-context {$this->context}",
            ]);

            return self::FAILURE;
        }

        $path = app_path("{$this->context}/{$this->plural}");

        if ($this->files->isDirectory($path) && ! $this->option('force')) {
            $this->components->error("The aggregate [{$this->plural}] already exists in [{$this->context}].");

            return self::FAILURE;
        }

        $this->writeCore($path);
        $this->writeOptional($path);
        $this->wireProvider();

        $this->newLine();
        $this->components->info("Aggregate [{$this->aggregate}] created in [{$this->context}].");

        $this->printRouteHints();

        return self::SUCCESS;
    }

    /**
     * Controllers are generated, but neither the route nor the Vue page is:
     * routes live in a file the developer owns, and the page lives outside
     * app/ under a path that vite.config.js decides. So they get printed.
     */
    private function printRouteHints(): void
    {
        $routes = base_path("app/{$this->context}/Shared/Infrastructure/Http/Routes");
        $slug = Str::kebab($this->plural);

        if ($this->wants('web')) {
            $this->newLine();
            $this->line("  Add to <options=bold>{$this->relative($routes)}/web.php</>:");
            $this->line("  <fg=gray>Route::get('/{$slug}', [{$this->aggregate}Controller::class, 'index'])->name('{$slug}.index');</>");
            $this->line("  and create the page at <options=bold>resources/templates/tailwindcss/js/Pages/{$this->plural}/Index.vue</>");
        }

        if ($this->wants('api')) {
            $this->newLine();
            $this->line("  Add to <options=bold>{$this->relative($routes)}/api.php</>:");
            $this->line("  <fg=gray>Route::get('/{$slug}', [{$this->aggregate}Controller::class, 'index'])->name('{$slug}.index');</>");
        }
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
            $model = $this->withImport($model, $class);
        }

        $this->put("{$path}/Domain/{$this->aggregate}.php", $model);

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
            $this->put("{$path}/Infrastructure/Persistence/{$this->aggregate}Factory.php", $this->stub('aggregate.factory', $this->replacements()));
        }

        if ($this->wants('migration')) {
            $name = date('Y_m_d_His')."_create_{$this->table}_table.php";
            $this->put("{$path}/Infrastructure/Persistence/Migrations/{$name}", $this->stub('aggregate.migration', $this->replacements()));
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
    private function wireProvider(): void
    {
        $file = app_path("{$this->context}/{$this->context}ServiceProvider.php");

        if (! $this->files->exists($file)) {
            $this->components->twoColumnDetail($this->relative($file), '<fg=yellow>missing, not wired</>');

            return;
        }

        $contract = "App\\{$this->context}\\{$this->plural}\\Domain\\Contracts\\{$this->aggregate}Repository";
        $eloquent = "App\\{$this->context}\\{$this->plural}\\Infrastructure\\Persistence\\Eloquent{$this->aggregate}Repository";
        $binding = "        {$this->aggregate}Repository::class => Eloquent{$this->aggregate}Repository::class,";

        $contents = $this->files->get($file);

        if (! str_contains($contents, $binding)) {
            $contents = $this->withImport($this->withImport($contents, $contract), $eloquent);
            $contents = $this->appendToArray($contents, 'bindings', $binding);
        }

        if ($this->wants('migration')) {
            $entry = "        __DIR__.'/{$this->plural}/Infrastructure/Persistence/Migrations',";

            if (! str_contains($contents, $entry)) {
                $contents = $this->appendToArray($contents, 'migrations', $entry);
            }
        }

        $this->files->put($file, $contents);
        $this->components->twoColumnDetail($this->relative($file), '<fg=green>wired</>');
    }

    /**
     * Appends an entry to a declared property array, whether it is currently
     * empty or already populated.
     */
    private function appendToArray(string $contents, string $property, string $entry): string
    {
        $empty = "array \${$property} = [];";

        if (str_contains($contents, $empty)) {
            return str_replace($empty, "array \${$property} = [\n{$entry}\n    ];", $contents);
        }

        return (string) preg_replace(
            '/(array \$'.$property.' = \[\n)(.*?)(    \];)/s',
            "$1$2{$entry}\n$3",
            $contents,
            1
        );
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
            '{{ author }}' => config('app.scaffold_author', 'Unay Santisteban <usantisteban@othercode.io>'),
        ], $extra);
    }
}
