<?php

use App\Shared\Infrastructure\Console\Commands\ScaffoldCommand;
use Illuminate\Filesystem\Filesystem;

/*
 * withImport() is where the invariant lives, so this is where it is tested.
 *
 * It used to dedupe on the whole class name alone, which let it put
 * Theirs\Widget next to Ours\Widget: two imports answering to one short name,
 * which PHP rejects at compile time. Five callers passed generated names into
 * files whose contents are not ours to predict, and the one that had already
 * caused the fatal was fixed on its own, in place, with the reasoning written
 * out as a comment beside it. The other four carried on.
 *
 * The three commands now write those entries fully qualified and import
 * nothing, so this guard is what stands between the next caller and the same
 * afternoon. Its only two remaining callers write into files this run
 * generated, so nothing else exercises it.
 */
function importer(): object
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

    expect(importer()->import($contents, 'App\Ours\Widget'))->toBeNull();
})->with([
    'another namespace' => 'App\Theirs\Widget',
    'an alias' => 'App\Theirs\Gadget as Widget',
]);

test('it adds the import when the short name is free', function (string $existing) {
    $contents = "<?php\n\nnamespace App;\n\nuse {$existing};\n";

    expect(importer()->import($contents, 'App\Ours\Widget'))
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

    expect(importer()->import($contents, 'App\Ours\Widget'))->toBe($contents);
});
