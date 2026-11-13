<?php

namespace App\Support\Transitions;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Thin infrastructure helper for document status changes (architecture Phase 3 / R8).
 *
 * Domain services remain owners of business rules, authorization, and side effects.
 * ApprovalService remains the multi-level approval engine. This helper only:
 * - reloads a row with FOR UPDATE (caller must already be inside a DB transaction),
 * - asserts the current status is one of the allowed sources,
 * - applies a target status (+ optional attributes) via the model's normal save path.
 *
 * Do not put module-specific transition graphs or ledger postings here.
 */
final class DocumentTransition
{
    /**
     * Reload and lock the document primary key. Must run inside an active DB transaction
     * or the lock is not held for subsequent statements.
     *
     * @template T of Model
     *
     * @param  T  $document
     * @return T
     */
    public static function lock(Model $document): Model
    {
        if ($document->getKey() === null) {
            throw new LogicException('DocumentTransition::lock requires a persisted model.');
        }

        /** @var T $locked */
        $locked = $document->newQuery()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    /**
     * @param  BackedEnum|list<BackedEnum>  $allowed
     */
    public static function assertStatus(
        Model $document,
        BackedEnum|array $allowed,
        string $errorKey,
        ?string $message = null,
    ): void {
        $allowed = is_array($allowed) ? array_values($allowed) : [$allowed];
        if ($allowed === []) {
            throw new LogicException('DocumentTransition::assertStatus requires at least one allowed status.');
        }

        $current = $document->getAttribute('status');
        foreach ($allowed as $status) {
            if ($current === $status) {
                return;
            }
        }

        throw ValidationException::withMessages([
            $errorKey => $message ?? self::defaultMessage($current, $allowed),
        ]);
    }

    /**
     * Persist the target status (and optional attributes) through Eloquent save / casts / events.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function apply(Model $document, BackedEnum $to, array $attributes = []): void
    {
        $document->forceFill(['status' => $to, ...$attributes])->save();
    }

    /**
     * @param  list<BackedEnum>  $allowed
     */
    private static function defaultMessage(mixed $current, array $allowed): string
    {
        $from = $current instanceof BackedEnum
            ? (method_exists($current, 'label') ? $current->label() : (string) $current->value)
            : (string) $current;

        $targets = array_map(
            fn (BackedEnum $status) => method_exists($status, 'label') ? $status->label() : (string) $status->value,
            $allowed,
        );

        return "This document cannot move from {$from}; allowed: ".implode(', ', $targets).'.';
    }
}
