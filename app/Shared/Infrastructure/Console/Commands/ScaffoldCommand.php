<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ScaffoldCommand
 *
 * Shared plumbing for the ldd:make:* commands: rendering stubs, writing
 * files that are not there yet, and collecting the wiring report.
 *
 * These commands create files. They do not edit them. The one exception is
 * ldd:make:bounded-context registering its provider through Laravel's own
 * ServiceProvider::addProviderToBootstrapFile(), and that is the framework's
 * code, not ours.
 *
 * Everything else that would have to be edited is printed instead: register
 * this class, in this file, in this property, and here is how it reads. That
 * costs a paste and buys back the whole class of failure that came from
 * writing into files whose contents are not ours to predict.
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
abstract class ScaffoldCommand extends Command
{
    /**
     * The Shared context is the application's foundation layer, never a
     * bounded context that can own aggregates.
     */
    protected const SHARED_CONTEXT = 'Shared';

    /**
     * Words PHP will not accept as a class name. A plain character-class
     * check lets `Case`, `Match` and `List` through, and those are ordinary
     * domain nouns that would generate a file which does not parse.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'abstract', 'and', 'array', 'as', 'bool', 'break', 'callable', 'case', 'catch', 'class',
        'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif',
        'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum',
        'eval', 'exit', 'extends', 'false', 'final', 'finally', 'float', 'fn', 'for', 'foreach',
        'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once', 'instanceof',
        'insteadof', 'int', 'interface', 'isset', 'iterable', 'list', 'match', 'mixed', 'namespace',
        'never', 'new', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public',
        'readonly', 'require', 'require_once', 'return', 'self', 'static', 'string', 'switch',
        'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield',
    ];

    /**
     * The blocks to print once every file is written.
     *
     * @var list<array{heading: string, lines: list<string>}>
     */
    private array $report = [];

    /**
     * Artisan reuses the command instance between calls in one process, so
     * per-run state kept on a property carries one run into the next. Symfony
     * calls this before every run.
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->report = [];
    }

    public function __construct(protected readonly Filesystem $files)
    {
        parent::__construct();
    }

    /**
     * Normalises a name and rejects anything that is not a legal PHP
     * identifier. Without this the command happily writes `class 2024Report`,
     * which is a parse error in a file somebody then has to find.
     */
    protected function identifier(string $value, string $label): ?string
    {
        $studly = Str::studly($value);

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $studly)) {
            $this->components->error("The {$label} name must be a valid PHP identifier, [{$value}] is not.");

            return null;
        }

        if (in_array(strtolower($studly), self::RESERVED, true)) {
            $this->components->error("[{$studly}] is a PHP reserved word and cannot be used as a class name.");

            return null;
        }

        if ($studly !== $value) {
            $this->components->info("Using [{$studly}] as the {$label} name.");
        }

        return $studly;
    }

    /**
     * Whether a name this command was given is a name it refuses.
     *
     * strcasecmp, not ===. Every name compared here ends up as a PHP class
     * name or as a namespace segment, and PHP resolves both without case, so
     * `boundedcontext` and `bOundedContext` declare the same class as
     * `BoundedContext`. Str::studly only upper-cases the first letter of each
     * word and leaves the rest as written, so the value arriving here keeps
     * whatever case it was typed in.
     *
     * It lives on the base class rather than at each comparison because the
     * same rule was answered once before, in a comment beside one call site,
     * while the other call sites went on comparing with ===. An invariant kept
     * by whoever remembers it is not kept.
     */
    protected function refuses(string $name, string $reserved): bool
    {
        return strcasecmp($name, $reserved) === 0;
    }

    /**
     * Resolves an existing aggregate from the names it was given.
     *
     * Prerequisites cascade and nothing is ever generated upwards: an event
     * handler needs an aggregate, an aggregate needs a bounded context, and a
     * command asked for one whose prerequisite is missing fails saying which
     * command creates it.
     *
     * The names are passed in rather than read here: a base class cannot know
     * which arguments its subclasses declare.
     *
     * @return array{context: string, aggregate: string, plural: string, path: string}|null
     */
    protected function target(string $contextName, string $aggregateName): ?array
    {
        $context = $this->identifier($contextName, 'bounded context');
        $aggregate = $this->identifier($aggregateName, 'aggregate');

        if ($context === null || $aggregate === null) {
            return null;
        }

        // A directory alone is not a context: it also has to have a provider
        // named after it, which is the file the report tells you to edit.
        if (! $this->files->exists(app_path("{$context}/{$context}ServiceProvider.php"))) {
            $this->components->error("The bounded context [{$context}] does not exist.");
            $this->components->bulletList([
                "Create it with: php artisan ldd:make:bounded-context {$context}",
            ]);

            return null;
        }

        $plural = Str::plural($aggregate);
        $path = app_path("{$context}/{$plural}");

        if (! $this->files->isDirectory($path)) {
            $this->components->error("The aggregate [{$aggregate}] does not exist in [{$context}].");
            $this->components->bulletList([
                "Create it with: php artisan ldd:make:aggregate {$context} {$aggregate}",
            ]);

            return null;
        }

        return compact('context', 'aggregate', 'plural', 'path');
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function stub(string $name, array $replacements): string
    {
        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $this->files->get(base_path("stubs/{$name}.stub"))
        );
    }

    /**
     * Writes a file, never over one that is already there.
     *
     * These commands scaffold, they do not edit: anything on disk may have
     * been worked on, and a migration may already have been applied. Running
     * one again fills in what is missing and reports what it left alone.
     */
    protected function put(string $path, string $contents): bool
    {
        if ($this->files->exists($path)) {
            $this->components->twoColumnDetail($this->relative($path), '<fg=yellow>exists, skipped</>');

            return false;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);

        $this->components->twoColumnDetail($this->relative($path), '<fg=green>created</>');

        return true;
    }

    /**
     * Adds imports to a stub this run has just rendered.
     *
     * Only ever called on our own output, which is what makes it safe to
     * rewrite the whole import block. It does not check whether a short name
     * is already taken, because the file being written is a new one: a
     * generated class that collides with something its own stub imports
     * produces a file that does not compile, sitting on its own in the
     * aggregate's directory, which is exactly what `make:model Model` leaves
     * behind in Laravel. Nothing else loads it, so nothing else breaks.
     */
    protected function withImports(string $contents, string ...$classes): string
    {
        preg_match_all('/^use (.+);$/m', $contents, $matches);

        $imports = array_values(array_unique([...$matches[1], ...$classes]));

        // Pint compares the namespace separator as a space, so a byte-wise
        // sort() would order InvoicesLines before Invoices and fail the lint.
        usort($imports, fn (string $a, string $b): int => str_replace('\\', ' ', $a) <=> str_replace('\\', ' ', $b));

        $block = implode('', array_map(fn (string $i): string => "use {$i};\n", $imports));

        // Every `use` line is replaced, not just the first contiguous run:
        // rewriting one group would leave the imports of any later group
        // duplicated, and duplicate imports are fatal. preg_replace_callback
        // rather than preg_replace, whose replacement would read a namespace
        // such as \20 as a backreference.
        $written = false;

        $result = preg_replace_callback(
            '/^use .+;\n/m',
            function () use ($block, &$written): string {
                if ($written) {
                    return '';
                }

                $written = true;

                return $block;
            },
            $contents
        );

        // Collapse the blank lines left where a later group used to be.
        return (string) preg_replace("/\n{3,}/", "\n\n", (string) $result);
    }

    /**
     * Queues a block for the report printed after every file is written.
     *
     * @param  list<string>  $lines
     */
    protected function note(string $heading, array $lines): void
    {
        $this->report[] = ['heading' => $heading, 'lines' => $lines];
    }

    /**
     * Queues the registration of entries in an array a file declares.
     *
     * Everything printed is written out in full and never imported. The file
     * it goes into keeps an import list of its own, and adding to that list
     * is how two imports end up resolving to one short name, which is a
     * compile-time fatal. Fully qualified, a pasted line cannot collide with
     * anything.
     *
     * The entries only, never the property declaration around them. Printing
     * `public array $bindings = [` and its closing bracket made the block look
     * like something to paste whole, and every file this points at declares
     * the property already: the stub ships all four, DatabaseSeeder ships
     * $seeders. Pasted as printed, that is a redeclared property, which is a
     * fatal in a provider bootstrap/providers.php loads. The heading says
     * which property, which is the part that cannot be read off the entry.
     *
     * @param  list<string>  $entries
     */
    protected function wire(string $subject, string $file, string $property, array $entries): void
    {
        $this->note(
            "Register {$subject} in {$this->relative($file)}, in \${$property}:",
            $entries
        );
    }

    /**
     * Prints everything queued, and empties the queue.
     *
     * Called once, after the files are on disk, so the run reads as what was
     * created followed by what is left to do rather than the two interleaved.
     */
    protected function report(): void
    {
        if ($this->report === []) {
            return;
        }

        // Said once, over everything below, because it is true of every block
        // and always will be: these commands do not read the files they name.
        // Without it each heading reads as a statement about that file, and
        // the headings are written by whoever adds the next call site. An
        // imperative that cannot be checked has to say so where it is printed,
        // not in the head of the person who wrote it.
        $this->newLine();
        $this->line('  <options=bold>Nothing below was wired.</>');
        $this->line('  <fg=gray>These files were neither read nor written by this run, so check each entry is</>');
        $this->line('  <fg=gray>not already there before pasting it.</>');

        foreach ($this->report as $block) {
            $this->newLine();
            $this->line("  <options=bold>{$block['heading']}</>");
            $this->newLine();

            foreach ($block['lines'] as $line) {
                $this->line("  <fg=gray>{$line}</>");
            }
        }

        $this->report = [];
    }

    protected function relative(string $path): string
    {
        return Str::after($path, base_path().DIRECTORY_SEPARATOR);
    }

    /**
     * Writes a class through one of Laravel's own make:* generators.
     *
     * The placement is ours and the stub is theirs. Passing the fully
     * qualified name is what puts the file outside the generator's default
     * directory: each one prepends its own namespace, App\Mail and the rest,
     * to any name that does not already start at the root.
     *
     * Returns the class that is on disk afterwards, whether this call wrote it
     * or found it already there, and null only when it could not be written.
     * The report names what exists, not what this run happened to create, so
     * that a re-run still tells you to declare a class an earlier one wrote.
     *
     * @param  array<string, mixed>  $options
     */
    protected function generate(string $generator, string $fqcn, array $options = []): ?string
    {
        $path = app_path(str_replace('\\', '/', Str::after($fqcn, 'App\\')).'.php');

        // Laravel's generators report "already exists" and still exit 0, so
        // this is ours to check: these commands only ever add, and a re-run
        // has to say "skipped" rather than print their error as if it failed.
        if ($this->files->exists($path)) {
            $this->components->twoColumnDetail($this->relative($path), '<fg=yellow>exists, skipped</>');

            return $fqcn;
        }

        $this->callSilently($generator, ['name' => $fqcn] + $options);

        return $this->wrote($generator, $path) ? $fqcn : null;
    }

    /**
     * Whether the generator left a file behind, reported either way.
     *
     * Split out because the check is what makes generate() honest: silencing
     * the generator keeps the output uniform and swallows any reason it
     * declined, so the file is the only thing left worth believing. Static
     * analysis reads two exists() calls in one scope as one answer, which is
     * exactly the assumption being avoided here.
     */
    private function wrote(string $generator, string $path): bool
    {
        if (! $this->files->exists($path)) {
            $this->components->twoColumnDetail($this->relative($path), "<fg=red>{$generator} wrote nothing</>");

            return false;
        }

        $this->components->twoColumnDetail($this->relative($path), '<fg=green>created</>');

        return true;
    }
}
