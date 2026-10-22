<?php

namespace App\Providers;

use App\Contracts\UploadStorage;
use App\Listeners\LogLogout;
use App\Listeners\LogSuccessfulLogin;
use App\Services\Uploads\UploadStorageFactory;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The byte store is scoped rather than bound: one store per request (or
        // per queue job) keeps the state of the fault injector meaningful, and
        // it is released before the next job starts.
        $this->app->scoped(UploadStorageFactory::class, fn (Application $app) => new UploadStorageFactory($app));

        $this->app->scoped(
            UploadStorage::class,
            fn (Application $app) => $app->make(UploadStorageFactory::class)->make(),
        );
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
        Event::listen(Login::class, LogSuccessfulLogin::class);
        Event::listen(Logout::class, LogLogout::class);
    }
}
