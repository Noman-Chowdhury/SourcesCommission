<?php

namespace Sources\AffiliateCommission;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;

class CommissionServiceProvider extends ServiceProvider

{
    public function boot(Filesystem $filesystem)
    {
        if (function_exists('config_path')) { // function not available and 'publish' not relevant in Lumen
            $this->publishes([
                __DIR__.'/../config/commission.php' => config_path('commission.php'),
            ], 'config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_referrers_table.php.stub' => $this->getMigrationFileName($filesystem),
            ], 'migrations');
        }
    }
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/commission.php',
            'commission'
        );
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getMigrationFileName(Filesystem $filesystem): string
    {
        $timestamp = date('Y_m_d_His');

        return Collection::make($this->app->databasePath().DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR)
            ->flatMap(function ($path) use ($filesystem) {
                return $filesystem->glob($path.'*_create_referrers_tables.php');
            })->push($this->app->databasePath()."/migrations/{$timestamp}_create_referrers_tables.php")
            ->first();
    }
}
