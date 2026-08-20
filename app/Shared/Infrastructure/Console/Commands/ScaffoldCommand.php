<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

use App\Shared\Infrastructure\Console\Support\SourceFile;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Class ScaffoldCommand
 *
 * Shared plumbing for the ldd:make:* commands: rendering stubs, writing
 * files without clobbering, and editing PHP sources the way Pint expects.
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

    public function __construct(protected readonly Filesystem $files)
    {
        parent::__construct();
    }

    /**
     * Normalises a name and rejects anything that is not a legal PHP
     * identifier. Without this the command happily writes `class 2024Report`,
     * which is a parse error that takes the whole application down.
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
     * Resolves an existing aggregate from the names it was given.
     *
     * The names are passed in rather than read here: a base class cannot
     * know which arguments its subclasses declare, and reaching for one that
     * does not exist is a runtime error waiting for whoever adds the next
     * command.
     *
     * Returns null, having said why, when either is missing: these commands
     * add to an aggregate that exists, they never create one.
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

        // A directory alone is not a context: it also has to be wired by a
        // provider named after it, which is what these commands edit.
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

    protected function relative(string $path): string
    {
        return Str::after($path, base_path().DIRECTORY_SEPARATOR);
    }

    /**
     * Whether anything in an aggregate's application layer publishes its
     * recorded domain events.
     *
     * Two commands ask this before advising a use case that publishes, and
     * they have no business disagreeing about the answer. They did, once: one
     * learned to skip a file it could not read and the other went on treating
     * unreadable as "does not publish", which is how you get told to write a
     * use case that is sitting in the file nobody could parse. One
     * implementation is the only thing that keeps the two honest.
     *
     * Files that do not parse are returned rather than counted either way,
     * since any of them may be the use case that publishes.
     *
     * @return array{publishes: bool, unreadable: list<string>}
     */
    protected function publishesDomainEvents(string $applicationPath): array
    {
        $unreadable = [];

        if (! $this->files->isDirectory($applicationPath)) {
            return ['publishes' => false, 'unreadable' => []];
        }

        foreach ($this->files->allFiles($applicationPath) as $file) {
            $source = SourceFile::at($file->getPathname());

            if (! $source->parsed()) {
                $unreadable[] = $this->relative($file->getPathname());

                continue;
            }

            if ($source->calls('publishDomainEvents')) {
                return ['publishes' => true, 'unreadable' => []];
            }
        }

        return ['publishes' => false, 'unreadable' => $unreadable];
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
     * The caller wires what exists, not what it happened to create, so that a
     * re-run still registers a class an earlier one wrote but never wired.
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

    /**
     * Refuses, loudly, a run that would generate a file holding two things
     * under one short name.
     *
     * PHP rejects that at compile time whether the two are two imports or an
     * import and the class the file declares, and a generated file reaches it
     * from either side: an aggregate called Model lands under Eloquent's
     * import, a handler called InvoiceCreated lands under its own event, and
     * an aggregate called Collection puts Illuminate's Collection next to the
     * one in its own domain.
     *
     * The stubs are rendered and read, rather than their names being listed
     * here. stubs/ is the one thing in this repository meant to be edited, so
     * a list would describe it as it was the day it was written, and an
     * earlier version of this guard compared the bare argument against
     * unrendered imports: it caught Model, whose stub declares the aggregate
     * name as written, and waved through Json and Aggregate, whose stubs
     * declare a name derived from it.
     *
     * Every stub the command can render is asked, including ones a given run
     * would not reach. Refusing an aggregate called Request whether or not
     * --web was passed costs a name nobody should want in a Laravel
     * application, and a guard that answers differently depending on the flags
     * is a worse thing to reason about.
     *
     * @param  array<string, string>  $replacements  the run's stub substitutions
     * @param  list<string>  $also  imports the command adds after rendering
     */
    protected function refusesCollidingNames(string $glob, array $replacements, array $also = []): bool
    {
        foreach ($this->files->glob(base_path("stubs/{$glob}.stub")) ?: [] as $path) {
            $rendered = str_replace(array_keys($replacements), array_values($replacements), $this->files->get($path));

            preg_match_all('/^use (.+);$/m', $rendered, $imports);
            preg_match_all('/^(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $rendered, $declared);

            // An import the command adds that the stub already carries is the
            // same import, not a collision. Without this, a stub edited to
            // bake in HasFactory would refuse every aggregate name there is,
            // and advise picking another one.
            $added = array_diff(array_map('trim', $also), array_map('trim', $imports[1]));

            // A migration declares an anonymous class, and the route stubs
            // declare nothing at all.
            $names = [...$imports[1], ...$added, ...$declared[1]];

            foreach ($names as $index => $name) {
                $earlier = array_slice($names, 0, $index);

                if (! $this->shortNameTaken($earlier, $name)) {
                    continue;
                }

                $this->components->error('Generating ['.basename($path).'] would put two things under the name ['.class_basename(trim($name)).'].');
                $this->components->bulletList([
                    'PHP refuses to compile a file that does, whether they are two imports or an import and the class itself.',
                    'Pick another name.',
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Whether one of the existing imports already answers to the short name
     * the given class would introduce.
     *
     * @param  list<string>  $imports  the text of each `use` line, without `use` or the semicolon
     */
    protected function shortNameTaken(array $imports, string $class): bool
    {
        $shortName = function (string $import): string {
            $import = trim($import);

            // `use Ours\Widget as TheirWidget;` occupies TheirWidget, not Widget.
            if (preg_match('/\s+as\s+(\S+)$/i', $import, $alias) === 1) {
                return $alias[1];
            }

            return substr((string) strrchr('\\'.$import, '\\'), 1);
        };

        foreach ($imports as $import) {
            // Function and constant imports live in their own symbol tables,
            // so neither can collide with a class name.
            if (preg_match('/^(function|const)\s/i', trim($import)) === 1) {
                continue;
            }

            // strcasecmp, not ===. PHP resolves class names case-insensitively,
            // so `use Ours\HAsFactory;` beside Eloquent's HasFactory is the
            // same fatal, and Str::studly leaves inner case alone: an aggregate
            // asked for as `hAs` arrives here as `HAs`.
            if (strcasecmp($shortName($import), $shortName($class)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inserts an import in the order Pint's ordered_imports fixer expects.
     *
     * Returns null when the import cannot be placed, so the caller reports a
     * failure instead of leaving an unqualified reference behind.
     */
    protected function withImport(string $contents, string $class): ?string
    {
        if (str_contains($contents, "use {$class};")) {
            return $contents;
        }

        preg_match_all('/^use (.+);$/m', $contents, $matches);

        // Two imports resolving to one short name is a compile-time fatal, and
        // it takes down every file that loads this one. The check above
        // compares whole names, so on its own it is happy to put
        // Ours\Widget next to Theirs\Widget.
        //
        // The refusal belongs here rather than in each caller. It was written
        // out once before, as a comment on the one append that had already
        // caused the fatal, and the other five call sites carried on importing
        // into files whose contents are not ours to predict. An invariant kept
        // by whoever remembers it is not kept.
        if ($this->shortNameTaken($matches[1], $class)) {
            return null;
        }

        $imports = [...$matches[1], $class];

        // Pint compares the namespace separator as a space, so a byte-wise
        // sort() would order InvoicesLines before Invoices and fail the lint.
        usort($imports, fn (string $a, string $b): int => str_replace('\\', ' ', $a) <=> str_replace('\\', ' ', $b));

        $block = implode('', array_map(fn (string $i): string => "use {$i};\n", $imports));

        // preg_replace would read backslash sequences in the replacement as
        // backreferences, silently mangling namespaces such as \20.
        if ($matches[1] !== []) {
            // Every `use` line is collected above, so every one of them has to
            // go: rewriting only the first contiguous run left the imports of
            // any later group duplicated, and duplicate imports are fatal.
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
            return preg_replace("/\n{3,}/", "\n\n", (string) $result);
        }

        // No imports yet: open a block after the namespace declaration, or
        // after the opening tag when the file has none.
        if (preg_match('/^namespace .+;\n/m', $contents) === 1) {
            return preg_replace_callback('/^namespace .+;\n/m', fn (array $m): string => $m[0]."\n".$block, $contents, 1);
        }

        if (str_starts_with($contents, "<?php\n")) {
            return preg_replace_callback('/^<\?php\n/', fn (array $m): string => $m[0]."\n".$block, $contents, 1);
        }

        return null;
    }

    /**
     * Appends an entry to a list literal, whatever shape it is written in.
     *
     * $open is the literal that opens the list, e.g. `return [` or
     * `array $bindings = [`, and $indent the indentation of its closing
     * bracket.
     *
     * Returns null when the list cannot be found, so the caller reports a
     * failure instead of writing the file back unchanged and calling it done.
     */
    protected function appendToList(string $contents, string $open, string $entry, string $indent = '    '): ?string
    {
        if (str_contains($contents, $open.'];')) {
            return str_replace($open.'];', $open."\n{$entry}\n{$indent}];", $contents);
        }

        // Already populated and spread over several lines.
        $multiline = preg_replace_callback(
            '/('.preg_quote($open, '/').'\n)(.*?)(^'.preg_quote($indent, '/').'\];)/ms',
            fn (array $m): string => $m[1].$m[2].$entry."\n".$m[3],
            $contents,
            1,
            $count
        );

        if ($count > 0) {
            return $multiline;
        }

        // Declared inline, e.g. `public array $bindings = [Foo::class => Bar::class];`
        $inline = preg_replace_callback(
            '/'.preg_quote($open, '/').'(.*?)\];/s',
            function (array $m) use ($open, $entry, $indent): string {
                $body = $m[1];

                // A body that is only whitespace or comments holds no elements:
                // emitting it as one produces `[,` and a parse error.
                $hasElements = trim((string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $body)) !== '';

                if (! $hasElements) {
                    return $open.rtrim($body)."\n{$entry}\n{$indent}];";
                }

                $existing = rtrim(trim($body), ',');

                return $open."\n{$indent}    {$existing},\n{$entry}\n{$indent}];";
            },
            $contents,
            1,
            $count
        );

        return $count > 0 ? $inline : null;
    }
}
