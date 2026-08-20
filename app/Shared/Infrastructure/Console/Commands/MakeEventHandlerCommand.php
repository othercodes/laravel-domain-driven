<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

use App\Shared\Infrastructure\Console\Support\SourceFile;

/**
 * Class MakeEventHandlerCommand
 *
 * Adds a domain event handler and declares it in the context's provider.
 *
 * The declaration is the point. A handler that is not listed in $events is
 * never called, and nothing anywhere says so: it is the same silent failure
 * as an unbound repository or an unregistered migration path.
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class MakeEventHandlerCommand extends ScaffoldCommand
{
    protected $signature = 'ldd:make:event-handler
        {context : The bounded context it belongs to, e.g. Billing}
        {aggregate : The aggregate whose event it handles, e.g. Invoice}
        {name : The handler name, e.g. NotifyAccounting}
        {--event= : The domain event, defaults to <Aggregate>Created}
        {--queued : Handle the event on the queue}';

    protected $description = 'Create a domain event handler and declare it in the provider';

    public function handle(): int
    {
        $target = $this->target(
            (string) $this->argument('context'),
            (string) $this->argument('aggregate'),
        );
        $name = $this->identifier((string) $this->argument('name'), 'handler');

        if ($target === null || $name === null) {
            return self::FAILURE;
        }

        // Checked before anything is written, and against the stubs rather
        // than a list kept here, so an edited stub cannot make it stale.
        if ($this->refusesCollidingName($name, 'event-handler', ['Illuminate\Contracts\Queue\ShouldQueue'])) {
            return self::FAILURE;
        }

        $event = $this->identifier(
            (string) ($this->option('event') ?: "{$target['aggregate']}Created"),
            'event'
        );

        if ($event === null) {
            return self::FAILURE;
        }

        if (! $this->files->exists("{$target['path']}/Domain/Events/{$event}.php")) {
            $this->components->error("[{$event}] does not exist in [{$target['aggregate']}].");
            $this->components->bulletList([
                "Add it with: php artisan ldd:make:aggregate {$target['context']} {$target['aggregate']} --events",
            ]);

            return self::FAILURE;
        }

        $provider = app_path("{$target['context']}/{$target['context']}ServiceProvider.php");
        $contents = $this->files->get($provider);

        // $events is keyed by the event class, and a duplicate key in an array
        // literal silently drops the earlier one. Checked before anything is
        // written, so a refusal leaves no orphan handler behind.
        //
        // Asked of the declared keys: a mapping somebody parked behind // is
        // not a mapping, and blocked the command outright when this was a
        // search over the text.
        $eventClass = "App\\{$target['context']}\\{$target['plural']}\\Domain\\Events\\{$event}";
        $declared = SourceFile::at($provider);

        // Unreadable is not the same as empty. Treating it as empty here would
        // add a second entry under a key that already has one, which is the
        // exact silent loss this guard exists to prevent.
        if (! $declared->parsed()) {
            $this->components->error("{$target['context']}ServiceProvider does not parse, so its \$events cannot be read.");
            $this->components->bulletList([
                'Fix the provider first: a second entry under one key drops the first without a word.',
            ]);

            return self::FAILURE;
        }

        if (in_array($eventClass, $declared->propertyKeys('events'), true)) {
            $this->components->error("[{$event}] is already handled in {$target['context']}ServiceProvider.");
            $this->components->bulletList([
                'Declare the second handler by hand: PHP would drop one of two entries under the same key.',
            ]);

            return self::FAILURE;
        }

        $handler = "{$target['path']}/Application/EventHandlers/{$name}.php";

        // put() reports whether it wrote. Adding the import to a handler this
        // run did not create leaves it on a class that never gains the
        // implements clause, which is an unused import and a lie.
        $written = $this->put($handler, $this->stub('event-handler', [
            '{{ context }}' => $target['context'],
            '{{ plural }}' => $target['plural'],
            '{{ name }}' => $name,
            '{{ event }}' => $event,
            '{{ handlerImplements }}' => $this->option('queued') ? ' implements ShouldQueue' : '',
        ]));

        if ($this->option('queued')) {
            $written ? $this->queueTheHandler($handler) : $this->printQueuedHint($handler, $name);
        }

        $wired = $this->wireProvider($provider, $contents, $target, $name, $event);

        $this->newLine();
        $this->components->info("Event handler [{$name}] ".($written ? 'created' : 'declared')." in [{$target['context']}].");

        // The handler exists but nothing calls it, so a script chaining on
        // this command must not treat it as done.
        return $wired ? self::SUCCESS : self::FAILURE;
    }

    /**
     * What the handler already declares decides this: asking for --queued on
     * one that is already queued has nothing to say.
     */
    private function printQueuedHint(string $file, string $name): void
    {
        $handler = SourceFile::at($file);

        // Asked before concluding anything from what the file does not hold. A
        // file that does not parse, and one holding no such class at all, both say
        // "implements nothing", and telling somebody to add an interface to a
        // class that is not there names the wrong problem.
        if (! $handler->declaresClass($name)) {
            $this->components->warn("{$name} already existed but could not be read from {$this->relative($file)}, so whether it is queued is unknown.");

            return;
        }

        if ($handler->implementsInterface('Illuminate\\Contracts\\Queue\\ShouldQueue')) {
            return;
        }

        $this->components->warn("{$name} already existed and was left as it is. Add `implements ShouldQueue` to it by hand.");
    }

    private function queueTheHandler(string $file): void
    {
        $imported = $this->withImport($this->files->get($file), 'Illuminate\Contracts\Queue\ShouldQueue');

        if ($imported === null) {
            $this->components->warn('Import Illuminate\Contracts\Queue\ShouldQueue in the handler by hand.');

            return;
        }

        $this->files->put($file, $imported);
    }

    /**
     * @param  array{context: string, aggregate: string, plural: string, path: string}  $target
     */
    private function wireProvider(string $provider, string $contents, array $target, string $name, string $event): bool
    {
        $handlerClass = "App\\{$target['context']}\\{$target['plural']}\\Application\\EventHandlers\\{$name}";
        $eventClass = "App\\{$target['context']}\\{$target['plural']}\\Domain\\Events\\{$event}";

        // Written out in full rather than imported under their short names.
        // Handler and event names are free text, so either may collide with
        // something the provider already imports, and two imports resolving to
        // one short name is a fatal that takes the application down from a
        // command that just printed green. $commands and $migrations avoid it
        // the same way.
        $updated = $this->appendToList(
            $contents,
            'array $events = [',
            "        \\{$eventClass}::class => \\{$handlerClass}::class,"
        );

        if ($updated === null) {
            $this->components->twoColumnDetail($this->relative($provider), '<fg=red>could not be wired</>');
            $this->components->warn("Map {$event}::class to {$name}::class in {$target['context']}ServiceProvider::\$events by hand.");

            return false;
        }

        $this->files->put($provider, $updated);
        $this->components->twoColumnDetail($this->relative($provider), '<fg=green>wired</>');

        return true;
    }
}
