<?php

namespace App\Providers;

use App\Contracts\UploadStorage;
use App\Listeners\LogLogout;
use App\Listeners\LogSuccessfulLogin;
use App\Services\Uploads\LocalUploadStorage;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UploadStorage::class, LocalUploadStorage::class);
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
