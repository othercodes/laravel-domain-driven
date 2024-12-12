<?php

namespace App\Shared;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Class SharedServiceProvider
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
class SharedServiceProvider extends ServiceProvider
{
    public array $bindings = [];

    private array $events = [];

    private array $commands = [];

    public function boot(): void
    {
        Model::shouldBeStrict();

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
}
