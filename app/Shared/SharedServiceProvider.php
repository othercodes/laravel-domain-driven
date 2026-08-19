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
use Inertia\Inertia;
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
        // `withS` or `withE` and picking. The two error macros flash different
        // words, `danger` for the banner and `error` for the alert, because
        // they are read by different things: a CSS palette and SweetAlert2,
        // whose icons are named success, error, warning and info. The name a
        // caller types is ours; the token is whatever the reader answers to.
        //
        // Inertia::flash rather than ->with(): a shared prop is written into
        // the browser's history state and merged back on a partial reload, so
        // a banner reappears on the Back button and a dialog re-opens on a
        // page that never flashed it. Flash data is stripped from the history
        // entry and dropped on the next response, which is what one-time
        // means. It also spreads over whatever is already flashed, so a
        // banner and an alert can ride the same redirect.

        RedirectResponse::macro('withSuccessBanner', function (string $message) {
            /** @var RedirectResponse $this */
            Inertia::flash('banner', Banner::of('success', $message));

            return $this;
        });

        RedirectResponse::macro('withInfoBanner', function (string $message) {
            /** @var RedirectResponse $this */
            Inertia::flash('banner', Banner::of('info', $message));

            return $this;
        });

        RedirectResponse::macro('withWarningBanner', function (string $message) {
            /** @var RedirectResponse $this */
            Inertia::flash('banner', Banner::of('warning', $message));

            return $this;
        });

        RedirectResponse::macro('withErrorBanner', function (string $message) {
            /** @var RedirectResponse $this */
            Inertia::flash('banner', Banner::of('danger', $message));

            return $this;
        });

        RedirectResponse::macro('withSuccessAlert', function (string $message, string $title = '') {
            /** @var RedirectResponse $this */
            Inertia::flash('alert', Alert::of('success', $message, $title));

            return $this;
        });

        RedirectResponse::macro('withInfoAlert', function (string $message, string $title = '') {
            /** @var RedirectResponse $this */
            Inertia::flash('alert', Alert::of('info', $message, $title));

            return $this;
        });

        RedirectResponse::macro('withWarningAlert', function (string $message, string $title = '') {
            /** @var RedirectResponse $this */
            Inertia::flash('alert', Alert::of('warning', $message, $title));

            return $this;
        });

        RedirectResponse::macro('withErrorAlert', function (string $message, string $title = '') {
            /** @var RedirectResponse $this */
            Inertia::flash('alert', Alert::of('error', $message, $title));

            return $this;
        });
    }
}
