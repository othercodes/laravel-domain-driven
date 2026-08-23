<?php

/*
|--------------------------------------------------------------------------
| Bounded Context Discovery
|--------------------------------------------------------------------------
| Every rule below is derived from the app/ directory listing, so adding a
| bounded context or an aggregate is covered automatically. Nothing in this
| file should ever enumerate a context, a provider or an aggregate by hand.
|
| The Arch suite runs without booting the framework, so this uses plain
| filesystem calls rather than app_path() or the File facade.
*/

$appPath = dirname(__DIR__, 2).'/app';

/** Every directory under app/, e.g. IdentityAndAccess and Shared. */
$contexts = array_map('basename', glob($appPath.'/*', GLOB_ONLYDIR) ?: []);
sort($contexts);

/** The same list without the Shared foundation layer. */
$boundedContexts = array_values(array_diff($contexts, ['Shared']));

/** Turns IdentityAndAccess into "identity and access" for readable test names. */
$label = fn (string $context): string => strtolower(
    (string) preg_replace('/(?<!^)[A-Z]/', ' $0', $context)
);

/*
|--------------------------------------------------------------------------
| Presets
|--------------------------------------------------------------------------
*/

arch()->preset()->php();
arch()->preset()->security();

/*
|--------------------------------------------------------------------------
| Domain Layer
|--------------------------------------------------------------------------
| The Domain layer is the innermost layer. It must not depend on
| Application or Infrastructure layers. Only Shared Domain is
| allowed as a cross-cutting dependency.
|
| This rule has no exceptions. It used to exempt aggregate roots, because
| newFactory() pointed at a *Factory under Infrastructure; the factory now
| lives in Domain\Factories beside the aggregate it builds, so the pointer
| stays inside one layer and the exemption is gone. Do not add it back: an
| exemption list is how a rule stops being one.
*/

arch('domain does not depend on infrastructure')
    ->expect('App\*\*\Domain')
    ->not->toUse('App\*\*\Infrastructure');

arch('domain does not depend on application')
    ->expect('App\*\*\Domain')
    ->not->toUse('App\*\*\Application');

arch('domain contracts are interfaces')
    ->expect('App\*\*\Domain\Contracts')
    ->toBeInterfaces();

arch('domain events are final and implement event interface')
    ->expect('App\*\*\Domain\Events')
    ->toBeClasses()
    ->toBeFinal()
    ->toImplement('ComplexHeart\Domain\Contracts\Events\Event');

arch('domain exceptions extend exception')
    ->expect('App\*\*\Domain\Exceptions')
    ->toBeClasses()
    ->toExtend('Exception');

/*
 * AggregateFactory routes make() and create() through the aggregate's new(),
 * which is what assigns the identifier and records the creation event. A
 * factory written against Eloquent's Factory instead looks ordinary and
 * produces rows nothing ever announced, so extending it is not left to
 * whoever writes the next one.
 *
 * A factory is optional, and Pest throws when a pattern matches no directory.
 */
if ((glob($appPath.'/*/*/Domain/Factories', GLOB_ONLYDIR) ?: []) !== []) {
    arch('aggregate factories build through the aggregate')
        ->expect('App\*\*\Domain\Factories')
        ->toExtend('App\Shared\Domain\AggregateFactory');
}

arch('shared domain traits are traits')
    ->expect('App\Shared\Domain')
    ->traits()
    ->toBeTraits();

/*
|--------------------------------------------------------------------------
| Application Layer
|--------------------------------------------------------------------------
| The Application layer orchestrates use cases. It depends on the
| Domain layer but must not depend on Infrastructure.
*/

arch('application does not depend on infrastructure')
    ->expect('App\*\*\Application')
    ->not->toUse('App\*\*\Infrastructure');

/*
|--------------------------------------------------------------------------
| Infrastructure Layer
|--------------------------------------------------------------------------
| The Infrastructure layer provides adapters and implementations.
| It must not be used directly by the Domain layer.
*/

arch('controllers have controller suffix')
    ->expect('App\*\*\Infrastructure\Http\Controllers')
    ->classes()
    ->toHaveSuffix('Controller');

arch('middleware has handle method')
    ->expect('App\Shared\Infrastructure\Http\Middleware')
    ->toBeClasses()
    ->toHaveMethod('handle');

/*
|--------------------------------------------------------------------------
| Bounded Context Isolation
|--------------------------------------------------------------------------
| Each bounded context's Domain and Application layers may only depend
| on their own context or on Shared Domain. They must never reach into
| another BC's internals or into Shared Infrastructure.
|
| The Shared context is a foundation layer, so it must never reference
| any specific bounded context.
|
| Adding a bounded context needs no change here: its rules are generated
| below, and every existing context starts forbidding it at the same time.
*/

foreach ($boundedContexts as $context) {
    $forbidden = array_merge(
        ['App\Shared\Infrastructure', 'App\Shared\Application'],
        array_map(
            fn (string $other): string => 'App\\'.$other,
            array_values(array_diff($boundedContexts, [$context]))
        )
    );

    // A context need not have every layer: aggregates are free to skip
    // Application or Domain. Pest throws if the pattern matches no directory,
    // so only emit the rule for layers that actually exist.
    foreach (['Domain', 'Application'] as $layer) {
        if ((glob($appPath.'/'.$context.'/*/'.$layer, GLOB_ONLYDIR) ?: []) === []) {
            continue;
        }

        arch($label($context).' '.strtolower($layer).' only uses own context and shared domain')
            ->expect('App\\'.$context.'\*\\'.$layer)
            ->not->toUse($forbidden);
    }
}

/*
 * The same layering rule, for the one context the patterns above structurally
 * cannot reach. Every rule so far matches App\<Context>\<Aggregate>\<Layer>,
 * three segments, because a bounded context keeps its layers inside each
 * aggregate. Shared has no aggregates, so its layers sit two segments in and
 * no pattern ever saw them: a Domain file there could import Infrastructure
 * and the suite stayed green, while the comment at the top of this file says
 * the rule has no exceptions.
 *
 * app/Shared is the foundation every context builds on, so it is the last
 * place that should get to skip it.
 *
 * Emitted only for the layers that exist, like the loop above: Pest throws on
 * a pattern that matches nothing, and Shared has no Application layer today.
 */
foreach (['Application', 'Infrastructure'] as $outer) {
    if (! is_dir($appPath.'/Shared/Domain') || ! is_dir($appPath.'/Shared/'.$outer)) {
        continue;
    }

    arch('shared domain does not depend on '.strtolower($outer))
        ->expect('App\Shared\Domain')
        ->not->toUse('App\Shared\\'.$outer);
}

if (is_dir($appPath.'/Shared/Application') && is_dir($appPath.'/Shared/Infrastructure')) {
    arch('shared application does not depend on infrastructure')
        ->expect('App\Shared\Application')
        ->not->toUse('App\Shared\Infrastructure');
}

arch('shared context does not depend on any bounded context')
    ->expect('App\Shared')
    ->not->toUse(array_map(fn (string $c): string => 'App\\'.$c, $boundedContexts));

/*
 * One file under app/Shared is exempt from the rule above, and not by anyone's
 * decision: DatabaseSeeder declares the Database\Seeders namespace, which
 * composer.json maps into Shared so that `db:seed` resolves it with no
 * --class. The rule matches on namespace, so it never sees the file, and the
 * file does reach into every context on purpose, the way
 * bootstrap/providers.php does.
 *
 * The exemption is fine. Its being silent is not: any second file added there
 * would inherit it without anyone noticing. So the exception is pinned to the
 * one file that earns it, and a new one has to be argued for here first.
 */
test('the seeders directory in Shared holds only the root seeder', function () use ($appPath) {
    $files = array_map('basename', glob($appPath.'/Shared/Infrastructure/Persistence/Seeders/*.php') ?: []);

    expect($files)->toBe(['DatabaseSeeder.php']);
});

/*
|--------------------------------------------------------------------------
| Service Providers
|--------------------------------------------------------------------------
| Each bounded context wires its own dependencies via a service provider.
*/

arch('service providers extend the bounded context base provider')
    ->expect(array_map(
        fn (string $context): string => 'App\\'.$context.'\\'.$context.'ServiceProvider',
        $contexts
    ))
    ->toExtend('ComplexHeart\Infrastructure\Laravel\BoundedContextServiceProvider');

/*
 * Every provider declares the same wiring arrays the stub does. A provider
 * missing one does not fail loudly at boot: the generator finds no array to
 * append to, throws away the binding and the migration path it had already
 * built, and the aggregate ships with its repository unbound. Both providers
 * here were missing a different one.
 *
 * Read from the stub so the two cannot drift, and $routes is left out of it
 * because the stub emits that one conditionally.
 */
test('every service provider declares the wiring arrays the stub does', function () use ($contexts, $appPath) {
    preg_match_all(
        '/^\s*(?:public|protected)\s+array\s+\$(\w+)/m',
        (string) file_get_contents(dirname($appPath).'/stubs/bounded-context.provider.stub'),
        $matches
    );

    expect($matches[1])->not->toBeEmpty();

    foreach ($contexts as $context) {
        $provider = "{$appPath}/{$context}/{$context}ServiceProvider.php";

        // Asked before reading it. A directory under app/ with no provider is
        // reachable, a leaked test fixture being the usual way, and reading
        // one that is not there yields an empty string that then reports every
        // property as undeclared: the wrong problem, named confidently.
        expect(is_file($provider))->toBeTrue("app/{$context} has no {$context}ServiceProvider.");

        $source = (string) file_get_contents($provider);

        foreach ($matches[1] as $property) {
            expect($source)->toMatch(
                '/(?:public|protected)\s+array\s+\$'.$property.'\s*=/',
                "{$context}ServiceProvider does not declare \${$property}."
            );
        }
    }
});

/*
|--------------------------------------------------------------------------
| Code Quality
|--------------------------------------------------------------------------
*/

/*
| The application layer changes an aggregate through the aggregate, not around
| it. UpdateUserPassword and ResetUserPassword each did their own
| forceFill(['password' => ...])->save() while User::updatePassword() sat
| unused, which is the pattern this starter exists to fix rather than ship.
*/
test('the application layer does not fill an aggregate behind its own back', function () use ($appPath) {
    $offenders = array_values(array_filter(
        glob($appPath.'/*/*/Application/{,*/}*.php', GLOB_BRACE) ?: [],
        fn (string $file): bool => str_contains((string) file_get_contents($file), 'forceFill(')
    ));

    expect($offenders)->toBeEmpty();
});

arch('no debugging statements')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();

arch('no env calls outside config')
    ->expect('env')
    ->not->toBeUsed();
