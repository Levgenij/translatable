<?php

declare(strict_types=1);

namespace Levgenij\LaravelTranslatable;

use Illuminate\Support\ServiceProvider;

class TranslatableServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        TranslatableConfig::cacheGetter(function (string $table): ?array {
            return $this->app['cache']->get('translatable.'.$table);
        });

        TranslatableConfig::cacheSetter(function (string $table, array $fields): bool {
            return $this->app['cache']->forever('translatable.'.$table, $fields);
        });

        TranslatableConfig::currentLocaleGetter(function (): string {
            return $this->app->getLocale();
        });

        TranslatableConfig::fallbackLocaleGetter(function (): string {
            return method_exists($this->app, 'getFallbackLocale')
                ? $this->app->getFallbackLocale()
                : config('app.fallback_locale');
        });
    }

    /**
     * Boot the service provider.
     */
    public function boot(): void
    {
        $config = dirname(__DIR__).'/config/translatable.php';

        $this->mergeConfigFrom($config, 'translatable');
        $this->publishes([$config => config_path('translatable.php')], 'config');

        TranslatableConfig::setDbSettings(
            $this->app['config']->get('translatable.db_settings')
        );

        TranslatableConfig::setDefaults(
            $this->app['config']->get('translatable.defaults')
        );
    }
}
