<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

use Illuminate\Support\ServiceProvider;

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
 * This is the one command that registers anything. Everything a context can
 * ever hold is declared in the provider this run writes, so a context comes
 * out fully wired and every later command only adds to it.
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

    /**
     * The one name a context cannot have.
     *
     * The provider stub imports exactly one class, the base provider it
     * extends, and declares <Context>ServiceProvider beside it. A context
     * called BoundedContext therefore produces `class BoundedContextServiceProvider
     * extends BoundedContextServiceProvider` under its own import, which is a
     * fatal, in the one file that goes straight into bootstrap/providers.php:
     * the application and artisan both stop booting, and deleting the file
     * does not undo it.
     *
     * No other generated name can do this, because no other command registers
     * what it writes. This is a constant rather than something derived from
     * the stub: a check that reads the stub is a prediction, and predictions
     * are what this whole design got rid of. The stub having one import is an
     * invariant a test holds.
     */
    private const RESERVED_CONTEXT = 'BoundedContext';

    public function handle(): int
    {
        $context = $this->identifier((string) $this->argument('name'), 'bounded context');

        if ($context === null) {
            return self::FAILURE;
        }

        if ($this->refuses($context, self::SHARED_CONTEXT)) {
            $this->components->error('[Shared] already exists as the application foundation layer.');

            return self::FAILURE;
        }

        if ($this->refuses($context, self::RESERVED_CONTEXT)) {
            $this->components->error("[{$context}] would produce a provider that extends itself, and it is registered on boot.");
            $this->components->bulletList([
                'Pick another name for the context.',
            ]);

            return self::FAILURE;
        }

        $path = app_path($context);
        $provider = $path."/{$context}ServiceProvider.php";

        // Running this again on a context already in use has to be safe: the
        // provider is the one file that accumulates hand-written wiring, and
        // rewriting it from the stub would drop every binding and migration
        // path while the aggregates stayed on disk.
        $providerWritten = $this->put($provider, $this->stub('bounded-context.provider', [
            '{{ context }}' => $context,
            '{{ routes }}' => $this->routesProperty($context),
        ]));

        // Read from the options, not from what put() wrote. A run that finds
        // both the provider and the route file already there wrote nothing and
        // used to say nothing, which is the state that most needs saying: the
        // file may well have been created by hand, following the convention
        // the stub itself prints as a comment, and then it is declared by
        // nothing and every route in it 404s. Whether the entry is already in
        // $routes is not something this command can know, and the standing
        // line above the report says exactly that.
        $routes = array_values(array_filter(
            ['web', 'api'],
            fn (string $kind): bool => (bool) $this->option($kind)
        ));

        $this->writeRoutes($context, $path);

        // The stub renders $routes from the same options, so a provider this
        // run wrote already declares whatever route file it wrote beside it.
        // A provider that was already there does not.
        if (! $providerWritten && $routes !== []) {
            $this->wire(
                'the route files',
                $provider,
                'routes',
                'protected array $routes = [',
                array_map(
                    fn (string $kind): string => "'{$kind}' => [__DIR__.'/Shared/Infrastructure/Http/Routes/{$kind}.php'],",
                    $routes
                )
            );
        }

        // The single exception to these commands not editing what they did not
        // create, and it is Laravel's own code doing it: the same helper
        // make:provider uses. It merges, uniques and sorts, so re-running is
        // free, and it writes the class fully qualified with no import.
        $registered = ServiceProvider::addProviderToBootstrapFile("App\\{$context}\\{$context}ServiceProvider");

        $this->components->twoColumnDetail(
            'bootstrap/providers.php',
            $registered ? '<fg=green>registered</>' : '<fg=red>could not be updated</>'
        );

        $this->newLine();
        $this->components->info("Bounded context [{$context}] ".($providerWritten ? 'created.' : 'updated.'));
        $this->components->bulletList([
            "Add aggregates with: php artisan ldd:make:aggregate {$context} <Aggregate>",
        ]);

        if (! $registered) {
            $this->components->warn("Register App\\{$context}\\{$context}ServiceProvider in bootstrap/providers.php by hand.");
        }

        $this->report();

        // The files exist but nothing loads them, so a script chaining on this
        // command must not treat it as done.
        return $registered ? self::SUCCESS : self::FAILURE;
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
}
