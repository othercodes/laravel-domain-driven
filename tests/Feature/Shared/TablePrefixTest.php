<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/*
 * The starter shipped ten tables named as if the application had one owner,
 * while its own write-up said otherwise. Nothing noticed, because nothing
 * looked: every test asked whether a query worked, and an unprefixed table
 * answers those exactly as well as a prefixed one.
 *
 * This is what looks. It reads the schema that actually exists rather than the
 * migrations that were meant to build it, and it derives the allowed prefixes
 * from the contexts on disk, so a context added tomorrow is covered tomorrow.
 */

/**
 * @return array<string, string>
 */
function declared_table_prefixes(): array
{
    $prefixes = [];

    foreach (File::directories(app_path()) as $directory) {
        $context = basename($directory);
        $provider = "App\\{$context}\\{$context}ServiceProvider";

        if (! class_exists($provider)) {
            continue;
        }

        $prefix = (new ReflectionClass($provider))->getDefaultProperties()['tablePrefix'] ?? '';

        if (is_string($prefix) && $prefix !== '') {
            $prefixes[$context] = $prefix;
        }
    }

    return $prefixes;
}

test('every bounded context declares the code its tables carry', function () {
    $contexts = array_map('basename', File::directories(app_path()));
    $declared = declared_table_prefixes();

    // Asked separately from the schema check below, because a context with no
    // prefix at all passes that one by having no tables yet, and then stamps
    // nothing on the first aggregate somebody adds to it.
    expect(array_keys($declared))->toEqualCanonicalizing($contexts);
});

test('every table carries the code of the context that owns it', function () {
    $prefixes = array_values(declared_table_prefixes());

    expect($prefixes)->not->toBeEmpty();

    $pattern = '/^('.implode('|', array_map('preg_quote', $prefixes)).')_/';

    // Scoped to the database this process is on. getTableListing() answers for
    // every schema the connection can see, which under --parallel is one
    // testing_test_N per worker plus whatever else lives on the server.
    $tables = Schema::getTableListing(schema: DB::getDatabaseName(), schemaQualified: false);

    expect($tables)->not->toBeEmpty();

    $unowned = array_values(array_filter(
        $tables,
        fn (string $table): bool => preg_match($pattern, $table) !== 1
    ));

    expect($unowned)->toBe([]);
});
