<?php

declare(strict_types=1);

namespace Levgenij\LaravelTranslatable;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model as Eloquent;

class TranslationModel extends Eloquent
{
    /**
     * Translation model does not include timestamps by default.
     */
    public $timestamps = false;

    /**
     * Name of the table (will be set dynamically).
     */
    protected $table = null;

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * Locale key name.
     */
    protected string $localeKey = 'locale';

    /**
     * Set the keys for a save update query.
     */
    protected function setKeysForSaveQuery($query): EloquentBuilder
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());
        $query->where($this->localeKey, '=', $this->{$this->localeKey});

        return $query;
    }

    /**
     * Set the locale key.
     */
    public function setLocaleKey(string $localeKey): static
    {
        $this->localeKey = $localeKey;

        return $this;
    }
}
