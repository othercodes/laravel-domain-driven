<?php

use Composer\Autoload\ClassLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Whether a generated PHP file actually parses.
 *
 * The scaffolding commands rewrite service providers, and a malformed one is
 * registered in bootstrap/providers.php: asserting on the file's contents
 * would not notice that the application can no longer boot.
 */
function php_parses(string $path): bool
{
    exec('php -l '.escapeshellarg($path).' 2>&1', $output, $status);

    return $status === 0;
}

/**
 * Runs the ldd:make:* commands against a copy of the application, and returns
 * where bootstrap/providers.php now lives.
 *
 * These tests used to generate into the real app/ and rewrite the tracked
 * bootstrap/providers.php. Two things follow from that, and both are why this
 * exists: a test run leaves a versioned file modified, and under --parallel the
 * arch suite is scanning app/ while a generator test is creating and deleting
 * directories inside it, so it fails on a path that vanished mid-iteration.
 *
 * The copy is keyed on the pid, so every worker gets its own, and it is a copy
 * rather than an empty directory because four tests are about the application
 * as shipped: the migrations Shared owns, and the APITokens aggregate.
 *
 * Registered with composer as a second path for the App\ namespace, so a
 * generated class still autoloads and class_exists() can tell a missing import
 * from a present one.
 */
function scaffold_into_a_copy_of_the_app(): string
{
    $root = sys_get_temp_dir().'/ldd-tests-'.getmypid();

    File::deleteDirectory($root);
    File::ensureDirectoryExists($root);
    File::copyDirectory(base_path('app'), $root.'/app');
    File::copy(base_path('bootstrap/providers.php'), $providers = $root.'/providers.php');

    // Resolved before the path moves, and cached on the container from then
    // on: getNamespace() matches app_path() against composer.json's psr-4
    // entry, which a copy outside the project cannot satisfy, and Laravel's
    // own make:mail and make:job ask for it.
    app()->getNamespace();

    app()->useAppPath($root.'/app');
    app()->useBootstrapPath($root);

    /** @var ClassLoader $loader */
    $loader = require base_path('vendor/autoload.php');

    // Once per process. The path is the same every time, and addPsr4 appends
    // without looking, so calling it per test left the App\ prefix holding one
    // duplicate entry per test for every class lookup to walk.
    if (! in_array($root.'/app', $loader->getPrefixesPsr4()['App\\'] ?? [], true)) {
        $loader->addPsr4('App\\', $root.'/app');
    }

    return $providers;
}
