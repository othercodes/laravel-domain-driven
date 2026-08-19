<?php

use App\Shared\Infrastructure\Console\Commands\ScaffoldCommand;
use Illuminate\Filesystem\Filesystem;

/*
 * withImport() is where the invariant lives, so this is where it is tested.
 *
 * It used to dedupe on the whole class name alone, which let it put
 * Theirs\Widget next to Ours\Widget: two imports answering to one short name,
 * which PHP rejects at compile time.
 *
 * This had been met once already, on the $commands array, and answered there
 * by writing the entry out in full and importing nothing. The reasoning went
 * into a comment beside that one append. Five withImport() calls went on
 * passing generated names into files whose contents are not ours to predict,
 * because the answer had been written down as a note rather than as code.
 *
 * Those five are gone, so the guard below has no reachable caller left: both
 * that remain write into files the same run generated. That is the point of
 * testing it here. It exists for the caller nobody has written yet.
 */
function scaffold_importer(): object
{
    return new class(new Filesystem) extends ScaffoldCommand
    {
        public function import(string $contents, string $class): ?string
        {
            return $this->withImport($contents, $class);
        }
    };
}

test('it refuses a class whose short name an import already answers to', function (string $existing) {
    $contents = "<?php\n\nnamespace App;\n\nuse {$existing};\n";

    expect(scaffold_importer()->import($contents, 'App\Ours\Widget'))->toBeNull();
})->with([
    'another namespace' => 'App\Theirs\Widget',
    'an alias' => 'App\Theirs\Gadget as Widget',

    // PHP resolves class names case-insensitively, and Str::studly leaves
    // inner case alone, so an aggregate asked for as `wIdget` reaches this
    // guard as `WIdget` and collides with a Widget already imported.
    'the same name in another case' => 'App\Theirs\WIdget',
]);

test('it adds the import when the short name is free', function (string $existing) {
    $contents = "<?php\n\nnamespace App;\n\nuse {$existing};\n";

    expect(scaffold_importer()->import($contents, 'App\Ours\Widget'))
        ->toContain('use App\Ours\Widget;');
})->with([
    // Aliased away, so Widget is free and Gadget is the name that is taken.
    'a class aliased to something else' => 'App\Theirs\Widget as Gadget',

    // Functions and constants have their own symbol tables, so neither can
    // collide with a class name. Refusing on them would block a legal import.
    'a function of the same name' => 'function App\Theirs\Widget',
    'a constant of the same name' => 'const App\Theirs\Widget',
]);

test('the same class asked for twice is left alone rather than refused', function () {
    // The exact-name check runs first: a caller re-importing what is already
    // there gets the file back unchanged, not a null it would report as a
    // failure to wire.
    $contents = "<?php\n\nnamespace App;\n\nuse App\Ours\Widget;\n";

    expect(scaffold_importer()->import($contents, 'App\Ours\Widget'))->toBe($contents);
});
