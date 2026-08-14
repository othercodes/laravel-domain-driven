<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// The api middleware group is applied by the service provider, but the URI
// prefix is not. BoundedContextServiceProvider::bootRoutes() only groups by
// middleware, so each context declares its own prefix.
Route::prefix('api')->middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user())->name('user');
});
