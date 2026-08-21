<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

use Illuminate\Support\Str;

/**
 * Class MakeUseCaseCommand
 *
 * Adds a use case to an aggregate's Application layer.
 *
 * Nothing needs wiring: the container resolves the constructor by itself.
 * What this saves is the shape, a final readonly class that reaches the
 * domain only through the repository contract, and, with --publishes, the
 * one idiom that is easy to leave out and impossible to notice missing.
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class MakeUseCaseCommand extends ScaffoldCommand
{
    protected $signature = 'ldd:make:use-case
        {context : The bounded context it belongs to, e.g. Billing}
        {aggregate : The aggregate it operates on, e.g. Invoice}
        {name : The use case name, e.g. CreateInvoice}
        {--publishes : Save through a transaction and publish the recorded domain events}';

    protected $description = 'Create a use case in an aggregate\'s application layer';

    public function handle(): int
    {
        $target = $this->target(
            (string) $this->argument('context'),
            (string) $this->argument('aggregate'),
        );
        $name = $this->identifier((string) $this->argument('name'), 'use case');

        if ($target === null || $name === null) {
            return self::FAILURE;
        }

        $variable = Str::camel($target['aggregate']);
        $stub = $this->option('publishes') ? 'use-case.publishes' : 'use-case';

        // put() reports whether it wrote, and saying "created" over the top of
        // its own "exists, skipped" is how a run that did nothing reads like
        // one that did.
        $written = $this->put("{$target['path']}/Application/{$name}.php", $this->stub($stub, [
            '{{ context }}' => $target['context'],
            '{{ aggregate }}' => $target['aggregate'],
            '{{ plural }}' => $target['plural'],
            '{{ variable }}' => $variable,
            '{{ name }}' => $name,
        ]));

        $this->newLine();
        $this->components->info("Use case [{$name}] ".($written ? 'created' : 'left as it is')." in [{$target['context']}].");

        // Only worth saying when the aggregate has events to lose: what the
        // domain already holds decides this, not the flag and not what this
        // run wrote. Keyed on the write, the one path that reaches here was
        // silent: ldd:make:aggregate --events prints the very command to run,
        // and running it over a use case that already exists reports "left as
        // it is" and says nothing, while the events still go nowhere.
        if ($this->files->isDirectory("{$target['path']}/Domain/Events")) {
            $lines = [
                '// add to the constructor: private \ComplexHeart\Domain\Contracts\Events\EventBus $eventBus,',
                '',
                // In full, and inside the transaction: a listener that throws
                // must not leave the aggregate persisted, and neither name is
                // imported by a use case this run did not write.
                '\Illuminate\Support\Facades\DB::transaction(function () {',
                "    \$this->repository->save(\${$variable});",
                "    \${$variable}->publishDomainEvents(\$this->eventBus);",
                '});',
            ];

            if (! $this->option('publishes')) {
                $this->note(
                    "{$target['aggregate']} records domain events. A use case that creates or changes one has to publish them:",
                    [...$lines, '', '// --publishes scaffolds exactly this.']
                );
            } elseif (! $written) {
                // The flag was passed and the file was already there, so the
                // rendered stub was discarded. Passing the flag the tool asked
                // for used to leave you with less guidance than omitting it.
                $this->note(
                    "[{$name}] already existed and was left as it is, so --publishes changed nothing. If it does not already publish:",
                    $lines
                );
            }
        }

        $this->report();

        return self::SUCCESS;
    }
}
