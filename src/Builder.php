<?php

declare(strict_types=1);

namespace Levgenij\LaravelTranslatable;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

class Builder extends EloquentBuilder
{
    /**
     * Update a record in the database.
     */
    public function update(array $values): int
    {
        $updated = 0;
        $modelKey = $this->getModel()->getKey();
        $modelKeyName = $this->model->getKeyName();
        $values = $this->addUpdatedAtColumn($values);
        [$values, $i18nValues] = $this->filterValues($values);
        $ids = $modelKey ? [$modelKey] : $this->pluck($modelKeyName)->all();

        if ($values) {
            $updated += $this->updateBase($values, $ids);
        }

        if ($i18nValues) {
            $updated += $this->updateI18n($i18nValues, $ids);
        }

        return $updated;
    }

    /**
     * Increment a column's value by a given amount.
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        $extra = $this->addUpdatedAtColumn($extra);

        return $this->noTranslationsQuery()->increment($column, $amount, $extra);
    }

    /**
     * Decrement a column's value by a given amount.
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $extra = $this->addUpdatedAtColumn($extra);

        return $this->noTranslationsQuery()->decrement($column, $amount, $extra);
    }

    /**
     * Insert a new record into the database.
     */
    public function insert(array $values): bool
    {
        [$values, $i18nValues] = $this->filterValues($values);

        if ($this->query->insert($values)) {
            return $this->insertI18n($i18nValues, $values[$this->model->getKeyName()]);
        }

        return false;
    }

    /**
     * Insert a new record and get the value of the primary key.
     */
    public function insertGetId(array $values, $sequence = null): int|false
    {
        [$values, $i18nValues] = $this->filterValues($values);

        if ($id = $this->query->insertGetId($values, $sequence)) {
            if ($this->insertI18n($i18nValues, $id)) {
                return $id;
            }
        }

        return false;
    }

    /**
     * Delete a record from the database.
     */
    public function delete(): mixed
    {
        if (isset($this->onDelete)) {
            return call_user_func($this->onDelete, $this);
        }

        return $this->i18nDeleteQuery()->delete() | $this->toBase()->delete();
    }

    /**
     * Run the default delete function on the builder.
     */
    public function forceDelete(): mixed
    {
        return $this->i18nDeleteQuery(false)->delete() & $this->query->delete();
    }

    /**
     * Filters translatable values from non-translatable.
     */
    protected function filterValues(array $values): array
    {
        $attributes = $this->model->translatableAttributes();
        $translatable = [];

        foreach ($attributes as $key) {
            if (array_key_exists($key, $values)) {
                $translatable[$key] = $values[$key];

                unset($values[$key]);
            }
        }

        return [$values, $translatable];
    }

    /**
     * Insert translation.
     */
    protected function insertI18n(array $values, mixed $key): bool
    {
        if (count($values) === 0) {
            return true;
        }

        $values[$this->model->getForeignKey()] = $key;
        $values[$this->model->getLocaleKey()] = $this->model->getLocale();

        return $this->i18nQuery()->insert($values);
    }

    /**
     * Update values in base table.
     */
    private function updateBase(array $values, array $ids): int
    {
        $query = $this->model
            ->newQuery()
            ->whereIn($this->model->getKeyName(), $ids)
            ->getQuery();

        return $query->update($values);
    }

    /**
     * Update translations.
     */
    protected function updateI18n(array $values, array $ids): int
    {
        if (count($values) === 0) {
            return 0;
        }

        $updated = 0;

        foreach ($ids as $id) {
            $query = $this->i18nQuery()
                ->whereOriginal($this->model->getForeignKey(), $id)
                ->whereOriginal($this->model->getLocaleKey(), $this->model->getLocale());

            if ($query->exists()) {
                unset($values[$this->model->getLocaleKey()]);

                $updated += $query->update($values);
            } else {
                $updated += $this->insertI18n($values, $id) ? 1 : 0;
            }
        }

        return $updated;
    }

    /**
     * Get the query builder instance for translation table.
     */
    public function i18nQuery(): \Illuminate\Database\Query\Builder
    {
        $query = $this->getModel()->newQueryWithoutScopes()->getQuery();

        $query->from($this->model->getI18nTable());

        return $query;
    }

    /**
     * Get the delete query instance for translation table.
     */
    protected function i18nDeleteQuery(bool $withGlobalScopes = true): \Illuminate\Database\Query\Builder
    {
        $subQuery = $withGlobalScopes ? $this->toBase() : $this->getQuery();

        $subQuery->select($this->model->getQualifiedKeyName());

        return $this->i18nQuery()->whereIn(
            $this->model->getForeignKey(),
            $subQuery->pluck($this->model->getKeyName())
        );
    }

    /**
     * Get the base query without translations.
     */
    protected function noTranslationsQuery(): \Illuminate\Database\Query\Builder
    {
        return $this->withoutGlobalScope(TranslatableScope::class)->toBase();
    }
}
