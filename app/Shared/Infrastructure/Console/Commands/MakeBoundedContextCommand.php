<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

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
final class MakeBoundedContextCommand extends Command
{
    protected $signature = 'ldd:make:bounded-context
        {name : The bounded context name, e.g. VisaManagement}
        {--web : Add a web routes file}
        {--api : Add an api routes file}
        {--force : Overwrite files that already exist}';

    protected $description = 'Create a bounded context with its service provider';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $context = Str::studly((string) $this->argument('name'));

        if ($context === '') {
            $this->components->error('The bounded context name cannot be empty.');

            return self::FAILURE;
        }

        if ($context !== $this->argument('name')) {
            $this->components->info("Using [{$context}] as the context name.");
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
            $this->stub('bounded-context.provider', $context, [
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
                $this->stub("bounded-context.routes.{$kind}", $context)
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

        // Imports are kept alphabetical, otherwise Pint's ordered_imports
        // fixer would fail on every generated context.
        preg_match_all('/^use (.+);$/m', $contents, $matches);
        $imports = $matches[1];
        $imports[] = $class;
        sort($imports);

        $contents = preg_replace(
            '/^use .+;\n(use .+;\n)*/m',
            implode('', array_map(fn (string $i): string => "use {$i};\n", $imports)),
            $contents,
            1
        );

        $contents = str_replace(
            "\n];",
            "\n    {$context}ServiceProvider::class,\n];",
            (string) $contents
        );

        $this->files->put($file, $contents);
        $this->components->twoColumnDetail('bootstrap/providers.php', '<fg=green>updated</>');
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function stub(string $name, string $context, array $replacements = []): string
    {
        return str_replace(
            array_merge(['{{ context }}'], array_keys($replacements)),
            array_merge([$context], array_values($replacements)),
            $this->files->get(base_path("stubs/{$name}.stub"))
        );
    }

    private function put(string $path, string $contents): void
    {
        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->twoColumnDetail($this->relative($path), '<fg=yellow>exists, skipped</>');

            return;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);

        $this->components->twoColumnDetail($this->relative($path), '<fg=green>created</>');
    }

    private function relative(string $path): string
    {
        return Str::after($path, base_path().DIRECTORY_SEPARATOR);
    }
}
