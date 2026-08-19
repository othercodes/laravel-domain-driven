<?php

namespace App\Shared;

use App\Shared\Infrastructure\Console\Commands\MakeAggregateCommand;
use App\Shared\Infrastructure\Console\Commands\MakeBoundedContextCommand;
use App\Shared\Infrastructure\Console\Commands\MakeEventHandlerCommand;
use App\Shared\Infrastructure\Console\Commands\MakeUseCaseCommand;
use App\Shared\Infrastructure\Http\Alert;
use App\Shared\Infrastructure\Http\Banner;
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
        // Four names apiece, so reaching for one is a matter of autocompleting
        // `withS` or `withE` and picking. Both flash through a dotted key so a
        // redirect can carry a banner and an alert at once: flashing `flash`
        // whole replaces it, and one of the two would quietly go missing.
        RedirectResponse::macro('withSuccessBanner', function (string $message) {
            /** @var RedirectResponse $this */
            return $this->with('flash.banner', Banner::of('success', $message));
        });

        RedirectResponse::macro('withInfoBanner', function (string $message) {
            /** @var RedirectResponse $this */
            return $this->with('flash.banner', Banner::of('info', $message));
        });

        RedirectResponse::macro('withWarningBanner', function (string $message) {
            /** @var RedirectResponse $this */
            return $this->with('flash.banner', Banner::of('warning', $message));
        });

        RedirectResponse::macro('withErrorBanner', function (string $message) {
            /** @var RedirectResponse $this */
            return $this->with('flash.banner', Banner::of('error', $message));
        });

        RedirectResponse::macro('withSuccessAlert', function (string $message, string $title = '') {
            /** @var RedirectResponse $this */
            return $this->with('flash.alert', Alert::of('success', $message, $title));
        });

        RedirectResponse::macro('withInfoAlert', function (string $message, string $title = '') {
            /** @var RedirectResponse $this */
            return $this->with('flash.alert', Alert::of('info', $message, $title));
        });

        RedirectResponse::macro('withWarningAlert', function (string $message, string $title = '') {
            /** @var RedirectResponse $this */
            return $this->with('flash.alert', Alert::of('warning', $message, $title));
        });

        RedirectResponse::macro('withErrorAlert', function (string $message, string $title = '') {
            /** @var RedirectResponse $this */
            return $this->with('flash.alert', Alert::of('error', $message, $title));
        });
    }
}
