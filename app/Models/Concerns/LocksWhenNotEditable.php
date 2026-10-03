<?php

namespace App\Models\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Document header that can only be changed while its (original) status is editable. Lifecycle
 * columns (status, approval stamps, caches) may still change through the owning service.
 * Models define LIFECYCLE_COLUMNS, lockedMessage() and a status cast whose enum has isEditable().
 */
trait LocksWhenNotEditable
{
    public static function bootLocksWhenNotEditable(): void
    {
        static::updating(function (self $document) {
            if ($document->wasEditable()) {
                return;
            }

            $changed = array_diff(array_keys($document->getDirty()), static::LIFECYCLE_COLUMNS);
            if ($changed !== []) {
                throw $document->lockedException();
            }
        });

        static::deleting(function (self $document) {
            if (! $document->wasEditable()) {
                throw $document->lockedException();
            }
        });
    }

    abstract public function lockedMessage(): string;

    public function lockedException(): ValidationException
    {
        return ValidationException::withMessages([$this->lockKey() => $this->lockedMessage()]);
    }

    public function lockKey(): string
    {
        return 'document';
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function assertEditable(): void
    {
        if (! $this->isEditable()) {
            throw $this->lockedException();
        }
    }

    /** Editability as stored, so a forged status change cannot unlock the record in the same save. */
    protected function wasEditable(): bool
    {
        $original = $this->getOriginal('status');

        return $original === null || $original->isEditable();
    }
}
