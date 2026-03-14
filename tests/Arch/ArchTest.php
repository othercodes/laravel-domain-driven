<?php

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
| Known exception: User.php references UserFactory from Infrastructure
| due to Laravel's HasFactory trait convention. This is a framework
| coupling that cannot be avoided without breaking Eloquent factories.
*/

arch('domain does not depend on infrastructure')
    ->expect('App\*\*\Domain')
    ->not->toUse('App\*\*\Infrastructure')
    ->ignoring('App\IdentityAndAccess\Users\Domain\User');

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
    ->toImplement('App\Shared\Domain\Contracts\ServiceBus\Event');

arch('domain exceptions extend exception')
    ->expect('App\*\*\Domain\Exceptions')
    ->toBeClasses()
    ->toExtend('Exception');

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
| The Shared context is a foundation layer — it must never reference
| any specific bounded context.
|
| When adding a new bounded context, add its isolation rules below.
*/

arch('identity and access domain only uses own context and shared domain')
    ->expect('App\IdentityAndAccess\*\Domain')
    ->not->toUse('App\Shared\Infrastructure')
    ->not->toUse('App\Shared\Application');

arch('identity and access application only uses own context and shared domain')
    ->expect('App\IdentityAndAccess\*\Application')
    ->not->toUse('App\Shared\Infrastructure')
    ->not->toUse('App\Shared\Application');

// Add new bounded context isolation rules here following the same pattern:
// arch('{context} domain only uses own context and shared domain')
//     ->expect('App\{Context}\*\Domain')
//     ->not->toUse('App\Shared\Infrastructure')
//     ->not->toUse('App\Shared\Application')
//     ->not->toUse('App\IdentityAndAccess');
//
// arch('{context} application only uses own context and shared domain')
//     ->expect('App\{Context}\*\Application')
//     ->not->toUse('App\Shared\Infrastructure')
//     ->not->toUse('App\Shared\Application')
//     ->not->toUse('App\IdentityAndAccess');

arch('shared context does not depend on any bounded context')
    ->expect('App\Shared')
    ->not->toUse('App\IdentityAndAccess');

/*
|--------------------------------------------------------------------------
| Service Providers
|--------------------------------------------------------------------------
| Each bounded context wires its own dependencies via a service provider.
*/

arch('service providers extend base provider')
    ->expect([
        'App\IdentityAndAccess\IdentityAndAccessServiceProvider',
        'App\Shared\SharedServiceProvider',
    ])
    ->toExtend('Illuminate\Support\ServiceProvider');

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
