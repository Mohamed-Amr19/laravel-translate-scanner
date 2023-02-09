<?php

namespace NawrasBukhariTranslationScanner;

use Illuminate\Support\ServiceProvider;

class TranslationScannerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \NawrasBukhariTranslationScanner\Command\TranslationHelperCommand::class,
            ]);
        }

        $this->mergeConfigFrom(__DIR__.'/config/translation-scanner.php', 'translation-scanner');

        $this->publishes([
            __DIR__.'/config/translation-scanner.php' => base_path('config/translation-scanner.php'),
        ], 'config');
    }
}
