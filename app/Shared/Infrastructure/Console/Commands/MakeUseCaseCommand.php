<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

use App\Shared\Infrastructure\Console\Support\SourceFile;
use Illuminate\Support\Str;

/**
 * Class MakeUseCaseCommand
 *
 * Adds a use case to an aggregate's Application layer.
 *
 * Nothing needs wiring: the container resolves the constructor by itself.
 * What this saves is the shape — a final readonly class that reaches the
 * domain only through the repository contract — and, with --publishes, the
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
        // have no business disagreeing about the answer.
        $application = "{$target['path']}/Application";

        if ($this->files->isDirectory($application)) {
            foreach ($this->files->allFiles($application) as $file) {
                if (SourceFile::at($file->getPathname())->calls('publishDomainEvents')) {
                    return;
                }
            }
        }

        $this->newLine();
        $this->line("  <fg=yellow>{$target['aggregate']} records domain events. A use case that creates or changes one</>");
        $this->line('  <fg=yellow>should publish them, which is what --publishes scaffolds.</>');
    }
}
