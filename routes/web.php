<?php

use App\IdentityAndAccess\Users\Infrastructure\Http\Controllers\DeleteUserController;
use App\IdentityAndAccess\Users\Infrastructure\Http\Controllers\OtherBrowserSessionsController;
use App\IdentityAndAccess\Users\Infrastructure\Http\Controllers\UserProfileController;
use App\IdentityAndAccess\Users\Infrastructure\Http\Controllers\UserProfilePhotoController;
use App\Shared\Infrastructure\Http\Controllers\PrivacyPolicyController;
use App\Shared\Infrastructure\Http\Controllers\TermsOfServiceController;
use App\Shared\Infrastructure\Http\Middleware\AuthenticateSession;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome', [
    'canLogin' => Route::has('login'),
    'canRegister' => Route::has('register'),
    'laravelVersion' => Application::VERSION,
    'phpVersion' => PHP_VERSION,
]));

Route::middleware(['auth:sanctum', AuthenticateSession::class, 'verified'])->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
});

Route::group(['domain' => null, 'prefix' => null], function () {
    Route::group(['middleware' => ['web']], function () {
        Route::get('/terms-of-service', [TermsOfServiceController::class, 'show'])->name('terms.show');
        Route::get('/privacy-policy', [PrivacyPolicyController::class, 'show'])->name('policy.show');

        Route::group(['middleware' => ['auth:sanctum', AuthenticateSession::class]], function () {
            Route::get('/user/profile', [UserProfileController::class, 'show'])->name('profile.show');
            Route::delete('/user/other-browser-sessions', [OtherBrowserSessionsController::class, 'destroy'])->name('other-browser-sessions.destroy');
            Route::delete('/user/profile-photo', [UserProfilePhotoController::class, 'destroy'])->name('current-user-photo.destroy');
            Route::delete('/user', [DeleteUserController::class, 'destroy'])->name('current-user.destroy');

            Route::group(['middleware' => 'verified'], function () {});
        });
    });
});
