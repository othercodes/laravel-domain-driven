<?php

namespace App\Shared;

use App\Shared\Domain\Contracts\ServiceBus\EventBus;
use App\Shared\Infrastructure\Http\Middleware\HandleInertiaRequests;
use App\Shared\Infrastructure\Http\Middleware\ShareInertiaData;
use App\Shared\Infrastructure\ServiceBus\IlluminateEventBus;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\PasswordUpdatedViaController;

/**
 * Class SharedServiceProvider
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class SharedServiceProvider extends ServiceProvider
{
    public array $bindings = [
        EventBus::class => IlluminateEventBus::class,
    ];

    private array $events = [];

    private array $commands = [];

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        Model::shouldBeStrict();

        $this->extendRedirectResponses();
        $this->bootInertia();

        $this->bootEvents();
        $this->bootCommands();

        Vite::prefetch(concurrency: 3);
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

    protected function extendRedirectResponses(): void
    {
        RedirectResponse::macro('withInfoBanner', function ($message) {
            /** @var RedirectResponse $this */
            return $this->with('flash.banner', [
                'style' => 'info',
                'message' => $message,
            ]);
        });

        RedirectResponse::macro('withSuccessBanner', function ($message) {
            /** @var RedirectResponse $this */
            return $this->with('flash.banner', [
                'style' => 'success',
                'message' => $message,
            ]);
        });

        RedirectResponse::macro('withWarningBanner', function ($message) {
            /** @var RedirectResponse $this */
            return $this->with('flash.banner', [
                'style' => 'warning',
                'message' => $message,
            ]);
        });

        RedirectResponse::macro('withDangerBanner', function ($message) {
            /** @var RedirectResponse $this */
            return $this->with('flash.banner', [
                'style' => 'danger',
                'message' => $message,
            ]);
        });

        RedirectResponse::macro('withSuccessAlert', function (string $message, string $title = 'Done!') {
            /** @var RedirectResponse $this */
            return $this->with('flash.alert', [
                'style' => 'success',
                'title' => $title,
                'text' => $message,
            ]);
        });

        RedirectResponse::macro('withErrorAlert', function (string $message, string $title = 'Oops...') {
            /** @var RedirectResponse $this */
            return $this->with('flash.alert', [
                'style' => 'success',
                'title' => $title,
                'text' => $message,
            ]);
        });
    }

    /**
     * @throws BindingResolutionException
     */
    protected function bootInertia(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $kernel->appendMiddlewareToGroup('web', ShareInertiaData::class);
        $kernel->appendToMiddlewarePriority(ShareInertiaData::class);

        if (class_exists(HandleInertiaRequests::class)) {
            $kernel->appendToMiddlewarePriority(HandleInertiaRequests::class);
        }

        Event::listen(function (PasswordUpdatedViaController $event) {
            if (request()->hasSession()) {
                request()->session()->put(['password_hash_sanctum' => Auth::user()->getAuthPassword()]);
            }
        });
    }
}
