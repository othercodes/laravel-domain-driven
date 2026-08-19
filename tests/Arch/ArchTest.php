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
|--------------------------------------------------------------------------
| Code Quality
|--------------------------------------------------------------------------
*/

arch('no debugging statements')
    ->expect(['dd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();

arch('no env calls outside config')
    ->expect('env')
    ->not->toBeUsed();
