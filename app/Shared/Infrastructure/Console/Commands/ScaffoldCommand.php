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
 * files without clobbering, and reporting what happened.
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
abstract class ScaffoldCommand extends Command
{
    public function __construct(protected readonly Filesystem $files)
    {
        parent::__construct();
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
     * Inserts an import in alphabetical order, so the result survives Pint's
     * ordered_imports fixer.
     */
    protected function withImport(string $contents, string $class): string
    {
        if (str_contains($contents, "use {$class};")) {
            return $contents;
        }

        preg_match_all('/^use (.+);$/m', $contents, $matches);

        $imports = [...$matches[1], $class];
        sort($imports);

        return (string) preg_replace(
            '/^use .+;\n(use .+;\n)*/m',
            implode('', array_map(fn (string $i): string => "use {$i};\n", $imports)),
            $contents,
            1
        );
    }
}
