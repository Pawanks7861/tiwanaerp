<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Line of a document that uses LocksWhenNotEditable: it can only be created, changed or deleted
 * while the parent is editable. Models define PARENT_MODEL and PARENT_KEY; POSTING_COLUMNS (if
 * defined) may still be written by the owning service after the parent is locked.
 */
trait LockedByParent
{
    public static function bootLockedByParent(): void
    {
        static::saving(function (self $line) {
            $parent = $line->lockingParent();
            $postingOnly = $line->exists && array_diff(array_keys($line->getDirty()), $line->postingColumns()) === [];

            if (! $parent->isEditable() && ! $postingOnly) {
                throw $parent->lockedException();
            }
        });

        static::deleting(fn (self $line) => $line->lockingParent()->assertEditable());
    }

    /**
     * @return list<string>
     */
    protected function postingColumns(): array
    {
        return defined(static::class.'::POSTING_COLUMNS') ? static::POSTING_COLUMNS : [];
    }

    protected function lockingParent(): Model
    {
        /** @var class-string<Model> $class */
        $class = static::PARENT_MODEL;

        return $class::query()->withoutGlobalScopes()->findOrFail($this->getAttribute(static::PARENT_KEY));
    }
}
