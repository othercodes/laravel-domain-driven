<?php

namespace App\IdentityAndAccess;

use App\IdentityAndAccess\APITokens\Domain\APIToken;
use App\IdentityAndAccess\Users\Application\CreateUser;
use App\IdentityAndAccess\Users\Application\EventHandlers\SendUserEmailVerification;
use App\IdentityAndAccess\Users\Application\ResetUserPassword;
use App\IdentityAndAccess\Users\Application\UpdateUserPassword;
use App\IdentityAndAccess\Users\Application\UpdateUserProfileInformation;
use App\IdentityAndAccess\Users\Domain\Contracts\UserRepository;
use App\IdentityAndAccess\Users\Domain\Events\UserEmailUpdated;
use App\IdentityAndAccess\Users\Infrastructure\Persistence\EloquentUserRepository;
use ComplexHeart\Infrastructure\Laravel\BoundedContextServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;

/**
 * Class IdentityAndAccessServiceProvider
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class IdentityAndAccessServiceProvider extends BoundedContextServiceProvider
{
    public array $bindings = [
        UserRepository::class => EloquentUserRepository::class,
    ];

    protected array $events = [
        UserEmailUpdated::class => SendUserEmailVerification::class,
    ];

    protected array $migrations = [
        __DIR__.'/Users/Infrastructure/Persistence/Migrations',
        __DIR__.'/APITokens/Infrastructure/Persistence/Migrations',
    ];

    public function boot(): void
    {
        parent::boot();

        // Sanctum's own model points at personal_access_tokens, whose
        // tokenable_id is a bigint and cannot hold a user uuid.
        Sanctum::usePersonalAccessTokenModel(APIToken::class);

        $this->bootFortify();
    }

    private function bootFortify(): void
    {
        // Configure the view prefix.
        Fortify::viewPrefix('auth.');

        // Configure the application user cases.
        Fortify::createUsersUsing(CreateUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Configure the routes.
        Fortify::loginView(fn () => Inertia::render('Auth/Login', [
            'status' => session('status'),
        ]));
        Fortify::registerView(fn () => Inertia::render('Auth/Register'));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->input('email'),
            'token' => $request->route('token'),
        ]));
        Fortify::verifyEmailView(fn () => Inertia::render('Auth/VerifyEmail', [
            'status' => session('status'),
        ]));
        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));
        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));

        // Rate limiter for authentication view
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip())));
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)
            ->by($request->session()->get('login.id')));
    }
}
