<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Commands;

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

    protected function put(string $path, string $contents): bool
    {
        if ($this->files->exists($path) && ! $this->option('force')) {
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
}
