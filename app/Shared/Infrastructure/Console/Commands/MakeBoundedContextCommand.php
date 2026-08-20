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

        $path = app_path($context);
        $provider = $path."/{$context}ServiceProvider.php";

        // Running this again on a context already in use has to be safe: the
        // provider is the one file that accumulates hand-written wiring, and
        // rewriting it from the stub would drop every binding and migration
        // path while the aggregates stayed on disk.
        $providerExisted = $this->files->exists($provider);

        $this->writeProvider($context, $path);
        $this->writeRoutes($context, $path);
        // Before anything that edits a file this run did not create. A
        // provider left bound to a class that does not compile is loaded from
        // bootstrap/providers.php, so it takes the application and artisan
        // down: worse than the broken file it was wired to, and not something
        // deleting that file undoes.
        if (! $this->compiles()) {
            return self::FAILURE;
        }

        $registered = $this->registerProvider($context);

        $this->newLine();
        $this->components->info("Bounded context [{$context}] ".($providerExisted ? 'updated.' : 'created.'));
        $this->components->bulletList([
            "Add aggregates with: php artisan ldd:make:aggregate {$context} <Aggregate>",
        ]);

        // A route file the provider does not declare is never loaded, and the
        // only symptom is a 404.
        //
        // Asked of the declaration, not of what this run happened to write.
        // Keyed on the write, the reminder printed once, on the run that
        // created the file, and never again: paste one of the two lines and
        // every later run reports success over an api.php nothing loads, while
        // ldd:make:aggregate --api goes on printing routes for it. $routes was
        // the one declarative array no command read back.
        $undeclared = $this->undeclaredRoutes($context, $provider);

        if ($undeclared !== []) {
            $this->newLine();
            $this->line("  <fg=yellow>{$context}ServiceProvider does not declare these route files, so nothing loads them:</>");

            foreach ($undeclared as $kind) {
                $this->line("  <fg=gray>'{$kind}' => [__DIR__.'/Shared/Infrastructure/Http/Routes/{$kind}.php'],</>");
            }
        }

        // The files exist but nothing loads them, so a script chaining on this
        // command must not treat it as done.
        return $registered ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The route files that exist but are absent from the provider's $routes.
     *
     * @return list<string>
     */
    private function undeclaredRoutes(string $context, string $provider): array
    {
        $declared = SourceFile::at($provider);

        // Unreadable is not the same as declaring nothing. Reporting every
        // file as undeclared over a provider that does not parse names the
        // wrong problem, and the right one is loud already.
        if (! $declared->parsed()) {
            return [];
        }

        $paths = $declared->propertyStrings('routes');

        return array_values(array_filter(
            ['web', 'api'],
            fn (string $kind): bool => $this->files->exists(app_path("{$context}/Shared/Infrastructure/Http/Routes/{$kind}.php"))
                // Matched on the file a declaration names, not on the one
                // spelling the stub happens to write. Any other way of saying
                // the same path read as undeclared, and the command then said
                // "nothing loads it" about a file that loads.
                && ! array_any($paths, fn (string $path): bool => str_ends_with($path, "/{$kind}.php"))
        ));
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
