<?php

declare(strict_types=1);

namespace Levgenij\LaravelTranslatable;

use Illuminate\Database\Eloquent\Relations\HasMany;

trait Translatable
{
    protected ?string $overrideLocale = null;

    protected ?string $overrideFallbackLocale = null;

    protected ?bool $overrideOnlyTranslated = null;

    protected ?bool $overrideWithFallback = null;

    protected bool $localeChanged = false;

    /**
     * Translated attributes cache.
     */
    protected static array $i18nAttributes = [];

    /**
     * Boot the trait.
     */
    public static function bootTranslatable(): void
    {
        static::addGlobalScope(new TranslatableScope);
    }

    /**
     * Save a new model and return the instance.
     */
    public static function create(array $attributes = [], array|string $translations = []): static
    {
        $model = new static($attributes);

        if ($model->save() && is_array($translations)) {
            $model->saveTranslations($translations);
        }

        return $model;
    }

    /**
     * Save a new model in provided locale and return the instance.
     */
    public static function createInLocale(string $locale, array $attributes = [], array|string $translations = []): static
    {
        $model = (new static($attributes))->setLocale($locale);

        if ($model->save() && is_array($translations)) {
            $model->saveTranslations($translations);
        }

        return $model;
    }

    /**
     * Save a new model and return the instance. Allow mass-assignment.
     */
    public static function forceCreate(array $attributes, array|string $translations = []): static
    {
        $model = new static;

        return static::unguarded(function () use ($model, $attributes, $translations) {
            return $model->create($attributes, $translations);
        });
    }

    /**
     * Save a new model in provided locale and return the instance. Allow mass-assignment.
     */
    public static function forceCreateInLocale(string $locale, array $attributes, array|string $translations = []): static
    {
        $model = new static;

        return static::unguarded(function () use ($locale, $model, $attributes, $translations) {
            return $model->createInLocale($locale, $attributes, $translations);
        });
    }

    /**
     * Reload a fresh model instance from the database.
     */
    public function fresh($with = []): ?static
    {
        if (! $this->exists) {
            return null;
        }

        $query = static::newQueryWithoutScopes()
            ->with(is_string($with) ? func_get_args() : $with)
            ->where($this->getKeyName(), $this->getKey());

        (new TranslatableScope)->apply($query, $this);

        return $query->first();
    }

    /**
     * Save the translations.
     */
    public function saveTranslations(array $translations): bool
    {
        $success = true;
        $fresh = parent::fresh();

        foreach ($translations as $locale => $attributes) {
            $model = clone $fresh;

            $model->setLocale($locale);
            $model->fill($attributes);

            $success = $success && $model->save();
        }

        return $success;
    }

    /**
     * Force saving the translations.
     */
    public function forceSaveTranslations(array $translations): bool
    {
        return static::unguarded(function () use ($translations) {
            return $this->saveTranslations($translations);
        });
    }

    /**
     * Save the translation.
     */
    public function saveTranslation(string $locale, array $attributes): bool
    {
        return $this->saveTranslations([
            $locale => $attributes,
        ]);
    }

    /**
     * Force saving the translation.
     */
    public function forceSaveTranslation(string $locale, array $attributes): bool
    {
        return static::unguarded(function () use ($locale, $attributes) {
            return $this->saveTranslation($locale, $attributes);
        });
    }

    /**
     * Populate the translations.
     */
    public function fill(array $attributes): static
    {
        if (! isset(static::$i18nAttributes[$this->getTable()])) {
            $this->initTranslatableAttributes();
        }

        return parent::fill($attributes);
    }

    /**
     * Initialize translatable attributes.
     */
    protected function initTranslatableAttributes(): void
    {
        if (property_exists($this, 'translatable')) {
            $attributes = $this->translatable;
        } else {
            $attributes = $this->getTranslatableAttributesFromSchema();
        }

        static::$i18nAttributes[$this->getTable()] = $attributes;
    }

    /**
     * Return an array of translatable attributes from schema.
     */
    protected function getTranslatableAttributesFromSchema(): array
    {
        $con = $this->getConnection();
        $builder = $con?->getSchemaBuilder();

        if (! $con || ! $builder) {
            return [];
        }

        if ($columns = TranslatableConfig::cacheGet($this->getI18nTable())) {
            return $columns;
        }

        $columns = $builder->getColumnListing($this->getI18nTable());

        unset($columns[array_search($this->getForeignKey(), $columns)]);

        TranslatableConfig::cacheSet($this->getI18nTable(), $columns);

        return $columns;
    }

    /**
     * Return a collection of translated attributes in a given locale.
     */
    public function translate(string $locale): TranslationModel|static|null
    {
        if (app()->getLocale() === $locale) {
            $found = $this;
        } else {
            $found = $this->translations->where($this->getLocaleKey(), $locale)->first();
        }

        if (! $found && $this->shouldFallback($locale)) {
            return $this->translate($this->getFallbackLocale());
        }

        return $found;
    }

    /**
     * Get translated attribute value for a specific locale (Spatie-style JSON columns).
     *
     * @deprecated This method exists only for compatibility with Spatie Translatable.
     *             Use $model->translate($locale)?->$attribute or $model->$attribute instead.
     *
     * @param  string  $key  The translatable attribute name
     * @param  string  $locale  The locale to get translation for
     * @param  bool  $useFallbackLocale  Whether to use fallback locale if translation not found
     * @return string|null The translated value or null
     */
    public function getTranslation(string $key, string $locale, bool $useFallbackLocale = true): ?string
    {
        $value = $this->getAttributeValue($key);

        if (! is_array($value)) {
            return $value;
        }

        if (array_key_exists($locale, $value)) {
            return $value[$locale];
        }

        if ($useFallbackLocale) {
            return $value[$this->getFallbackLocale()] ?? null;
        }

        return null;
    }

    /**
     * Return a collection of translated attributes in a given locale or create a new one.
     */
    public function translateOrNew(string $locale): TranslationModel|static
    {
        if (($instance = $this->translate($locale)) === null) {
            return $this->newModelInstance();
        }

        return $instance;
    }

    /**
     * Translations relationship.
     */
    public function translations(): HasMany
    {
        $localKey = $this->getKeyName();
        $foreignKey = $this->getForeignKey();
        $instance = $this->translationModel();

        return new HasMany($instance->newQuery(), $this, $instance->getTable().'.'.$foreignKey, $localKey);
    }

    /**
     * Returns the default translation model instance.
     */
    public function translationModel(): TranslationModel
    {
        $translation = new TranslationModel;

        $translation->setConnection($this->getI18nConnection());
        $translation->setTable($this->getI18nTable());
        $translation->setKeyName($this->getForeignKey());
        $translation->setLocaleKey($this->getLocaleKey());

        if ($attributes = $this->translatableAttributes()) {
            $translation->fillable(array_intersect($attributes, $this->getFillable()));
        }

        return $translation;
    }

    /**
     * Return an array of translatable attributes.
     */
    public function translatableAttributes(): array
    {
        if (! isset(static::$i18nAttributes[$this->getTable()])) {
            return [];
        }

        return static::$i18nAttributes[$this->getTable()];
    }

    /**
     * Return the name of the locale key.
     */
    public function getLocaleKey(): string
    {
        return TranslatableConfig::dbKey();
    }

    /**
     * Set the current locale.
     */
    public function setLocale(string $locale): static
    {
        $this->overrideLocale = $locale;
        $this->localeChanged = true;

        return $this;
    }

    /**
     * Return the current locale.
     */
    public function getLocale(): string
    {
        if ($this->overrideLocale) {
            return $this->overrideLocale;
        }

        if (property_exists($this, 'locale')) {
            return $this->locale;
        }

        return TranslatableConfig::currentLocale();
    }

    /**
     * Set the fallback locale.
     */
    public function setFallbackLocale(string $locale): static
    {
        $this->overrideFallbackLocale = $locale;

        return $this;
    }

    /**
     * Return the fallback locale.
     */
    public function getFallbackLocale(): string
    {
        if ($this->overrideFallbackLocale) {
            return $this->overrideFallbackLocale;
        }

        if (property_exists($this, 'fallbackLocale')) {
            return $this->fallbackLocale;
        }

        return TranslatableConfig::fallbackLocale();
    }

    /**
     * Set if model should select only translated rows.
     */
    public function setOnlyTranslated(bool $onlyTranslated): static
    {
        $this->overrideOnlyTranslated = $onlyTranslated;

        return $this;
    }

    /**
     * Return only translated rows.
     */
    public function getOnlyTranslated(): bool
    {
        if ($this->overrideOnlyTranslated !== null) {
            return $this->overrideOnlyTranslated;
        }

        if (property_exists($this, 'onlyTranslated')) {
            return $this->onlyTranslated;
        }

        return TranslatableConfig::onlyTranslated();
    }

    /**
     * Set if model should select only translated rows.
     */
    public function setWithFallback(bool $withFallback): static
    {
        $this->overrideWithFallback = $withFallback;

        return $this;
    }

    /**
     * Return current locale with fallback.
     */
    public function getWithFallback(): bool
    {
        if ($this->overrideWithFallback !== null) {
            return $this->overrideWithFallback;
        }

        if (property_exists($this, 'withFallback')) {
            return $this->withFallback;
        }

        return TranslatableConfig::withFallback();
    }

    /**
     * Return the i18n connection name associated with the model.
     */
    public function getI18nConnection(): ?string
    {
        return $this->getConnectionName();
    }

    /**
     * Return the i18n table associated with the model.
     */
    public function getI18nTable(): string
    {
        return $this->getTable().$this->getTranslationTableSuffix();
    }

    /**
     * Return the i18n table suffix.
     */
    public function getTranslationTableSuffix(): string
    {
        return TranslatableConfig::dbSuffix();
    }

    /**
     * Should fall back to a primary translation.
     */
    public function shouldFallback(?string $locale = null): bool
    {
        if (! $this->getWithFallback() || ! $this->getFallbackLocale()) {
            return false;
        }

        $locale = $locale ?: $this->getLocale();

        return $locale !== $this->getFallbackLocale();
    }

    /**
     * Create a new Eloquent query builder for the model.
     */
    public function newEloquentBuilder($query): Builder
    {
        return new Builder($query);
    }

    /**
     * Return a new query builder instance for the connection.
     */
    protected function newBaseQueryBuilder(): QueryBuilder
    {
        $conn = $this->getConnection();
        $grammar = $conn->getQueryGrammar();
        $builder = new QueryBuilder($conn, $grammar, $conn->getPostProcessor());

        return $builder->setModel($this);
    }

    /**
     * Return the attributes that have been changed since the last sync.
     */
    public function getDirty(): array
    {
        $dirty = parent::getDirty();

        if (! $this->localeChanged) {
            return $dirty;
        }

        foreach ($this->translatableAttributes() as $key) {
            if (isset($this->attributes[$key])) {
                $dirty[$key] = $this->attributes[$key];
            }
        }

        return $dirty;
    }

    /**
     * Sync the original attributes with the current.
     */
    public function syncOriginal(): static
    {
        $this->localeChanged = false;

        return parent::syncOriginal();
    }

    /**
     * Prefix column names with the translation table instead of the model table if the given column is translated.
     */
    public function qualifyColumn($column): string
    {
        if (in_array($column, $this->translatableAttributes(), true)) {
            return sprintf('%s.%s', $this->getI18nTable(), $column);
        }

        return parent::qualifyColumn($column);
    }
}
