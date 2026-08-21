<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

/**
 * Class MakeEventHandlerCommand
 *
 * Adds a domain event handler, and says how to declare it.
 *
 * The declaration is the point. A handler that is not listed in $events is
 * never called, and nothing anywhere says so: it is the same silent failure
 * as an unbound repository or an unregistered migration path. It is printed
 * rather than appended because $events is keyed by the event class, and only
 * the developer can say what should happen when a key is already taken.
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

    protected $description = 'Create a domain event handler and say how to declare it';

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

        $event = $this->identifier(
            (string) ($this->option('event') ?: "{$target['aggregate']}Created"),
            'event'
        );

        if ($event === null) {
            return self::FAILURE;
        }

        // A handler needs an event the way an aggregate needs a context.
        // Generating the event from here would be creating upwards: it belongs
        // to the aggregate, and the command that owns the aggregate makes it.
        if (! $this->files->exists("{$target['path']}/Domain/Events/{$event}.php")) {
            $this->components->error("[{$event}] does not exist in [{$target['aggregate']}].");
            $this->components->bulletList([
                "Add it with: php artisan ldd:make:aggregate {$target['context']} {$target['aggregate']} --events",
            ]);

            return self::FAILURE;
        }

        $handler = "{$target['path']}/Application/EventHandlers/{$name}.php";

        $contents = $this->stub('event-handler', [
            '{{ context }}' => $target['context'],
            '{{ plural }}' => $target['plural'],
            '{{ name }}' => $name,
            '{{ event }}' => $event,
            '{{ handlerImplements }}' => $this->option('queued') ? ' implements ShouldQueue' : '',
        ]);

        if ($this->option('queued')) {
            $contents = $this->withImports($contents, 'Illuminate\Contracts\Queue\ShouldQueue');
        }

        $written = $this->put($handler, $contents);

        $handlerClass = "App\\{$target['context']}\\{$target['plural']}\\Application\\EventHandlers\\{$name}";
        $eventClass = "App\\{$target['context']}\\{$target['plural']}\\Domain\\Events\\{$event}";

        $this->wire(
            "[{$name}]",
            app_path("{$target['context']}/{$target['context']}ServiceProvider.php"),
            'events',
            ["\\{$eventClass}::class => \\{$handlerClass}::class,"]
        );

        // $events is keyed by the event, and a second entry under a key that
        // already has one leaves PHP keeping the last and dropping the first,
        // silently: the handler that was there stops running and nothing says
        // so. This repository ships one such array already, mapping
        // UserEmailUpdated to SendUserEmailVerification, so a handler for that
        // event reaches the case on the way out of the box.
        //
        // Said rather than checked. Which handler should survive is not a
        // generator's decision, and the array form below is the answer that
        // loses neither: ComplexHeart's bootEvents() listens for every entry
        // when the value is a list.
        $this->note(
            "If {$event} is already a key in \$events, do not add a second one: PHP keeps only the last. List both instead:",
            [
                "\\{$eventClass}::class => [",
                '    // the handler already mapped to it, first',
                "    \\{$handlerClass}::class,",
                '],',
            ]
        );

        // Nothing here rewrites a file, so a handler that was already on disk
        // is whatever it was: asking for --queued cannot make it queued.
        //
        // Hedged, because what decides this is the write, not the handler: a
        // re-run of the same --queued command reaches here over a handler the
        // first run already made queued, and nothing here reads it back to
        // find out. Saying it outright would be telling somebody to add what
        // is already there.
        //
        // Fully qualified and no import, like every other line this report
        // prints. This was the one place that printed a `use`, and pasting it
        // into a handler that already imports ShouldQueue is a fatal in a
        // class the provider lists in $events.
        //
        // The clause, not the declaration it goes on. Printing
        // `final readonly class X implements ...` was the last line in the
        // report telling anybody to replace existing text rather than add to
        // it, written without reading the file it names: a handler somebody
        // had given another interface, or had taken readonly off to hold
        // state, loses that when the line is followed.
        if (! $written && $this->option('queued')) {
            $this->note(
                "[{$name}] already existed and was left as it is. If it is not queued already, add to its class declaration, beside anything it already implements:",
                ['implements \\Illuminate\\Contracts\\Queue\\ShouldQueue']
            );
        }

        $this->newLine();
        $this->components->info("Event handler [{$name}] ".($written ? 'created' : 'left as it is')." in [{$target['context']}].");

        $this->report();

        return self::SUCCESS;
    }
}
