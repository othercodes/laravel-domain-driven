<?php

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

Route::get('/terms-of-service', [TermsOfServiceController::class, 'show'])->name('terms.show');
Route::get('/privacy-policy', [PrivacyPolicyController::class, 'show'])->name('policy.show');

Route::middleware(['auth:sanctum', AuthenticateSession::class, 'verified'])->group(function () {
    Route::get('/dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
});
