<?php

declare(strict_types=1);

namespace Levgenij\Translatable;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;

class TranslatableScope implements Scope
{
    protected string $table;

    protected string $i18nTable;

    protected string $locale;

    protected string $fallback;

    protected string $joinType = 'leftJoin';

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(EloquentBuilder $builder, Eloquent $model): void
    {
        $this->table = $model->getTable();
        $this->locale = $model->getLocale();
        $this->i18nTable = $model->getI18nTable();
        $this->fallback = $model->getFallbackLocale();

        if (! Str::startsWith($this->table, 'laravel_reserved_')) {
            $this->createJoin($builder, $model);
            $this->createWhere($builder, $model);
            $this->createSelect($builder, $model);
        }
    }

    /**
     * Create the join clause.
     */
    protected function createJoin(EloquentBuilder $builder, Eloquent $model): void
    {
        $joinType = $this->getJoinType($model);
        $clause = $this->getJoinClause($model, $this->locale, $this->i18nTable);

        $builder->$joinType($this->i18nTable, $clause);

        if ($model->shouldFallback()) {
            $clause = $this->getJoinClause($model, $this->fallback, $this->i18nTable.'_fallback');

            $builder->$joinType("{$this->i18nTable} as {$this->i18nTable}_fallback", $clause);
        }
    }

    /**
     * Return the join type.
     */
    protected function getJoinType(Eloquent $model): string
    {
        $innerJoin = ! $model->shouldFallback() && $model->getOnlyTranslated();

        return $innerJoin ? 'join' : 'leftJoin';
    }

    /**
     * Return the join clause.
     */
    protected function getJoinClause(Eloquent $model, string $locale, string $alias): callable
    {
        return function (JoinClause $join) use ($model, $locale, $alias) {
            $primary = $model->getKeyName();
            $foreign = $model->getForeignKey();
            $langKey = $model->getLocaleKey();

            $join->on($alias.'.'.$foreign, '=', $this->table.'.'.$primary)
                ->where($alias.'.'.$langKey, '=', $locale);
        };
    }

    /**
     * Create the where clause.
     */
    protected function createWhere(EloquentBuilder $builder, Eloquent $model): void
    {
        if ($model->getOnlyTranslated() && $model->shouldFallback()) {
            $key = $model->getForeignKey();
            $primary = "{$this->i18nTable}.{$key}";
            $fallback = "{$this->i18nTable}_fallback.{$key}";
            $ifNull = $builder->getQuery()->compileIfNull($primary, $fallback);

            $builder->whereRaw("$ifNull is not null");
        }
    }

    /**
     * Create the select clause.
     */
    protected function createSelect(EloquentBuilder $builder, Eloquent $model): void
    {
        $select = $this->formatColumns($builder, $model);

        if (empty($select)) {
            return;
        }

        if (! $builder->getQuery()->columns) {
            // No columns set yet - add all columns including translations
            $builder->select(array_merge([$this->table.'.*'], $select));
        } else {
            // Columns already set (e.g., by withCount) - add translations
            // Laravel will handle duplicates gracefully, so we always add them
            $builder->addSelect($select);
        }
    }

    /**
     * Format the columns.
     */
    protected function formatColumns(EloquentBuilder $builder, Eloquent $model): array
    {
        $map = function (string $field) use ($builder, $model): string|Expression {
            if (! $model->shouldFallback()) {
                return "{$this->i18nTable}.{$field}";
            }

            $primary = "{$this->i18nTable}.{$field}";
            $fallback = "{$this->i18nTable}_fallback.{$field}";
            $alias = $field;

            return new Expression($builder->getQuery()->compileIfNull($primary, $fallback, $alias));
        };

        return array_map($map, $model->translatableAttributes());
    }

    /**
     * Return string based on null type.
     */
    protected function getIfNull(Grammar $grammar): string
    {
        return $grammar instanceof SqlServerGrammar ? 'isnull' : 'ifnull';
    }

    /**
     * Extend the builder.
     */
    public function extend(EloquentBuilder $builder): void
    {
        $builder->macro('onlyTranslated', function (EloquentBuilder $builder, ?string $locale = null) {
            $builder->getModel()->setOnlyTranslated(true);

            if ($locale) {
                $builder->getModel()->setLocale($locale);
            }

            return $builder;
        });

        $builder->macro('withUntranslated', function (EloquentBuilder $builder) {
            $builder->getModel()->setOnlyTranslated(false);

            return $builder;
        });

        $builder->macro('withFallback', function (EloquentBuilder $builder, ?string $fallbackLocale = null) {
            $builder->getModel()->setWithFallback(true);

            if ($fallbackLocale) {
                $builder->getModel()->setFallbackLocale($fallbackLocale);
            }

            return $builder;
        });

        $builder->macro('withoutFallback', function (EloquentBuilder $builder) {
            $builder->getModel()->setWithFallback(false);

            return $builder;
        });

        $builder->macro('translateInto', function (EloquentBuilder $builder, ?string $locale) {
            if ($locale) {
                $builder->getModel()->setLocale($locale);
            }

            return $builder;
        });

        $builder->macro('withoutTranslations', function (EloquentBuilder $builder) {
            $builder->withoutGlobalScope(static::class);

            return $builder;
        });

        $builder->macro('withAllTranslations', function (EloquentBuilder $builder) {
            $builder->withoutGlobalScope(static::class)->with('translations');

            return $builder;
        });
    }
}
