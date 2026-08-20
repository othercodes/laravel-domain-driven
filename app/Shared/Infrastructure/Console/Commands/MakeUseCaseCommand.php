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

        // Asked of the stubs as this run would render them, before anything
        // is written. The names that can collide are the ones interpolated
        // in, so an unrendered stub has nothing useful to compare.
        //
        // Both use-case stubs import the aggregate and its repository, so a
        // use case named Invoice lands under App\...\Domain\Invoice.
        if ($this->refusesCollidingNames('use-case*', [
            '{{ context }}' => $target['context'],
            '{{ aggregate }}' => $target['aggregate'],
            '{{ plural }}' => $target['plural'],
            '{{ variable }}' => Str::camel($target['aggregate']),
            '{{ name }}' => $name,
        ])) {
            return self::FAILURE;
        }
        $stub = $this->option('publishes') ? 'use-case.publishes' : 'use-case';

        // put() reports whether it wrote, and saying "created" over the top of
        // its own "exists, skipped" is how a run that did nothing reads like
        // one that did.
        $written = $this->put("{$target['path']}/Application/{$name}.php", $this->stub($stub, [
            '{{ context }}' => $target['context'],
            '{{ aggregate }}' => $target['aggregate'],
            '{{ plural }}' => $target['plural'],
            '{{ variable }}' => Str::camel($target['aggregate']),
            '{{ name }}' => $name,
        ]));

        $this->newLine();
        $this->components->info("Use case [{$name}] ".($written ? 'created' : 'left as it is')." in [{$target['context']}].");

        $this->printPublishHint($target);

        return self::SUCCESS;
    }

    /**
     * @param  array{context: string, aggregate: string, plural: string, path: string}  $target
     */
    private function printPublishHint(array $target): void
    {
        if ($this->option('publishes')) {
            return;
        }

        // Only worth saying when the aggregate has events to lose: what the
        // domain already holds decides this, not the flag.
        if (! $this->files->isDirectory("{$target['path']}/Domain/Events")) {
            return;
        }

        // And only until something publishes them. This is the same question
        // ldd:make:aggregate asks before printing its own version, and the two
        // have no business disagreeing about the answer, so both ask it through
        // the one helper rather than each keeping a copy that can drift.
        $answer = $this->publishesDomainEvents("{$target['path']}/Application");

        if ($answer['publishes']) {
            return;
        }

        $this->newLine();

        // Any of them may be the use case that publishes, so advising one
        // would be asking for what is already written.
        if ($answer['unreadable'] !== []) {
            $this->line("  <fg=yellow>Could not tell whether anything publishes {$target['aggregate']}'s events: these do not parse:</>");

            foreach ($answer['unreadable'] as $file) {
                $this->line("  <fg=gray>{$file}</>");
            }

            return;
        }

        $this->line("  <fg=yellow>{$target['aggregate']} records domain events. A use case that creates or changes one</>");
        $this->line('  <fg=yellow>should publish them, which is what --publishes scaffolds.</>');
    }
}
