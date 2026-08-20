<?php

declare(strict_types=1);

namespace NovaHorizons\SdkGenerator\Laravel;

use Illuminate\Support\ServiceProvider;

final class SdkGeneratorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/sdk-generator.php' => config_path('sdk-generator.php'),
            ], 'sdk-generator-config');

            $this->commands([GenerateSdkCommand::class]);
        }

        $this->mergeConfigFrom(__DIR__.'/../../config/sdk-generator.php', 'sdk-generator');
    }
}
