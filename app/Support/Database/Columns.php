<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

/**
 * Shared column conventions for migrations (architecture section H.1).
 */
final class Columns
{
    public static function company(Blueprint $table): void
    {
        $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
    }

    /**
     * created_by / updated_by (and deleted_by for soft-deletable tables). Users are deactivated,
     * never deleted, but nullOnDelete keeps the FK safe if an account is ever purged.
     */
    public static function blame(Blueprint $table, bool $withDeletedBy = true): void
    {
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

        if ($withDeletedBy) {
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        }
    }

    public static function money(Blueprint $table, string $name): ColumnDefinition
    {
        return $table->decimal($name, 18, 2);
    }

    public static function rate(Blueprint $table, string $name): ColumnDefinition
    {
        return $table->decimal($name, 18, 4);
    }

    public static function quantity(Blueprint $table, string $name): ColumnDefinition
    {
        return $table->decimal($name, 18, 4);
    }

    public static function percent(Blueprint $table, string $name): ColumnDefinition
    {
        return $table->decimal($name, 7, 4);
    }

    public static function geo(Blueprint $table): void
    {
        $table->decimal('latitude', 10, 7)->nullable();
        $table->decimal('longitude', 10, 7)->nullable();
    }
}
