<?php

declare(strict_types=1);

namespace Vaslv\Brevity\Laravel;

use Illuminate\Support\ServiceProvider;
use Vaslv\Brevity\BrevityClient;

class BrevityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/brevity.php', 'brevity');

        $this->app->singleton('brevity.client', function ($app) {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('brevity', []);

            return new BrevityClient($config);
        });

        $this->app->alias('brevity.client', BrevityClient::class);
    }

    public function boot(): void
    {
        $configPath = method_exists($this->app, 'configPath')
            ? $this->app->configPath('brevity.php')
            : $this->app->basePath('config/brevity.php');

        $this->publishes(
            [
                __DIR__.'/../../config/brevity.php' => $configPath,
            ],
            'brevity-config'
        );
    }
}
