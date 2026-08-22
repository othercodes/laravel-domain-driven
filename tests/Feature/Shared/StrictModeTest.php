<?php

use Illuminate\Database\Eloquent\Model;

/*
 * Strict mode is a development tool. Left on in production, a query somebody
 * narrowed with select() is a 500 rather than a slow page: Inertia
 * materialises $appends, so User::query()->select('id', 'name') is enough to
 * reach one. SharedServiceProvider passes the environment, which is Laravel's
 * own recommendation, and this holds the half that can be observed from here.
 */
test('strict mode is on outside production', function () {
    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(Model::preventsAccessingMissingAttributes())->toBeTrue()
        ->and(Model::preventsSilentlyDiscardingAttributes())->toBeTrue();
});
