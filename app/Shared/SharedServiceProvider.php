<?php

namespace App\Shared;

use App\Shared\Infrastructure\Console\Commands\MakeAggregateCommand;
use App\Shared\Infrastructure\Console\Commands\MakeBoundedContextCommand;
use App\Shared\Infrastructure\Console\Commands\MakeEventHandlerCommand;
use App\Shared\Infrastructure\Console\Commands\MakeUseCaseCommand;
use ComplexHeart\Domain\Contracts\Events\EventBus;
use ComplexHeart\Infrastructure\Laravel\BoundedContextServiceProvider;
use ComplexHeart\Infrastructure\Laravel\ServiceBus\IlluminateEventBus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Laravel\Fortify\Events\PasswordUpdatedViaController;

/**
 * Class SharedServiceProvider
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class SharedServiceProvider extends BoundedContextServiceProvider
{
    public array $bindings = [
        EventBus::class => IlluminateEventBus::class,
    ];

    protected array $migrations = [
        __DIR__.'/Infrastructure/Persistence/Migrations',
    ];

    protected array $routes = [
        'web' => [__DIR__.'/Infrastructure/Http/Routes/web.php'],
    ];

    protected array $commands = [
        MakeAggregateCommand::class,
        MakeBoundedContextCommand::class,
        MakeEventHandlerCommand::class,
        MakeUseCaseCommand::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Model::shouldBeStrict();

        $this->extendRedirectResponses();

        Event::listen(function (PasswordUpdatedViaController $event) {
            if (request()->hasSession()) {
                request()->session()->put(['password_hash_sanctum' => Auth::user()->getAuthPassword()]);
            }
        });

        Vite::prefetch(concurrency: 3);
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
                'style' => 'danger',
                'title' => $title,
                'text' => $message,
            ]);
        });
    }
}
