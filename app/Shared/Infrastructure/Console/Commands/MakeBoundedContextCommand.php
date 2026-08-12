<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

/**
 * Class MakeBoundedContextCommand
 *
 * Scaffolds a bounded context: its directory, a declarative service
 * provider, optional route files, and the entry in bootstrap/providers.php.
 *
 * Layers are deliberately not created. A context earns its Domain,
 * Application and Infrastructure directories one aggregate at a time,
 * through ldd:make:aggregate.
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class MakeBoundedContextCommand extends ScaffoldCommand
{
    protected $signature = 'ldd:make:bounded-context
        {name : The bounded context name, e.g. VisaManagement}
        {--web : Add a web routes file}
        {--api : Add an api routes file}
        {--force : Overwrite files that already exist}';

    protected $description = 'Create a bounded context with its service provider';

    public function handle(): int
    {
        $context = $this->identifier((string) $this->argument('name'), 'bounded context');

        if ($context === null) {
            return self::FAILURE;
        }

        if ($context === self::SHARED_CONTEXT) {
            $this->components->error('[Shared] already exists as the application foundation layer.');

            return self::FAILURE;
        }

        $path = app_path($context);

        if ($this->files->isDirectory($path) && ! $this->option('force')) {
            $this->components->error("The bounded context [{$context}] already exists.");

            return self::FAILURE;
        }

        $this->writeProvider($context, $path);
        $this->writeRoutes($context, $path);
        $this->registerProvider($context);

        $this->newLine();
        $this->components->info("Bounded context [{$context}] created.");
        $this->components->bulletList([
            "Add aggregates with: php artisan ldd:make:aggregate {$context} <Aggregate>",
        ]);

        return self::SUCCESS;
    }

    private function writeProvider(string $context, string $path): void
    {
        $this->put(
            $path."/{$context}ServiceProvider.php",
            $this->stub('bounded-context.provider', [
                '{{ context }}' => $context,
                '{{ routes }}' => $this->routesProperty($context),
            ])
        );
    }

    private function writeRoutes(string $context, string $path): void
    {
        foreach (['web', 'api'] as $kind) {
            if (! $this->option($kind)) {
                continue;
            }

            $this->put(
                $path."/Shared/Infrastructure/Http/Routes/{$kind}.php",
                $this->stub("bounded-context.routes.{$kind}", ['{{ context }}' => $context])
            );
        }
    }

    /**
     * Renders the $routes property, or a comment showing the convention when
     * no route file was requested.
     */
    private function routesProperty(string $context): string
    {
        $entries = [];

        foreach (['web', 'api'] as $kind) {
            if ($this->option($kind)) {
                $entries[] = "        '{$kind}' => [__DIR__.'/Shared/Infrastructure/Http/Routes/{$kind}.php'],";
            }
        }

        if ($entries === []) {
            return implode("\n", [
                '',
                '    // Routes are published per context. Create',
                '    // Shared/Infrastructure/Http/Routes/web.php and declare it here:',
                '    //',
                '    // protected array $routes = [',
                "    //     'web' => [__DIR__.'/Shared/Infrastructure/Http/Routes/web.php'],",
                '    // ];',
            ]);
        }

        $lines = implode("\n", $entries);

        return implode("\n", [
            '',
            '    /** @var array<string, array<int, string>> */',
            '    protected array $routes = [',
            $lines,
            '    ];',
        ]);
    }

    /**
     * Adds the provider to bootstrap/providers.php, keeping the list sorted
     * the way the file already is.
     */
    private function registerProvider(string $context): void
    {
        $file = base_path('bootstrap/providers.php');
        $contents = $this->files->get($file);
        $class = "App\\{$context}\\{$context}ServiceProvider";

        if (str_contains($contents, $class)) {
            $this->components->twoColumnDetail('bootstrap/providers.php', '<fg=yellow>already registered</>');

            return;
        }

        $imported = $this->withImport($contents, $class);

        // Appending the short class name without its import would leave an
        // unqualified reference and a fatal on the next boot.
        if ($imported === null) {
            $this->components->twoColumnDetail('bootstrap/providers.php', '<fg=red>could not be updated</>');
            $this->components->warn("Register {$class} by hand.");

            return;
        }

        $contents = str_replace(
            "\n];",
            "\n    {$context}ServiceProvider::class,\n];",
            $imported
        );

        $this->files->put($file, $contents);
        $this->components->twoColumnDetail('bootstrap/providers.php', '<fg=green>updated</>');
    }
}
