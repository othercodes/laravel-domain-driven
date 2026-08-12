<?php

use App\IdentityAndAccess\Users\Infrastructure\Http\Controllers\DeleteUserController;
use App\IdentityAndAccess\Users\Infrastructure\Http\Controllers\OtherBrowserSessionsController;
use App\IdentityAndAccess\Users\Infrastructure\Http\Controllers\UserProfileController;
use App\IdentityAndAccess\Users\Infrastructure\Http\Controllers\UserProfilePhotoController;
use App\Shared\Infrastructure\Http\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', AuthenticateSession::class])->group(function () {
    Route::get('/user/profile', [UserProfileController::class, 'show'])->name('profile.show');
    Route::delete('/user/other-browser-sessions', [OtherBrowserSessionsController::class, 'destroy'])->name('other-browser-sessions.destroy');
    Route::delete('/user/profile-photo', [UserProfilePhotoController::class, 'destroy'])->name('current-user-photo.destroy');
    Route::delete('/user', [DeleteUserController::class, 'destroy'])->name('current-user.destroy');
});
