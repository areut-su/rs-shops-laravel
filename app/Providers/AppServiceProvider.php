<?php

namespace App\Providers;

use App\Storefront\StorefrontApiClient;
use App\Storefront\StorefrontUpstream;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(StorefrontUpstream::class, function (): StorefrontUpstream {
            $baseUrl = (string) config('storefront.api_url');
            $token = (string) config('storefront.token');
            return new StorefrontApiClient($baseUrl, $token);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
