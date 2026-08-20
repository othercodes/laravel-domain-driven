<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

use App\Shared\Infrastructure\Console\Support\SourceFile;

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
        {--api : Add an api routes file}';

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

        // Asked of the stubs as this run would render them, before anything
        // is written. The names that can collide are the ones interpolated
        // in, so an unrendered stub has nothing useful to compare.
        //
        // The provider stub imports BoundedContextServiceProvider and declares
        // <Context>ServiceProvider, and this one is loaded from
        // bootstrap/providers.php: a fatal here takes the application down.
        if ($this->refusesCollidingNames('bounded-context.provider', [
            '{{ context }}' => $context,
            '{{ routes }}' => '',
        ])) {
            return self::FAILURE;
        }

        $path = app_path($context);
        $provider = $path."/{$context}ServiceProvider.php";

        // Running this again on a context already in use has to be safe: the
        // provider is the one file that accumulates hand-written wiring, and
        // rewriting it from the stub would drop every binding and migration
        // path while the aggregates stayed on disk.
        $providerExisted = $this->files->exists($provider);

        $this->writeProvider($context, $path);
        $routes = $this->writeRoutes($context, $path);
        $registered = $this->registerProvider($context);

        $this->newLine();
        $this->components->info("Bounded context [{$context}] ".($providerExisted ? 'updated.' : 'created.'));
        $this->components->bulletList([
            "Add aggregates with: php artisan ldd:make:aggregate {$context} <Aggregate>",
        ]);

        // A route file the provider does not declare is never loaded, and the
        // only symptom is a 404.
        if ($providerExisted && $routes !== []) {
            $this->newLine();
            $this->line("  <fg=yellow>{$context}ServiceProvider was left as it is. Declare the new file(s) in its \$routes:</>");

            foreach ($routes as $kind) {
                $this->line("  <fg=gray>'{$kind}' => [__DIR__.'/Shared/Infrastructure/Http/Routes/{$kind}.php'],</>");
            }
        }

        // The files exist but nothing loads them, so a script chaining on this
        // command must not treat it as done.
        return $registered ? self::SUCCESS : self::FAILURE;
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

    /**
     * @return list<string> The route files this run actually created.
     */
    private function writeRoutes(string $context, string $path): array
    {
        $created = [];

        foreach (['web', 'api'] as $kind) {
            if (! $this->option($kind)) {
                continue;
            }

            $written = $this->put(
                $path."/Shared/Infrastructure/Http/Routes/{$kind}.php",
                $this->stub("bounded-context.routes.{$kind}", ['{{ context }}' => $context])
            );

            if ($written) {
                $created[] = $kind;
            }
        }

        return $created;
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
     * Adds the provider to bootstrap/providers.php.
     */
    private function registerProvider(string $context): bool
    {
        $file = base_path('bootstrap/providers.php');
        $contents = $this->files->get($file);
        $class = "App\\{$context}\\{$context}ServiceProvider";

        // Asked of the array itself, resolved through whatever imports the
        // file has. Laravel lists these fully qualified, an earlier version of
        // this command listed them by short name behind an import, and either
        // way the entry names the same class. An import on its own does not
        // count as registered, and neither does a mention in a comment.
        $listed = SourceFile::at($file);

        if (! $listed->parsed()) {
            $this->components->twoColumnDetail('bootstrap/providers.php', '<fg=red>could not be read</>');
            $this->components->warn("bootstrap/providers.php does not parse. Register {$class} once it does.");

            return false;
        }

        if (in_array($class, $listed->returnedClasses(), true)) {
            $this->components->twoColumnDetail('bootstrap/providers.php', '<fg=yellow>already registered</>');

            return true;
        }

        // Written out in full rather than imported. A context called Billing
        // wants a BillingServiceProvider, and nothing stops this file already
        // importing one under that name from somewhere else; two imports
        // resolving to one short name is a fatal, and in this file of all
        // files it means nothing boots at all.
        $updated = $this->appendToList($contents, 'return [', "    \\{$class}::class,", '');

        // A list this could not find is a context nothing ever loads, and
        // silently: the provider file sits there looking finished. Say so.
        if ($updated === null) {
            $this->components->twoColumnDetail('bootstrap/providers.php', '<fg=red>could not be updated</>');
            $this->components->warn("Register {$class} in bootstrap/providers.php by hand.");

            return false;
        }

        $this->files->put($file, $updated);
        $this->components->twoColumnDetail('bootstrap/providers.php', '<fg=green>updated</>');

        return true;
    }
}
