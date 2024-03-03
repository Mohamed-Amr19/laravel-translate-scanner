<?php

namespace NawrasBukhariTranslationScanner;

use Illuminate\Support\ServiceProvider;
use NawrasBukhariTranslationScanner\Command\TranslationHelperCommand;

class TranslationScannerServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                TranslationHelperCommand::class,
            ]);
        }

        $this->mergeConfigFrom(__DIR__.'/config/translation-scanner.php', 'translation-scanner');

        $this->publishes([
            __DIR__.'/config/translation-scanner.php' => base_path('config/translation-scanner.php'),
        ], 'config');
    }
}
