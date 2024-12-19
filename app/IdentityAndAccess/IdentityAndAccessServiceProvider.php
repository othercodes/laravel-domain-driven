<?php

namespace App\IdentityAndAccess;

use App\IdentityAndAccess\Users\Application\CreateUser;
use App\IdentityAndAccess\Users\Application\EventHandlers\SendUserEmailVerification;
use App\IdentityAndAccess\Users\Application\ResetUserPassword;
use App\IdentityAndAccess\Users\Application\UpdateUserPassword;
use App\IdentityAndAccess\Users\Application\UpdateUserProfileInformation;
use App\IdentityAndAccess\Users\Domain\Contracts\UserRepository;
use App\IdentityAndAccess\Users\Domain\Events\UserEmailUpdated;
use App\IdentityAndAccess\Users\Infrastructure\Persistence\EloquentUserRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

/**
 * Class IdentityAndAccessServiceProvider
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class IdentityAndAccessServiceProvider extends ServiceProvider
{
    public array $bindings = [
        UserRepository::class => EloquentUserRepository::class,
    ];

    public array $events = [
        UserEmailUpdated::class => SendUserEmailVerification::class,
    ];

    private array $commands = [];

    public function register(): void {}

    public function boot(): void
    {
        $this->bootFortify();

        $this->bootEvents();
        $this->bootCommands();
    }

    private function bootEvents(): void
    {
        foreach ($this->events as $event => $listener) {
            Event::listen($event, $listener);
        }
    }

    private function bootCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }
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
