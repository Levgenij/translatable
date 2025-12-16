<?php

declare(strict_types=1);

namespace Levgenij\Translatable;

use Exception;

class TranslatableConfig
{
    /**
     * @var array<string, mixed>
     */
    protected static array $config = [
        'locale' => [
            'current_getter' => null,
            'fallback_getter' => null,
        ],
        'cache' => [
            'getter' => null,
            'setter' => null,
        ],
        'db_settings' => [
            'table_suffix' => '_i18n',
            'locale_field' => 'locale',
        ],
        'defaults' => [
            'only_translated' => false,
            'with_fallback' => true,
        ],
    ];

    public static function currentLocaleGetter(callable $current): void
    {
        static::$config['locale']['current_getter'] = $current;
    }

    public static function fallbackLocaleGetter(callable $fallback): void
    {
        static::$config['locale']['fallback_getter'] = $fallback;
    }

    public static function cacheGetter(callable $getter): void
    {
        static::$config['cache']['getter'] = $getter;
    }

    public static function cacheSetter(callable $setter): void
    {
        static::$config['cache']['setter'] = $setter;
    }

    public static function setDbSettings(array $settings): void
    {
        static::$config['db_settings'] = array_merge(static::$config['db_settings'], $settings);
    }

    public static function setDefaults(array $defaults): void
    {
        static::$config['defaults'] = array_merge(static::$config['defaults'], $defaults);
    }

    public static function currentLocale(): string
    {
        static::checkIfSet('locale', 'current_getter');

        return call_user_func(static::$config['locale']['current_getter']);
    }

    public static function fallbackLocale(): string
    {
        static::checkIfSet('locale', 'fallback_getter');

        return call_user_func(static::$config['locale']['fallback_getter']);
    }

    public static function onlyTranslated(): bool
    {
        return static::$config['defaults']['only_translated'];
    }

    public static function withFallback(): bool
    {
        return static::$config['defaults']['with_fallback'];
    }

    public static function dbSuffix(): string
    {
        return static::$config['db_settings']['table_suffix'];
    }

    public static function dbKey(): string
    {
        return static::$config['db_settings']['locale_field'];
    }

    public static function cacheSet(string $table, array $fields): mixed
    {
        static::checkIfSet('cache', 'setter');

        return call_user_func_array(static::$config['cache']['setter'], [$table, $fields]);
    }

    public static function cacheGet(string $table): ?array
    {
        static::checkIfSet('cache', 'getter');

        return call_user_func_array(static::$config['cache']['getter'], [$table]);
    }

    /**
     * @throws Exception
     */
    protected static function checkIfSet(string $key1, string $key2): void
    {
        if (empty(static::$config[$key1][$key2])) {
            throw new Exception("Translatable is not configured correctly. Config for [$key1.$key2] is missing.");
        }
    }
}
